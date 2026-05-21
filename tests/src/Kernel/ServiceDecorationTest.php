<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel;

use Drupal\canvas\Plugin\ComponentPluginManager as CanvasComponentPluginManager;
use Drupal\Core\Theme\ComponentPluginManager as CoreComponentPluginManager;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests Service Decoration.
 */
#[RunTestsInSeparateProcesses]
#[Group('canvas')]
final class ServiceDecorationTest extends CanvasKernelTestBase {

  public function testServiceDecoration(): void {
    $this->assertInstanceOf(CanvasComponentPluginManager::class, $this->container->get(CanvasComponentPluginManager::class));
    $this->assertInstanceOf(CanvasComponentPluginManager::class, $this->container->get(CoreComponentPluginManager::class));
    $this->assertInstanceOf(CanvasComponentPluginManager::class, $this->container->get('plugin.manager.sdc'));
  }

}
