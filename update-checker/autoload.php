<?php

namespace KratosUpdateChecker;

require_once __DIR__ . '/libraries/Autoloader.php';
if (!defined('KRATOS_UC_AUTOLOADER_INIT')) {
    new Autoloader();
    define('KRATOS_UC_AUTOLOADER_INIT', true);
}

require_once __DIR__ . '/libraries/Factory.php';

if (!defined('KRATOS_UC_FACTORY_REGISTERED')) {
    foreach (
        array(
            'Plugin\\UpdateChecker' => Plugin\UpdateChecker::class,
            'Theme\\UpdateChecker'  => Theme\UpdateChecker::class,
        )
        as $GeneralClass => $VersionedClass
    ) {
        Factory::addVersion($GeneralClass, $VersionedClass, '5.6');
    }
    define('KRATOS_UC_FACTORY_REGISTERED', true);
}
