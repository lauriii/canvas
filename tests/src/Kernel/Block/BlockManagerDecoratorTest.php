<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\Block;

use Drupal\canvas\Block\BlockManagerDecorator;
use Drupal\canvas\ComponentSource\ComponentSourceManager;
use Drupal\canvas\Entity\Component;
use Drupal\canvas\Plugin\Canvas\ComponentSource\BlockComponent;
use Drupal\canvas\Service\CanvasModuleInstallerDecorator;
use Drupal\system\Entity\Menu;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\canvas\Traits\GenerateComponentConfigTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\views\Entity\View;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

#[CoversClass(BlockManagerDecorator::class)]
#[CoversClass(CanvasModuleInstallerDecorator::class)]
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

  public function testNewViewsBlockGeneratedOnRebuild(): void {
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
    // BlockManager::clearCachedDefinitions(). The decorator deliberately no
    // longer regenerates components there (that eager regeneration mid-module-
    // install is what wedged the site, see #3582851), so the component does not
    // exist yet.
    $view->save();
    self::assertNull(Component::load('block.views_block.test_decorator_view-test_block'));

    // A cache rebuild (hook_rebuild, i.e. drush cr) regenerates components and
    // the new Views block surfaces.
    $this->container->get(ComponentSourceManager::class)->generateComponents();

    $component = Component::load('block.views_block.test_decorator_view-test_block');
    // @todo Remove this when https://github.com/phpstan/phpstan/issues/13566#issuecomment-3645405380 is fixed.
    // @phpstan-ignore staticMethod.impossibleType
    self::assertNotNull($component, 'Views block component was generated on rebuild.');
    self::assertSame(BlockComponent::SOURCE_PLUGIN_ID, $component->get('source'));
    self::assertTrue($component->status());
  }

  public function testNewMenuBlockGeneratedOnRebuild(): void {
    self::assertNull(Component::load('block.system_menu_block.test-decorator-menu'));

    // Saving a Menu calls BlockManager::clearCachedDefinitions() in
    // Menu::postSave(), which no longer eagerly regenerates components, so the
    // component does not exist yet.
    Menu::create([
      'id' => 'test-decorator-menu',
      'label' => 'Test decorator menu',
    ])->save();
    self::assertNull(Component::load('block.system_menu_block.test-decorator-menu'));

    // A cache rebuild (hook_rebuild, i.e. drush cr) regenerates components and
    // the new Menu block surfaces.
    $this->container->get(ComponentSourceManager::class)->generateComponents();

    $component = Component::load('block.system_menu_block.test-decorator-menu');
    // @todo Remove this when https://github.com/phpstan/phpstan/issues/13566#issuecomment-3645405380 is fixed.
    // @phpstan-ignore staticMethod.impossibleType
    self::assertNotNull($component, 'Menu block component was generated on rebuild.');
    self::assertSame(BlockComponent::SOURCE_PLUGIN_ID, $component->get('source'));
    // New menu block derivatives are disabled by default: only those in
    // BlockComponentDiscovery::BLOCKS_TO_KEEP_ENABLED are enabled.
    self::assertFalse($component->status());
  }

  public function testInstallingModuleWithEntityQueryingBlockDeriverDoesNotBreakSite(): void {
    // Installing a module whose block plugin deriver queries its own entity
    // storage must not crash: core clears plugin caches during
    // ModuleInstaller::doInstall() *before* the module's entity schemas are
    // created. If Canvas regenerated components at that point, the block_content
    // deriver's entity query would hit a nonexistent table, the install would
    // abort halfway, and the site would be left permanently broken.
    // @see https://www.drupal.org/project/canvas/issues/3582851

    // Component generation no longer runs eagerly on plugin cache clears, so no
    // components exist yet. This makes the post-install assertion below a
    // genuine proof that the module_installer decorator regenerated them.
    self::assertSame([], Component::loadMultiple());

    // Install through the real module_installer service (decorated by
    // CanvasModuleInstallerDecorator), exercising the whole install lifecycle.
    // On unfixed code this call aborts with the block_content deriver's
    // "Table 'block_content' doesn't exist" query.
    $this->container->get('module_installer')->install(['block_content']);

    // The install completed: the module is installed and its entity schema
    // exists.
    self::assertTrue($this->container->get('module_handler')->moduleExists('block_content'));
    self::assertTrue($this->container->get('database')->schema()->tableExists('block_content'));

    // Block components were generated after the install transaction returned,
    // by CanvasModuleInstallerDecorator::install() — not mid-install.
    // @see \Drupal\canvas\Service\CanvasModuleInstallerDecorator
    $block_component_ids = array_filter(
      \array_keys(Component::loadMultiple()),
      fn (string $id): bool => str_starts_with($id, BlockComponent::SOURCE_PLUGIN_ID . '.'),
    );
    self::assertNotEmpty($block_component_ids);
  }

}
