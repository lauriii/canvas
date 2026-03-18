<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\Block;

use Drupal\canvas\Block\BlockManagerDecorator;
use Drupal\canvas\Entity\Component;
use Drupal\canvas\Plugin\Canvas\ComponentSource\BlockComponent;
use Drupal\system\Entity\Menu;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\canvas\Traits\GenerateComponentConfigTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\views\Entity\View;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

#[CoversClass(BlockManagerDecorator::class)]
#[Group('canvas')]
#[Group('canvas_component_sources')]
final class BlockManagerDecoratorTest extends CanvasKernelTestBase {

  use GenerateComponentConfigTrait;
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'node',
  ];

  public function testNewViewsBlockDiscoveredAutomatically(): void {
    self::assertNull(Component::load('block.views_block.test_decorator_view-test_block'));

    $view = View::create([
      'id' => 'test_decorator_view',
      'label' => 'Test decorator view',
      'description' => 'A view for testing the BlockManager decorator.',
      'base_table' => 'node',
      'display' => [],
    ]);
    $view->addDisplay('default', 'Defaults', 'default');
    $view->addDisplay('block', 'Test Block', 'test_block');
    // Saving a View calls \views_invalidate_cache() which calls
    // BlockManager::clearCachedDefinitions(). The decorator intercepts this
    // to automatically trigger generateComponents().
    $view->save();

    $component = Component::load('block.views_block.test_decorator_view-test_block');
    // @todo Remove this when https://github.com/phpstan/phpstan/issues/13566#issuecomment-3645405380 is fixed.
    // @phpstan-ignore staticMethod.impossibleType
    self::assertNotNull($component, 'Views block component was auto-discovered by the BlockManager decorator.');
    self::assertSame(BlockComponent::SOURCE_PLUGIN_ID, $component->get('source'));
    self::assertTrue($component->status());
  }

  public function testNewMenuBlockDiscoveredAutomatically(): void {
    self::assertNull(Component::load('block.system_menu_block.test-decorator-menu'));

    // Saving a Menu calls BlockManager::clearCachedDefinitions() in
    // Menu::postSave(). The decorator intercepts this to automatically
    // trigger generateComponents().
    Menu::create([
      'id' => 'test-decorator-menu',
      'label' => 'Test decorator menu',
    ])->save();

    $component = Component::load('block.system_menu_block.test-decorator-menu');
    // @todo Remove this when https://github.com/phpstan/phpstan/issues/13566#issuecomment-3645405380 is fixed.
    // @phpstan-ignore staticMethod.impossibleType
    self::assertNotNull($component, 'Menu block component was auto-discovered by the BlockManager decorator.');
    self::assertSame(BlockComponent::SOURCE_PLUGIN_ID, $component->get('source'));
    // New menu block derivatives are disabled by default: only those in
    // BlockComponentDiscovery::BLOCKS_TO_KEEP_ENABLED are enabled.
    self::assertFalse($component->status());
  }

}
