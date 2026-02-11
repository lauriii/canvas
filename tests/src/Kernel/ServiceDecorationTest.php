<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel;

use Drupal\Core\Theme\ComponentPluginManager as CoreComponentPluginManager;
use Drupal\canvas\Plugin\ComponentPluginManager as CanvasComponentPluginManager;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * @group canvas
 */
#[RunTestsInSeparateProcesses]
final class ServiceDecorationTest extends CanvasKernelTestBase {

  public function testServiceDecoration(): void {
    $this->assertInstanceOf(CanvasComponentPluginManager::class, $this->container->get(CanvasComponentPluginManager::class));
    $this->assertInstanceOf(CanvasComponentPluginManager::class, $this->container->get(CoreComponentPluginManager::class));
    $this->assertInstanceOf(CanvasComponentPluginManager::class, $this->container->get('plugin.manager.sdc'));
  }

}
