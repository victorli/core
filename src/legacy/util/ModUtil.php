<?php
/**
 * Copyright Zikula Foundation 2009 - Zikula Application Framework
 *
 * This work is contributed to the Zikula Foundation under one or more
 * Contributor Agreements and licensed to You under the following license:
 *
 * @license GNU/LGPLv3 (or at your option, any later version).
 * @package Util
 *
 * Please see the NOTICE file distributed with this source code for further
 * information regarding copyright and licensing.
 */

use Zikula\Core\Event\GenericEvent;

/**
 * Module Util.
 */
class ModUtil
{
    // States
    const STATE_UNINITIALISED = 1;
    const STATE_INACTIVE = 2;
    const STATE_ACTIVE = 3;
    const STATE_MISSING = 4;
    const STATE_UPGRADED = 5;
    const STATE_NOTALLOWED = 6;
    const STATE_INVALID = -1;

    const CONFIG_MODULE = 'ZConfig';

    // Types
    const TYPE_MODULE = 2;
    const TYPE_SYSTEM = 3;
    const TYPE_CORE = 4;

    // Module dependency states
    const DEPENDENCY_REQUIRED = 1;
    const DEPENDENCY_RECOMMENDED = 2;
    const DEPENDENCY_CONFLICTS = 3;

    /**
     * Memory of object oriented modules.
     *
     * @var array
     */
    protected static $ooModules = array();
    /**
     * Module info cache.
     *
     * @var array
     */
    protected static $modinfo;
    /**
     * Module vars.
     *
     * @var ArrayObject
     */
    protected static $modvars = array();

    /**
     * Internal module cache.
     *
     * @var array
     */
    protected static $cache = array();

    /**
     * Module variables getter.
     *
     * @return ArrayObject
     */
    public static function getModvars()
    {
        return self::$modvars;
    }

    /**
     * Flush this static class' cache.
     *
     * @return void
     */
    public static function flushCache()
    {
        self::$cache = array();
    }

    /**
     * The initCoreVars preloads some module vars.
     *
     * Preloads module vars for a number of key modules to reduce sql statements.
     *
     * @return void
     */
    public static function initCoreVars($force=false)
    {
        // The empty arrays for handlers and settings are required to prevent messages with E_ALL error reporting
        self::$modvars = new ArrayObject(array(
                EventUtil::HANDLERS => array(),
                ServiceUtil::HANDLERS => array(),
                'Settings'          => array(),
        ));

        // don't init vars during the installer or upgrader
        if (!$force && System::isInstalling()) {
            return;
        }

        // This loads all module variables into the modvars static class variable.
        $em = ServiceUtil::get('doctrine')->getEntityManager();
        $modvars = $em->getRepository('Zikula\Core\Doctrine\Entity\ExtensionVar')->findAll();
        foreach ($modvars as $var) {
            if (!array_key_exists($var['modname'], self::$modvars)) {
                self::$modvars[$var['modname']] = array();
            }
            if (array_key_exists($var['name'], $GLOBALS['ZConfig']['System'])) {
                self::$modvars[$var['modname']][$var['name']] = $GLOBALS['ZConfig']['System'][$var['name']];
            } else {
                self::$modvars[$var['modname']][$var['name']] = $var['value'];
            }
         }

         // Pre-load the module variables array with empty arrays for known modules that
         // do not define any module variables to prevent unnecessary SQL queries to
         // the module_vars table.
         $knownModules = self::getAllMods();
         foreach ($knownModules as $key => $mod) {
             if (!array_key_exists($mod['name'], self::$modvars)) {
                 self::$modvars[$mod['name']] = array();
             }
         }
    }

    /**
     * Checks to see if a module variable is set.
     *
     * @param string $modname The name of the module.
     * @param string $name    The name of the variable.
     *
     * @return boolean True if the variable exists in the database, false if not.
     */
    public static function hasVar($modname, $name)
    {
        // define input, all numbers and booleans to strings
        if ('ZConfig' !== $modname) {
            $modname = preg_match('/\w+Module$/', $modname) || !$modname ? $modname : $modname.'Module';
        }
        $modname = isset($modname) ? ((string)$modname) : '';
        $name = isset($name) ? ((string)$name) : '';

        // make sure we have the necessary parameters
        if (!System::varValidate($modname, 'mod') || !System::varValidate($name, 'modvar')) {
            return false;
        }

        // The cast to (array) is for the odd instance where self::$modvars[$modname] is set to null--not sure if this is really needed.
        $varExists = isset(self::$modvars[$modname]) && array_key_exists($name, (array)self::$modvars[$modname]);

        if (!$varExists && System::isUpgrading()) {
            // Handle the upgrade edge case--the call to getVar() ensures vars for the module are loaded if newly available.
            $modvars = self::getVar($modname);
            $varExists = array_key_exists($name, (array)$modvars);
        }

        return $varExists;
    }

    /**
     * The getVar method gets a module variable.
     *
     * If the name parameter is included then method returns the
     * module variable value.
     * if the name parameter is ommitted then method returns a multi
     * dimentional array of the keys and values for the module vars.
     *
     * @param string  $modname The name of the module or pseudo-module (e.g., 'Users', 'ZConfig', '/EventHandlers').
     * @param string  $name    The name of the variable.
     * @param boolean $default The value to return if the requested modvar is not set.
     *
     * @return  string|array If the name parameter is included then method returns
     *          string - module variable value
     *          if the name parameter is ommitted then method returns
     *          array - multi dimentional array of the keys
     *                  and values for the module vars.
     */
    public static function getVar($modname, $name = '', $default = false)
    {
        // if we don't know the modname then lets assume it is the current
        // active module
        if (!isset($modname)) {
            $modname = self::getName();
        }

        if ('ZConfig' !== $modname) {
            $modname = preg_match('/\w+Module$/', $modname) || !$modname ? $modname : $modname.'Module';
        }

        // if we haven't got vars for this module (or pseudo-module) yet then lets get them
        if (!array_key_exists($modname, self::$modvars)) {
            // A query out to the database should only be needed if the system is upgrading. Use the installing flag to determine this.
            // Prevent a re-query for the same module in the future, where the module does not define any module variables.
            self::$modvars[$modname] = array();
        }

        // if they didn't pass a variable name then return every variable
        // for the specified module as an associative array.
        // array('var1' => value1, 'var2' => value2)
        if (empty($name) && array_key_exists($modname, self::$modvars)) {
            return self::$modvars[$modname];
        }

        // since they passed a variable name then only return the value for
        // that variable
        if (isset(self::$modvars[$modname]) && array_key_exists($name, self::$modvars[$modname])) {
            return self::$modvars[$modname][$name];
        }

        // we don't know the required module var but we established all known
        // module vars for this module so the requested one can't exist.
        // we return the default (which itself defaults to false)
        return $default;
    }

    /**
     * The setVar method sets a module variable.
     *
     * @param string $modname The name of the module.
     * @param string $name    The name of the variable.
     * @param string $value   The value of the variable.
     *
     * @return boolean True if successful, false otherwise.
     */
    public static function setVar($modname, $name, $value = '')
    {
        // define input, all numbers and booleans to strings
        if ('ZConfig' !== $modname) {
            $modname = preg_match('/\w+Module$/', $modname) || !$modname ? $modname : $modname.'Module';
        }
        $modname = isset($modname) ? ((string)$modname) : '';

        // validate
        if (!System::varValidate($modname, 'mod') || !isset($name)) {
            return false;
        }

        $em = ServiceUtil::get('doctrine')->getEntityManager();
        if (self::hasVar($modname, $name)) {
            $entity = $em->getRepository('Zikula\Core\Doctrine\Entity\ExtensionVar')->findOneBy(array('modname' => $modname, 'name' => $name));
            $entity->setValue($value);
        } else {
            $entity = new \Zikula\Core\Doctrine\Entity\ExtensionVar();
            $entity->setModname($modname);
            $entity->setName($name);
            $entity->setValue($value);
            $em->persist($entity);
        }

        self::$modvars[$modname][$name] = $value;

        $em->flush();

        return true;
    }

    /**
     * The setVars method sets multiple module variables.
     *
     * @param string $modname The name of the module.
     * @param array  $vars    An associative array of varnames/varvalues.
     *
     * @return boolean True if successful, false otherwise.
     */
    public static function setVars($modname, array $vars)
    {
        $ok = true;
        foreach ($vars as $var => $value) {
            $ok = $ok && self::setVar($modname, $var, $value);
        }
        return $ok;
    }

    /**
     * The delVar method deletes a module variable.
     *
     * Delete a module variables. If the optional name parameter is not supplied all variables
     * for the module 'modname' are deleted.
     *
     * @param string $modname The name of the module.
     * @param string $name    The name of the variable (optional).
     *
     * @return boolean True if successful, false otherwise.
     */
    public static function delVar($modname, $name = '')
    {
        // define input, all numbers and booleans to strings
        if ('ZConfig' !== $modname) {
            $modname = preg_match('/\w+Module$/', $modname) || !$modname ? $modname : $modname.'Module';
        }
        $modname = isset($modname) ? ((string)$modname) : '';

        // validate
        if (!System::varValidate($modname, 'modvar')) {
            return false;
        }

        $val = null;
        if (!isset(self::$modvars[$modname])) {
            return $val;
        }
        if (empty($name)) {
            if (array_key_exists($modname, self::$modvars)) {
                unset(self::$modvars[$modname]);
            }
        } else {
            if (array_key_exists($name, self::$modvars[$modname])) {
                $val = self::$modvars[$modname][$name];

                // we're dealing with an ArrayObject, so we cannot unset() deep keys.
                $array = self::$modvars[$modname];
                unset($array[$name]);
                self::$modvars[$modname] = $array;
            }
        }

        $em = ServiceUtil::get('doctrine')->getEntityManager();

        // if $name is not provided, delete all variables of this module
        // else just delete this specific variable
        if (empty($name)) {
            $dql = "DELETE FROM Zikula\Core\Doctrine\Entity\ExtensionVar v WHERE v.modname = '{$modname}'";
        } else {
            $dql = "DELETE FROM Zikula\Core\Doctrine\Entity\ExtensionVar v WHERE v.modname = '{$modname}' AND v.name = '{$name}'";
        }

        $query = $em->createQuery($dql);
        $result = $query->getResult();

        return (boolean)$result;
    }

    /**
     * Get Module meta info.
     *
     * @param string $module Module name.
     *
     * @return array|boolean Module information array or false.
     */
    public static function getInfoFromName($module)
    {
        return self::getInfo(self::getIdFromName($module));
    }

    /**
     * The getIdFromName method gets module ID given its name.
     *
     * @param string $module The name of the module.
     *
     * @return integer module ID.
     */
    public static function getIdFromName($module)
    {
        // define input, all numbers and booleans to strings
        $alias = (isset($module) ? strtolower((string)$module) : '');
        $module = preg_match('/\w+Module$/i', $module) || !$module ? $module : $module.'Module';
        $module = (isset($module) ? strtolower((string)$module) : '');

        // validate
        if (!System::varValidate($module, 'mod')) {
            return false;
        }

        if (!isset(self::$cache['modid'])) {
            self::$cache['modid'] = null;
        }

        if (!is_array(self::$cache['modid']) || System::isInstalling()) {
            $modules = self::getModsTable();

            if ($modules === false) {
                return false;
            }

            foreach ($modules as $id => $mod) {
                $mName = strtolower($mod['name']);
                self::$cache['modid'][$mName] = $mod['id'];
                if (!$id == 0) {
                    $mdName = strtolower($mod['url']);
                    self::$cache['modid'][$mdName] = $mod['id'];
                }
            }

            if (!isset(self::$cache['modid'][$module]) && !isset(self::$cache['modid'][$alias])) {
                self::$cache['modid'][$module] = false;
                return false;
            }
        }

        if (isset(self::$cache['modid'][$module])) {
            return self::$cache['modid'][$module];
        }

        if (isset(self::$cache['modid'][$alias])) {
            return self::$cache['modid'][$alias];
        }

        return false;
    }

    /**
     * The getInfo method gets information on module.
     *
     * Return array of module information or false if core ( id = 0 ).
     *
     * @param integer $modid The module ID.
     *
     * @return array|boolean Module information array or false.
     */
    public static function getInfo($modid = 0)
    {
        // a $modid of 0 is associated with the core ( blocks.mid, ... ).
        if (!is_numeric($modid)) {
            return false;
        }

        if (!is_array(self::$modinfo) || System::isInstalling()) {
            self::$modinfo = self::getModsTable();

            if (!self::$modinfo) {
                return null;
            }

            if (!isset(self::$modinfo[$modid])) {
                self::$modinfo[$modid] = false;
                return self::$modinfo[$modid];
            }
        }

        if (isset(self::$modinfo[$modid])) {
            return self::$modinfo[$modid];
        }

        return false;
    }

    /**
     * The getModulesCapableOf method gets a list of modules by module type.
     *
     * @param string $capability The module type to get (either 'user' or 'admin') (optional) (default='user').
     *
     * @return array An array of module information arrays.
     */
    public static function getModulesCapableOf($capability = 'user')
    {
        if (!isset(self::$cache['modcache'])) {
            self::$cache['modcache'] = array();
        }

        if (!isset(self::$cache['modcache'][$capability]) || !self::$cache['modcache'][$capability]) {
            self::$cache['modcache'][$capability] = array();
            $mods = self::getAllMods();
            foreach ($mods as $key => $mod) {
                if (isset($mod['capabilities'][$capability])) {
                    self::$cache['modcache'][$capability][] = $mods[$key];
                }
            }
        }

        return self::$cache['modcache'][$capability];
    }

    /**
     * Indicates whether the specified module has the specified capability.
     *
     * @param string $module     The name of the module.
     * @param string $capability The name of the advertised capability.
     *
     * @return boolean True if the specified module advertises that it has the specified capability, otherwise false.
     */
    public static function isCapable($module, $capability)
    {
        $modinfo = self::getInfoFromName($module);
        if (!$modinfo) {
            return false;
        }

        return (bool)array_key_exists($capability, $modinfo['capabilities']);
    }

    /**
     * Retrieves the capabilities of the specified module.
     *
     * @param string $module The module name.
     *
     * @return array|boolean The capabilities array, false if the module does not advertise any capabilities.
     */
    public static function getCapabilitiesOf($module)
    {
        $modules = self::getAllMods();
        if (array_key_exists($module, $modules)) {
            return $modules[$module]['capabilities'];
        }

        return false;
    }

    /**
     * The getAllMods method gets a list of all modules.
     *
     * @return array An array of module information arrays.
     */
    public static function getAllMods()
    {
        if (!isset(self::$cache['modsarray'])) {
            self::$cache['modsarray'] = array();
        }

        if (empty(self::$cache['modsarray'])) {
            $all = self::getModsTable();
            foreach ($all as $mod) {
                // "Core" modules should be returned in this list
                if (($mod['state'] == self::STATE_ACTIVE)
                    || (preg_match('/^(extensionsmodule|adminmodule|thememodule|blockmodule|groupsmodule|permissionsmodule|usersmodule)$/i', $mod['name'])
                        && ($mod['state'] == self::STATE_UPGRADED || $mod['state'] == self::STATE_INACTIVE))) {
                    self::$cache['modsarray'][$mod['name']] = $mod;
                }
            }
        }

        return self::$cache['modsarray'];
    }

    /**
     * Loads database definition for a module.
     *
     * @param string  $modname   The name of the module to load database definition for.
     * @param string  $directory Directory that module is in (if known).
     * @param boolean $force     Force table information to be reloaded.
     *
     * @return boolean True if successful, false otherwise.
     */
    public static function dbInfoLoad($modname, $directory = '', $force = false)
    {
        // define input, all numbers and booleans to strings
        $modname = preg_match('/\w+Module$/i', $modname) || !$modname ? $modname : $modname.'Module';
        $modname = (isset($modname) ? strtolower((string)$modname) : '');

        // validate
        if (!System::varValidate($modname, 'mod')) {
            return false;
        }

        $container = ServiceUtil::getManager();

        if (!isset($container['modutil.dbinfoload.loaded'])) {
            $container['modutil.dbinfoload.loaded'] = array();
        }

        $loaded = $container['modutil.dbinfoload.loaded'];

        // check to ensure we aren't doing this twice
        if (isset($loaded[$modname]) && !$force) {
            return $loaded[$modname];
        }

        // from here the module dbinfo will be loaded no doubt
        $loaded[$modname] = true;
        $container['modutil.dbinfoload.loaded'] = $loaded;

        // get the directory if we don't already have it
        if (empty($directory)) {
            // get the module info
            $modinfo = self::getInfo(self::getIdFromName($modname));
            $directory = $modinfo['directory'];

            $modpath = ($modinfo['type'] == self::TYPE_SYSTEM) ? 'system' : 'modules';
        } else {
            $modpath = is_dir(ZIKULA_ROOT."/system/$directory") ? 'system' : 'modules';
        }

        // Load the database definition if required
        $file = ZIKULA_ROOT."/$modpath/$directory/tables.php";

        if (file_exists($file) && include $file) {
            // If not gets here, the module has no tables to load
            $tablefunc = $modname . '_tables';
            $data = array();
            if (function_exists($tablefunc)) {
                $data = call_user_func($tablefunc);
            }

            // Generate _column automatically from _column_def if it is not present.
            foreach ($data as $key => $value) {
                $table_col = substr($key, 0, -4);
                if (substr($key, -11) == "_column_def" && !isset($data[$table_col])) {
                    foreach ($value as $fieldname => $def) {
                        $data[$table_col][$fieldname] = $fieldname;
                    }
                }
            }

            if (!isset($container['dbtables'])) {
                $container['dbtables'] = array();
            }

            $dbtables = $container['dbtables'];
            $container['dbtables'] = array_merge($dbtables, (array)$data);
        } else {
            // the module is tableless (Doctrine or doesn't use tables at all)
            return true;
        }

        // update the loaded status
        $container['modutil.dbinfoload.loaded'] = $loaded;

        return isset($data) ? $data : $loaded[$modname];
    }

    /**
     * Loads a module.
     *
     * @param string  $modname The name of the module.
     * @param string  $type    The type of functions to load.
     * @param boolean $force   Determines to load Module even if module isn't active.
     *
     * @return string|boolean Name of module loaded, or false on failure.
     */
    public static function load($modname, $type = 'user', $force = false)
    {
        if (strtolower(substr($type, -3)) == 'api') {
            return false;
        }
        return self::loadGeneric($modname, $type, $force);
    }

    /**
     * Load an API module.
     *
     * @param string  $modname The name of the module.
     * @param string  $type    The type of functions to load.
     * @param boolean $force   Determines to load Module even if module isn't active.
     *
     * @return string|boolean Name of module loaded, or false on failure.
     */
    public static function loadApi($modname, $type = 'user', $force = false)
    {
        return self::loadGeneric($modname, $type, $force, true);
    }

    /**
     * Load a module.
     *
     * This loads/set's up a module.  For classic style modules, it tests to see
     * if the module type files exist, admin.php, user.php etc and includes them.
     * If they do not exist, it will return false.
     *
     * Loading a module simply means making the functions/methods available
     * by loading the files and other tasks like binding any language domain.
     *
     * For OO style modules this means registering the main module autoloader,
     * and binding any language domain.
     *
     * @param string  $modname The name of the module.
     * @param string  $type    The type of functions to load.
     * @param boolean $force   Determines to load Module even if module isn't active.
     * @param boolean $api     Whether or not to load an API (or regular) module.
     *
     * @return string|boolean Name of module loaded, or false on failure.
     */
    public static function loadGeneric($modname, $type = 'user', $force = false, $api = false)
    {
        // define input, all numbers and booleans to strings
        $osapi = ($api ? 'api' : '');
        $modname = preg_match('/\w+Module$/i', $modname) || !$modname ? $modname : $modname.'Module';
        $modname = isset($modname) ? ((string)$modname) : '';
        $modtype = strtolower("$modname{$type}{$osapi}");

        if (!isset(self::$cache['loaded'])) {
            self::$cache['loaded'] = array();
        }

        if (!empty(self::$cache['loaded'][$modtype])) {
            // Already loaded from somewhere else
            return self::$cache['loaded'][$modtype];
        }

        // this is essential to call separately and not in the condition below - drak
        $available = self::available($modname, $force);
        // check the modules state
        if (!$force && !$available) {
            return false;
        }

        // get the module info
        $modinfo = self::getInfo(self::getIdFromName($modname));
        // check for bad System::varValidate($modname)
        if (!$modinfo) {
            return false;
        }

        // if class is loadable or has been loaded exit here.
        if (self::isInitialized($modname)) {
            self::_loadStyleSheets($modname, $api, $type);
            return $modname;
        }

        self::isOO($modname);
        self::initOOModule($modname);

        self::$cache['loaded'][$modtype] = $modname;

        if ($modinfo['type'] == self::TYPE_MODULE) {
            ZLanguage::bindModuleDomain($modname);
        }

        // Load database info
        self::dbInfoLoad($modname, $modinfo['directory']);

        self::_loadStyleSheets($modname, $api, $type);

        $event = new GenericEvent(null, array('modinfo' => $modinfo, 'type' => $type, 'force' => $force, 'api' => $api));
        EventUtil::dispatch('module_dispatch.postloadgeneric', $event);

        return $modname;
    }

    /**
     * Initialise all modules.
     *
     * @return void
     */
    public static function loadAll()
    {
        $modules = self::getModsTable();
        unset($modules[0]);
        foreach ($modules as $module) {
            if (self::available($module['name'])) {
                self::loadGeneric($module['name']);
            }
        }
    }

    /**
     * Add stylesheet to the page vars.
     *
     * This makes the modulestylesheet plugin obsolete,
     * but only for non-api loads as we would pollute the stylesheets
     * not during installation as the Theme engine may not be available yet and not for system themes
     * TODO: figure out how to determine if a userapi belongs to a hook module and load the
     *       corresponding css, perhaps with a new entry in modules table?
     *
     * @param string  $modname Module name.
     * @param boolean $api     Whether or not it's a api load.
     * @param string  $type    Type.
     *
     * @return void
     */
    private static function _loadStyleSheets($modname, $api, $type)
    {
        if (!System::isInstalling() && !$api) {
            PageUtil::addVar('stylesheet', ThemeUtil::getModuleStylesheet($modname));
            if (strpos($type, 'admin') === 0) {
                // load special admin stylesheets for administrator controllers
                PageUtil::addVar('stylesheet', ThemeUtil::getModuleStylesheet('Admin'));
            }
        }
    }

    /**
     * Get module class.
     *
     * @param string  $modname Module name.
     * @param string  $type    Type.
     * @param boolean $api     Whether or not to get the api class.
     * @param boolean $force   Whether or not to force load.
     *
     * @return boolean|string Class name.
     */
    public static function getClass($modname, $type, $api = false, $force = false)
    {
        // do not cache this process - drak
        if ($api) {
            $result = self::loadApi($modname, $type);
        } else {
            $result = self::load($modname, $type);
        }

        if (!$result) {
            return false;
        }

        $modinfo = self::getInfo(self::getIDFromName($modname));

        $className = ($api) ? ucwords($modname) . '\\Api\\' . ucwords($type) . 'Api' : ucwords($modname) .
            '\\Controller\\' . ucwords($type) . 'Controller';

        // allow overriding the OO class (to override existing methods using inheritance).
        $event = new GenericEvent(null, array('modname', 'modinfo' => $modinfo, 'type' => $type, 'api' => $api), $className);
        EventUtil::dispatch('module_dispatch.custom_classname', $event);
        if ($event->isPropagationStopped()) {
            $className = $event->getData();
        }

        // check the modules state
        if (!$force && !self::available($modname)) {
            return false;
        }

        if (class_exists($className)) {
            return $className;
        }

        return false;
    }

    /**
     * Checks if module has the given controller.
     *
     * @param string $modname Module name.
     * @param string $type    Controller type.
     *
     * @return boolean
     */
    public static function hasController($modname, $type)
    {
        return (bool)self::getClass($modname, $type);
    }

    /**
     * Checks if module has the given API class.
     *
     * @param string $modname Module name.
     * @param string $type    API type.
     *
     * @return boolean
     */
    public static function hasApi($modname, $type)
    {
        return (bool)self::getClass($modname, $type, true);
    }

    /**
     * Get class object.
     *
     * @param string $className Class name.
     *
     * @throws LogicException If $className is neither a Zikula_AbstractApi nor a Zikula_AbstractController.
     * @return object Module object.
     */
    public static function getObject($className)
    {
        if (!$className) {
            return false;
        }

        $serviceId = str_replace('\\', '_', strtolower("module.$className"));
        $sm = ServiceUtil::getManager();

        if ($sm->has($serviceId)) {
            $object = $sm->get($serviceId);
        } else {
            $r = new ReflectionClass($className);
            $object = $r->newInstanceArgs(array($sm));
            $sm->set($serviceId, $object);
        }

        return $object;
    }

    /**
     * Get info if callable.
     *
     * @param string  $modname Module name.
     * @param string  $type    Type.
     * @param string  $func    Function.
     * @param boolean $api     Whether or not this is an api call.
     * @param boolean $force   Whether or not force load.
     *
     * @return mixed
     */
    public static function getCallable($modname, $type, $func, $api = false, $force = false)
    {
        $className = self::getClass($modname, $type, $api, $force);
        if (!$className) {
            return false;
        }

        $object = self::getObject($className);
        $func = $api ? $func : $func.'Action';
        if (is_callable(array($object, $func))) {
            $className = str_replace('\\', '_', $className);
            return array('serviceid' => strtolower("module.$className"), 'classname' => $className, 'callable' => array($object, $func));
        }

        return false;
    }

    /**
     * Run a module function.
     *
     * @param string  $modname    The name of the module.
     * @param string  $type       The type of function to run.
     * @param string  $func       The specific function to run.
     * @param array   $args       The arguments to pass to the function.
     * @param boolean $api        Whether or not to execute an API (or regular) function.
     *
     * @throws Zikula_Exception_NotFound If method was not found.
     *
     * @return mixed.
     */
    public static function exec($modname, $type = 'user', $func = 'index', $args = array(), $api = false)
    {
        // define input, all numbers and booleans to strings
        $modname = preg_match('/\w+Module$/i', $modname) || !$modname ? $modname : $modname.'Module';
        $modname = isset($modname) ? ((string)$modname) : '';
        $loadfunc = ($api ? 'ModUtil::loadApi' : 'ModUtil::load');

        // validate
        if (!System::varValidate($modname, 'mod')) {
            return null;
        }

        $modinfo = self::getInfo(self::getIDFromName($modname));

        $controller = null;
        $modfunc = null;
        $loaded = call_user_func_array($loadfunc, array($modname, $type));
        $result = self::getCallable($modname, $type, $func, $api);

        if ($result) {
            $modfunc = $result['callable'];
            $controller = $modfunc[0];
        }

        $dispatcher = EventUtil::getManager();
        if ($loaded) {
            $preExecuteEvent = new GenericEvent($controller, array('modname' => $modname, 'modfunc' => $modfunc, 'args' => $args, 'modinfo' => $modinfo, 'type' => $type, 'api' => $api));
            $postExecuteEvent = new GenericEvent($controller, array('modname' => $modname, 'modfunc' => $modfunc, 'args' => $args, 'modinfo' => $modinfo, 'type' => $type, 'api' => $api));

            if (is_callable($modfunc)) {
                $dispatcher->dispatch('module_dispatch.preexecute', $preExecuteEvent);

                $modfunc[0]->preDispatch();
                $postExecuteEvent->setData(call_user_func($modfunc, $args));
                $modfunc[0]->postDispatch();

                return $dispatcher->dispatch('module_dispatch.postexecute', $postExecuteEvent)->getData();
            }

            // try to load plugin
            // This kind of eventhandler should
            // 1. Check $event['modfunc'] to see if it should run else exit silently.
            // 2. Do something like $result = {$event['modfunc']}({$event['args'});
            // 3. Save the result $event->setData($result).
            // 4. $event->setNotify().
            // return void
            // This event means that no $type was found
            $event = new GenericEvent(null, array('modfunc' => $modfunc, 'args' => $args, 'modinfo' => $modinfo, 'type' => $type, 'api' => $api), false);
            $dispatcher->dispatch('module_dispatch.type_not_found', $event);

            if ($preExecuteEvent->isPropagationStopped()) {
                return $preExecuteEvent->getData();
            }

            return false;
        }

        // Issue not found exception for controller requests
        if (!$api) {
            throw new \Zikula\Framework\Exception\NotFoundException(__f('The requested controller action %s_Controller_%s::%s() could not be found', array($modname, $type, $func)));
        }
    }

    /**
     * Run a module function.
     *
     * @param string $modname    The name of the module.
     * @param string $type       The type of function to run.
     * @param string $func       The specific function to run.
     * @param array  $args       The arguments to pass to the function.
     *
     * @return mixed.
     */
    public static function func($modname, $type = 'user', $func = 'index', $args = array())
    {
        return self::exec($modname, $type, $func, $args, false);
    }

    /**
     * Run an module API function.
     *
     * @param string $modname    The name of the module.
     * @param string $type       The type of function to run.
     * @param string $func       The specific function to run.
     * @param array  $args       The arguments to pass to the function.
     *
     * @return mixed.
     */
    public static function apiFunc($modname, $type = 'user', $func = 'index', $args = array())
    {
        if (empty($type)) {
            $type = 'user';
        } elseif (!System::varValidate($type, 'api')) {
            return null;
        }

        if (empty($func)) {
            $func = 'index';
        }

        return self::exec($modname, $type, $func, $args, true);
    }

    /**
     * Generate a module function URL.
     *
     * If the module is non-API compliant (type 1) then
     * a) $func is ignored.
     * b) $type=admin will generate admin.php?module=... and $type=user will generate index.php?name=...
     *
     * @param string         $modname      The name of the module.
     * @param string         $type         The type of function to run.
     * @param string         $func         The specific function to run.
     * @param array          $args         The array of arguments to put on the URL.
     * @param boolean|null   $ssl          Set to constant null,true,false $ssl = true not $ssl = 'true'  null - leave the current status untouched,
     *                                     true - create a ssl url, false - create a non-ssl url.
     * @param string         $fragment     The framgment to target within the URL.
     * @param boolean|null   $fqurl        Fully Qualified URL. True to get full URL, eg for Redirect, else gets root-relative path unless SSL.
     * @param boolean        $forcelongurl Force ModUtil::url to not create a short url even if the system is configured to do so.
     * @param boolean|string $forcelang    Force the inclusion of the $forcelang or default system language in the generated url.
     *
     * @return string Absolute URL for call.
     */
    public static function url($modname, $type = null, $func = null, $args = array(), $ssl = null, $fragment = null, $fqurl = null, $forcelongurl = false, $forcelang=false)
    {
        // define input, all numbers and booleans to strings
        $modname = preg_match('/\w+Module$/i', $modname) || !$modname ? $modname : $modname.'Module';
        $modname = isset($modname) ? ((string)$modname) : '';

        // note - when this legacy is to be removed, change method signature $type = null to $type making it a required argument.
        if (!$type) {
            throw new UnexpectedValueException('ModUtil::url() - $type is a required argument, you must specify it explicitly.');
        }

        // note - when this legacy is to be removed, change method signature $func = null to $func making it a required argument.
        if (!$func) {
             throw new UnexpectedValueException('ModUtil::url() - $func is a required argument, you must specify it explicitly.');
        }

        // validate
        if (!System::varValidate($modname, 'mod')) {
            return null;
        }

        //get the module info
        $modinfo = self::getInfo(self::getIDFromName($modname));

        // set the module name to the display name if this is present
        if (isset($modinfo['url']) && !empty($modinfo['url'])) {
            $modname = rawurlencode($modinfo['url']);
        }
        $entrypoint = System::getVar('entrypoint');

        $request = ServiceUtil::getManager()->get('request');
        /* @var \Symfony\Component\HttpFoundation\Request $request */
        $basePath = $request->getBasePath();

        $host = System::serverGetVar('HTTP_HOST');

        if (empty($host)) {
            return false;
        }

        $baseuri = System::getBaseUri();
        $https = System::serverGetVar('HTTPS');
        $shorturls = System::getVar('shorturls');
        $shorturlsstripentrypoint = System::getVar('shorturlsstripentrypoint');
        $shorturlsdefaultmodule = System::getVar('shorturlsdefaultmodule');

        // Don't encode URLs with escaped characters, like return urls.
        foreach ($args as $v) {
            if (!is_array($v)) {
                if (strpos($v, '%') !== false) {
                    $shorturls = false;
                    break;
                }
            } else {
                foreach ($v as $vv) {
                    if (is_array($vv)) {
                        foreach ($vv as $vvv) {
                            if (!is_array($vvv) && strpos($vvv, '%') !== false) {
                                $shorturls = false;
                                break;
                            }
                        }
                    } elseif (strpos($vv, '%') !== false) {
                        $shorturls = false;
                        break;
                    }
                }
                break;
            }
        }

        // Setup the language code to use
        if (is_array($args) && isset($args['lang'])) {
            if (in_array($args['lang'], ZLanguage::getInstalledLanguages())) {
                $language = $args['lang'];
            }
            unset($args['lang']);
        }
        if (!isset($language)) {
            $language = ZLanguage::getLanguageCode();
        }

        $language = ($forcelang && in_array($forcelang, ZLanguage::getInstalledLanguages()) ? $forcelang : $language);

        // Only produce full URL when HTTPS is on or $ssl is set
        $siteRoot = '';
        if ((isset($https) && $https == 'on') || $ssl != null || $fqurl == true) {
            $protocol = 'http' . (($https == 'on' && $ssl !== false) || $ssl === true ? 's' : '');
            $secureDomain = System::getVar('secure_domain');
            $siteRoot = $protocol . '://' . (($secureDomain != '') ? $secureDomain : ($host . $baseuri)) . '/';
        }

        // Only convert type=user. Exclude links that append a theme parameter
        if ($shorturls && $type == 'user' && $forcelongurl == false) {
            if (isset($args['theme'])) {
                $theme = $args['theme'];
                unset($args['theme']);
            }
            // Module-specific Short URLs
            $url = self::apiFunc($modinfo['name'], 'user', 'encodeurl', array('modname' => $modname, 'type' => $type, 'func' => $func, 'args' => $args));
            if (empty($url)) {
                // depending on the settings, we have generic directory based short URLs:
                // [language]/[module]/[function]/[param1]/[value1]/[param2]/[value2]
                // [module]/[function]/[param1]/[value1]/[param2]/[value2]
                $vars = '';
                foreach ($args as $k => $v) {
                    if (is_array($v)) {
                        foreach ($v as $k2 => $w) {
                            if (is_numeric($w) || !empty($w)) {
                                // we suppress '', but allow 0 as value (see #193)
                                $vars .= '/' . $k . '[' . $k2 . ']/' . $w; // &$k[$k2]=$w
                            }
                        }
                    } elseif (is_numeric($v) || !empty($v)) {
                        // we suppress '', but allow 0 as value (see #193)
                        $vars .= "/$k/$v"; // &$k=$v
                    }
                }
                $url = $modname . ($vars || $func != 'index' ? "/$func$vars" : '');
            }

            if ($modinfo && $shorturlsdefaultmodule && $shorturlsdefaultmodule == $modinfo['name']) {
                $pattern = '/^'.preg_quote($modinfo['url'], '/').'\//';
                $url = preg_replace($pattern, '', $url);
            }
            if (isset($theme)) {
                $url = rawurlencode($theme) . '/' . $url;
            }

            // add language param to short url
            if (ZLanguage::isRequiredLangParam() || $forcelang) {
                $url = "$language/" . $url;
            }
            if (!$shorturlsstripentrypoint) {
                $url = "$entrypoint/$url" . (!empty($query) ? '?' . $query : '');
            } else {
                $url = "$url" . (!empty($query) ? '?' . $query : '');
            }
        } else {
            // Regular stuff
//            $urlargs = "module=$modname&type=$type&func=$func";
            $urlargs = "/$modname/$type/$func?";

            // add lang param to URL
            if (ZLanguage::isRequiredLangParam() || $forcelang) {
//                $urlargs .= "&lang=$language";
                $urlargs .= "lang=$language";
            }

//            $url = "$entrypoint?$urlargs";
            $url = "{$basePath}$urlargs";

            if (!is_array($args)) {
                return false;
            } else {
                foreach ($args as $key => $value) {
                    if (is_array($value)) {
                        foreach ($value as $l => $w) {
                            if (is_numeric($w) || !empty($w)) {
                                // we suppress '', but allow 0 as value (see #193)
                                if (is_array($w)) {
                                    foreach ($w as $m => $n) {
                                        if (is_numeric($n) || !empty($n)) {
                                            $n    = strpos($n, '%') !== false ? $n : urlencode($n);
                                            $url .= "&$key" . "[$l][$m]=$n";
                                        }
                                    }
                                } else {
                                    $w    = strpos($w, '%') !== false ? $w : urlencode($w);
                                    $url .= "&$key" . "[$l]=$w";
                                }
                            }
                        }
                    } elseif (is_numeric($value) || !empty($value)) {
                        // we suppress '', but allow 0 as value (see #193)
                        $w    = strpos($value, '%') !== false ? $value : urlencode($value);
                        $url .= "&$key=$value";
                    }
                }
            }
        }

        if (isset($fragment)) {
            $url .= '#' . $fragment;
        }

        return $siteRoot . $url;
    }

    /**
     * Check if a module is available.
     *
     * @param string  $modname The name of the module.
     * @param boolean $force   Force.
     *
     * @return boolean True if the module is available, false if not.
     */
    public static function available($modname = null, $force = false)
    {
        // define input, all numbers and booleans to strings
        $modname = preg_match('/\w+Module$/i', $modname) || !$modname ? $modname : $modname.'Module';
        $modname = (isset($modname) ? strtolower((string)$modname) : '');

        // validate
        if (!System::varValidate($modname, 'mod')) {
            return false;
        }

        if (!isset(self::$cache['modstate'])) {
            self::$cache['modstate'] = array();
        }

        if (!isset(self::$cache['modstate'][$modname]) || $force == true) {
            $modinfo = self::getInfo(self::getIDFromName($modname));
            if (isset($modinfo['state'])) {
                self::$cache['modstate'][$modname] = $modinfo['state'];
            }
        }

        if ($force == true) {
            self::$cache['modstate'][$modname] = self::STATE_ACTIVE;
        }

        if ((isset(self::$cache['modstate'][$modname]) &&
                self::$cache['modstate'][$modname] == self::STATE_ACTIVE) || (preg_match('/^(extensionsmodule|adminmodule|thememodule|blockmodule|groupsmodule|permissionsmodule|usersmodule)$/i', $modname) &&
                (isset(self::$cache['modstate'][$modname]) && (self::$cache['modstate'][$modname] == self::STATE_UPGRADED || self::$cache['modstate'][$modname] == self::STATE_INACTIVE)))) {
            self::$cache['modstate'][$modname] = self::STATE_ACTIVE;
            return true;
        }

        return false;
    }

    /**
     * Get name of current top-level module.
     *
     * @return string The name of the current top-level module, false if not in a module.
     */
    public static function getName()
    {
        if (!isset(self::$cache['modgetname'])) {
            self::$cache['modgetname'] = FormUtil::getPassedValue('module', null, 'GETPOST', FILTER_SANITIZE_STRING);

            if (empty(self::$cache['modgetname'])) {
                if (!System::getVar('startpage')) {
                    self::$cache['modgetname'] = System::getVar('startpage');
                } else {
                    $baseUriLenght = strlen(System::getBaseUri());
                    $shortUrlPath = substr(System::getCurrentUri(),$baseUriLenght+1);
                    if (!empty($shortUrlPath) == 0) {
                        self::$cache['modgetname'] = System::getVar('startpage');
                    } else {
                        $args = explode('/', $shortUrlPath);
                        self::$cache['modgetname'] = $args[0];
                    }
                }
            }

            // the parameters may provide the module alias so lets get
            // the real name from the db
            $modinfo = self::getInfo(self::getIdFromName(self::$cache['modgetname']));
            if (isset($modinfo['name'])) {
                $type = FormUtil::getPassedValue('type', null, 'GETPOST', FILTER_SANITIZE_STRING);

                self::$cache['modgetname'] = $modinfo['name'];

                if ((!$type == 'init' || !$type == 'initeractiveinstaller') && !self::available(self::$cache['modgetname'])) {
                    self::$cache['modgetname'] = System::getVar('startpage');
                }
            }
        }

        return self::$cache['modgetname'];
    }

    /**
     * Get the base directory for a module.
     *
     * Example: If the webroot is located at
     * /var/www/html
     * and the module name is Template and is found
     * in the modules directory then this function
     * would return /var/www/html/modules/Template
     *
     * If the Template module was located in the system
     * directory then this function would return
     * /var/www/html/system/Template
     *
     * This allows you to say:
     * include(ModUtil::getBaseDir() . '/includes/private_functions.php');.
     *
     * @param string $modname Name of module to that you want the base directory of.
     *
     * @return string The path from the root directory to the specified module.
     */
    public static function getBaseDir($modname = '')
    {
        if (empty($modname)) {
            $modname = self::getName();
        }

        $path = System::getBaseUri();
        $directory = ZIKULA_ROOT.'/system/' . $modname;
        if ($path != '') {
            $path .= '/';
        }

        $url = $path . $directory;
        if (!is_dir($url)) {
            $directory = ZIKULA_ROOT.'/modules/' . $modname;
            $url = $path . $directory;
        }

        return $url;
    }

    /**
     * Gets the modules table.
     *
     * Small wrapper function to avoid duplicate sql.
     *
     * @return array An array modules table.
     */
    public static function getModsTable()
    {
        if (!isset(self::$cache['modstable'])) {
            self::$cache['modstable'] = array();
        }

        if (!self::$cache['modstable'] || System::isInstalling()) {
            // get entityManager
            $sm = ServiceUtil::getManager();
            $entityManager = $sm->get('doctrine')->getEntityManager();

            // get all modules
            $modules = $entityManager->getRepository('Zikula\Core\Doctrine\Entity\Extension')->findAll();

            foreach ($modules as $module) {
                $module = $module->toArray();
                if (!isset($module['url']) || empty($module['url'])) {
                    $module['url'] = strtolower($module['displayname']);
                }
                self::$cache['modstable'][$module['id']] = $module;
            }

            // add Core module (hack).
            self::$cache['modstable'][0] = array(
                'id' => 0,
                'name' => 'zikula',
                'type' => self::TYPE_CORE,
                'directory' => '',
                'displayname' => 'Zikula Core v' . \Zikula\Core\Core::VERSION_NUM,
                'version' => \Zikula\Core\Core::VERSION_NUM,
                'state' => self::STATE_ACTIVE);
        }

        return self::$cache['modstable'];
    }

    /**
     * Generic modules select function.
     *
     * Only modules in the module table are returned
     * which means that new/unscanned modules will not be returned.
     *
     * @param string $where The where clause to use for the select.
     * @param string $sort  The sort to use.
     *
     * @return array The resulting module object array.
     */
    public static function getModules($where=array(), $sort='displayname')
    {
        // get entityManager
        $sm = ServiceUtil::getManager();
        $entityManager = $sm->get('doctrine')->getEntityManager();

        // get all modules
        $modules = $entityManager->getRepository('Zikula\Core\Doctrine\Entity\Extension')->findBy($where, array($sort => 'ASC'));
        return $modules;
    }

    /**
     * Return an array of modules in the specified state.
     *
     * Only modules in the module table are returned
     * which means that new/unscanned modules will not be returned.
     *
     * @param integer $state The module state (optional) (defaults = active state).
     * @param string  $sort  The sort to use.
     *
     * @return array The resulting module object array.
     */
    public static function getModulesByState($state=self::STATE_ACTIVE, $sort='displayname')
    {
        $sm = ServiceUtil::getManager();
        $entityManager = $sm->get('doctrine')->getEntityManager();
        $modules = $entityManager->getRepository('Zikula\Core\Doctrine\Entity\Extension')->findBy(array('state' => $state), array($sort => 'ASC'));
        return $modules;
    }

    /**
     * Initialize object oriented module.
     *
     * @param string $moduleName Module name.
     *
     * @return boolean
     */
    public static function initOOModule($moduleName)
    {
        if (self::isInitialized($moduleName)) {
            return true;
        }

        $modinfo = self::getInfo(self::getIdFromName($moduleName));
        if (!$modinfo) {
            return false;
        }

        $modpath = ($modinfo['type'] == self::TYPE_SYSTEM) ? 'system' : 'modules';
        $osdir   = DataUtil::formatForOS($modinfo['directory']);
        ZLoader::addModule($moduleName, realpath($modpath));

        // load optional bootstrap
        $bootstrap = ZIKULA_ROOT."/$modpath/$osdir/bootstrap.php";
        if (file_exists($bootstrap)) {
            include_once $bootstrap;
        }

        // register any event handlers.
        // module handlers must be attached from the bootstrap.
        if (is_dir(ZIKULA_CONFIG_PATH."/EventHandlers/$osdir")) {
            EventUtil::attachCustomHandlers(ZIKULA_CONFIG_PATH."EventHandlers/$osdir");
        }

        // load any plugins
        PluginUtil::loadPlugins(ZIKULA_ROOT."$modpath/$osdir/plugins", "ModulePlugin_{$osdir}");

        self::$ooModules[$moduleName]['initialized'] = true;
        return true;
    }

    /**
     * Checks whether a OO module is initialized.
     *
     * @param string $moduleName Module name.
     *
     * @return boolean
     */
    public static function isInitialized($moduleName)
    {
        return (self::isOO($moduleName) && self::$ooModules[$moduleName]['initialized']);
    }

    /**
     * Checks whether a module is object oriented.
     *
     * @param string $moduleName Module name.
     *
     * @return boolean
     */
    public static function isOO($moduleName)
    {
        if (!isset(self::$ooModules[$moduleName])) {
            self::$ooModules[$moduleName] = array();
            self::$ooModules[$moduleName]['initialized'] = false;
            self::$ooModules[$moduleName]['oo'] = false;
            $modinfo = self::getInfo(self::getIdFromName($moduleName));

            if (!$modinfo) {
                return false;
            }

            self::$ooModules[$moduleName]['oo'] = true;
        }

        return self::$ooModules[$moduleName]['oo'];
    }

    /**
     * Register all autoloaders for all modules.
     *
     * @internal
     *
     * @return void
     */
    public static function registerAutoloaders()
    {
        $modules = self::getModsTable();
        unset($modules[0]);
        foreach ($modules as $module) {
            $base = ($module['type'] == self::TYPE_MODULE) ? 'modules' : 'system';
            ZLoader::addModule($module['directory'], ZIKULA_ROOT."/$base");
        }
    }

    /**
     * Determine the module base directory (system or modules).
     *
     * The purpose of this API is to decouple this calculation from the database,
     * since we ship core with fixed system modules, there is no need to calculate
     * this from the database over and over.
     *
     * @param string $moduleName Module name.
     *
     * @return string Returns 'system' if system module, and 'modules' if not.
     */
    public static function getModuleBaseDir($moduleName)
    {
        $moduleName = preg_match('/\w+Module$/i', $moduleName) || !$moduleName ? $moduleName : $moduleName.'Module';
        if (in_array(strtolower($moduleName), array('adminmodule', 'blocksmodule', 'categoriesmodule',
            'errorsmodule', 'extensionsmodule', 'groupsmodule', 'mailermodule', 'pagelockmodule',
            'permissionsmodule', 'searchmodule', 'securitycentermodule', 'settingsmodule',
            'thememodule', 'usersmodule'))) {
            $directory = 'system';
        } else {
            $directory = 'modules';
        }

        return $directory;
    }

}
