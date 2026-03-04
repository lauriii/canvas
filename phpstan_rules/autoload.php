<?php

declare(strict_types=1);

use Composer\Autoload\ClassLoader;

$autoloader = new ClassLoader();
$autoloader->addPsr4('Canvas\\PHPStan\\Rules\\', __DIR__ . '/Canvas/PHPStan/Rules');
$autoloader->register();
