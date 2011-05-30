<?php
/**
 * Copyright 2010 Zikula Foundation
 *
 * This work is contributed to the Zikula Foundation under one or more
 * Contributor Agreements and licensed to You under the following license:
 *
 * @license GNU/LGPLv3 (or at your option, any later version).
 *
 * Please see the NOTICE file distributed with this source code for further
 * information regarding copyright and licensing.
 */

use Doctrine\ORM\Tools\SchemaTool as SchemaTool;

class DoctrineHelper
{
    public static function createSchema(array $classes)
    {
        $em = ServiceUtil::getService('doctrine.entitymanager');
        $tool = new SchemaTool($em);
        $metaClasses = array();
        foreach ($classes as $class) {
            $metaClasses[] = $em->getClassMetadata($class);
        }
        try {
            $tool->createSchema($metaClasses);
        } catch (PDOException $e) {
            throw $e;
        }
    }

    public static function dropSchema(array $classes)
    {
        $em = ServiceUtil::getService('doctrine.entitymanager');
        $tool = new SchemaTool($em);
        $metaClasses = array();
        foreach ($classes as $class) {
            $metaClasses[] = $em->getClassMetadata($class);
        }
        try {
            $tool->dropSchema($metaClasses);
        } catch (\PDOException $e) {
            throw $e;
        }
    }

    public static function updateSchema(array $classes)
    {
        $em = ServiceUtil::getService('doctrine.entitymanager');

        $tool = new SchemaTool($em);
        $metaClasses = array();
        foreach ($classes as $class) {
            $metaClasses[] = $em->getClassMetadata($class);
        }
        try {
            $tool->updateSchema($metaClasses);
        } catch (\PDOException $e) {
            throw $e;
        }
    }
}