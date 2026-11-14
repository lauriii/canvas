<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\Block;

use Drupal\block_content\Entity\BlockContent;
use Drupal\block_content\Entity\BlockContentType;
use Drupal\canvas\Block\BlockManagerDecorator;
use Drupal\canvas\Entity\Component;
use Drupal\canvas\Plugin\Canvas\ComponentSource\BlockComponent;
use Drupal\Core\Block\BlockManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Extension\ModuleInstallerInterface;
use Drupal\system\Entity\Menu;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\canvas\Traits\GenerateComponentConfigTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\views\Entity\View;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

#[CoversClass(BlockManagerDecorator::class)]
#[Group('canvas')]
#[Group('canvas_component_sources')]
#[RunTestsInSeparateProcesses]
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

  /**
   * Installing block_content must not break the site.
   *
   * Core clears plugin caches during a module install before it installs the
   * module's entity types; block_content's derivative queries its table.
   * Webform crashed during the same cache clear, on sites with Trash 3.1.0:
   * its block asked for the webform entity type before core knew about it.
   *
   * @see https://www.drupal.org/project/canvas/issues/3582851
   */
  public function testInstallingBlockContentDoesNotDiscoverBlocksBeforeItsTablesExist(): void {
    $this->container->get(ModuleInstallerInterface::class)->install(['block_content']);

    // Now that the install is done, new reusable blocks must still become
    // components right away.
    BlockContentType::create([
      'id' => 'basic',
      'label' => 'Basic',
    ])->save();
    $block_content = BlockContent::create([
      'type' => 'basic',
      'info' => 'Test reusable block',
      'reusable' => TRUE,
    ]);
    $block_content->save();

    $component = Component::load('block.block_content.' . $block_content->uuid());
    self::assertNotNull($component, 'The reusable block is generated as a component after the install.');
    self::assertSame(BlockComponent::SOURCE_PLUGIN_ID, $component->get('source'));
  }

  /**
   * Installing several modules at once must not break the site either.
   *
   * Core installs modules in groups. Modules that don't set
   * `container_rebuild_required: true` in their info file share a group. Core
   * rebuilds the container at the start of each group, and resets it again
   * after installing config if the group has more than one module. The install
   * must still succeed, and the block manager decorator must generate
   * components again afterwards.
   *
   * @see \Drupal\Core\Extension\ModuleInstaller::install()
   * @see \Drupal\Core\Extension\ModuleInstaller::doInstall()
   * @see https://www.drupal.org/project/canvas/issues/3582851
   */
  public function testInstallingSeveralModulesAtOnce(): void {
    $this->container->get(ModuleInstallerInterface::class)->install([
      'block_content',
      'contact',
    ]);

    $module_handler = $this->container->get(ModuleHandlerInterface::class);
    self::assertTrue($module_handler->moduleExists('block_content'));
    self::assertTrue($module_handler->moduleExists('contact'));

    $block_manager = $this->container->get(BlockManagerInterface::class);
    self::assertInstanceOf(BlockManagerDecorator::class, $block_manager);
    self::assertFalse($block_manager->moduleInstallInProgress);
  }

}
