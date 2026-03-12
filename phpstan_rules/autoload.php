<?php

declare(strict_types=1);

use Composer\Autoload\ClassLoader;

$autoloader = new ClassLoader();
$autoloader->addPsr4('Canvas\\PHPStan\\Rules\\', __DIR__ . '/Canvas/PHPStan/Rules');
// @see https://github.com/carlosas/phpat
$autoloader->addPsr4('Canvas\\PHPStan\\Architecture\\', __DIR__ . '/Canvas/PHPStan/Architecture');
$autoloader->register();
