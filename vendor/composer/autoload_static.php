<?php

// autoload_static.php @generated by Composer

namespace Composer\Autoload;

class ComposerStaticInit78f0dc1954b8c5aabbec9395a56a86dd
{
    public static $prefixLengthsPsr4 = array (
        'C' => 
        array (
            'Cube\\' => 5,
        ),
    );

    public static $prefixDirsPsr4 = array (
        'Cube\\' => 
        array (
            0 => __DIR__ . '/../..' . '/src',
        ),
    );

    public static $classMap = array (
        'Composer\\InstalledVersions' => __DIR__ . '/..' . '/composer/InstalledVersions.php',
    );

    public static function getInitializer(ClassLoader $loader)
    {
        return \Closure::bind(function () use ($loader) {
            $loader->prefixLengthsPsr4 = ComposerStaticInit78f0dc1954b8c5aabbec9395a56a86dd::$prefixLengthsPsr4;
            $loader->prefixDirsPsr4 = ComposerStaticInit78f0dc1954b8c5aabbec9395a56a86dd::$prefixDirsPsr4;
            $loader->classMap = ComposerStaticInit78f0dc1954b8c5aabbec9395a56a86dd::$classMap;

        }, null, ClassLoader::class);
    }
}
