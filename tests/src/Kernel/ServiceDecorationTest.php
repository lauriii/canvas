<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Kernel;

use Drupal\Core\Block\BlockManagerInterface;
use Drupal\Core\Theme\ComponentPluginManager as CoreComponentPluginManager;
use Drupal\experience_builder\Plugin\BlockManager as XbBlockManager;
use Drupal\experience_builder\Plugin\ComponentPluginManager as XbComponentPluginManager;
use Drupal\KernelTests\KernelTestBase;

/**
 * @group experience_builder
 */
final class ServiceDecorationTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['experience_builder'];

  public function testServiceDecoration(): void {
    $this->assertInstanceOf(XbComponentPluginManager::class, $this->container->get(XbComponentPluginManager::class));
    $this->assertInstanceOf(XbComponentPluginManager::class, $this->container->get(CoreComponentPluginManager::class));
    $this->assertInstanceOf(XbComponentPluginManager::class, $this->container->get('plugin.manager.sdc'));

    $this->assertInstanceOf(XbBlockManager::class, $this->container->get(XbBlockManager::class));
    $this->assertInstanceOf(XbBlockManager::class, $this->container->get(BlockManagerInterface::class));
    $this->assertInstanceOf(XbBlockManager::class, $this->container->get('plugin.manager.block'));
  }

}
