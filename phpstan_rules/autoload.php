<?php

declare(strict_types=1);

use Composer\Autoload\ClassLoader;

$autoloader = new ClassLoader();
$autoloader->addPsr4('Canvas\\PHPStan\\Rules\\', __DIR__ . '/Canvas/PHPStan/Rules');
$autoloader->addPsr4('Canvas\\PHPStan\\UsageProvider\\', __DIR__ . '/Canvas/PHPStan/UsageProvider');
// @see https://github.com/carlosas/phpat
$autoloader->addPsr4('Canvas\\PHPStan\\Architecture\\', __DIR__ . '/Canvas/PHPStan/Architecture');
$autoloader->register();
