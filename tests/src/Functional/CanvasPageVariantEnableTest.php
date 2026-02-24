<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Functional;

use Drupal\block\Entity\Block;
use Drupal\Component\Uuid\Uuid;
use Drupal\Core\Url;
use Drupal\canvas\Entity\PageRegion;
use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\canvas\Traits\GenerateComponentConfigTrait;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Drupal\Tests\system\Functional\Cache\AssertPageCacheContextsAndTagsTrait;

/**
 * @group canvas
 * @covers \Drupal\canvas\Hook\PageRegionHooks::formSystemThemeSettingsAlter
 * @covers \Drupal\canvas\Hook\PageRegionHooks::formSystemThemeSettingsSubmit
 * @covers \Drupal\canvas\Controller\CanvasBlockListController
 * @covers \Drupal\canvas\Entity\PageRegion::createFromBlockLayout
 */
#[RunTestsInSeparateProcesses]
class CanvasPageVariantEnableTest extends BrowserTestBase {

  use GenerateComponentConfigTrait;
  use AssertPageCacheContextsAndTagsTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['block', 'canvas', 'node'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'olivero';

  public function test(): void {
    $assert = $this->assertSession();

    $this->drupalLogin($this->rootUser);
    $this->generateComponentConfig();

    $front = Url::fromRoute('<front>');
    $this->drupalGet($front);
    $this->assertSession()->statusCodeEquals(200);
    $content_cache_tags = [
      'config:system.menu.account',
      'config:system.menu.main',
      'config:system.site',
      'local_task',
      'rendered',
      'user:1',
      'user_view',
    ];
    $this->assertCacheTags([
      ...$content_cache_tags,
      // Cache tags bubbled by Drupal core's default "block" page variant.
      // @see \Drupal\block\Plugin\DisplayVariant\BlockPageVariant
      'block_view',
      'config:block.block.olivero_account_menu',
      'config:block.block.olivero_breadcrumbs',
      'config:block.block.olivero_content',
      'config:block.block.olivero_main_menu',
      'config:block.block.olivero_messages',
      'config:block.block.olivero_page_title',
      'config:block.block.olivero_powered',
      'config:block.block.olivero_primary_admin_actions',
      'config:block.block.olivero_primary_local_tasks',
      'config:block.block.olivero_secondary_local_tasks',
      'config:block.block.olivero_site_branding',
      'config:block_list',
    ]);

    // Disable the breadcrumbs block to check its absence from the regions
    // created when enabling Canvas.
    $block = Block::load('olivero_breadcrumbs');
    self::assertNotNull($block);
    $block->disable()->save();

    // No Canvas settings on the global settings page.
    $this->drupalGet('/admin/appearance/settings');
    $this->assertSession()->pageTextNotContains('Drupal Canvas');
    $this->assertSession()->fieldNotExists('use_canvas');

    // Canvas checkbox on the Olivero theme page.
    $this->drupalGet('/admin/appearance/settings/olivero');
    $this->assertSession()->pageTextContains('Drupal Canvas');
    $this->assertSession()->fieldExists('use_canvas');

    // We start with no templates.
    $this->assertEmpty(PageRegion::loadMultiple());

    // No template is created if we do not enable Canvas; no warning messages on
    // block listing.
    $this->submitForm(['use_canvas' => FALSE], 'Save configuration');
    // @phpstan-ignore-next-line method.alreadyNarrowedType
    $this->assertEmpty(PageRegion::loadMultiple());
    $this->drupalGet('/admin/structure/block');
    $assert->elementsCount('css', '[aria-label="Warning message"]', 0);

    // Regions are created when we enable Canvas; warning message appears on block
    // listing.
    $this->drupalGet('/admin/appearance/settings/olivero');
    $this->submitForm(['use_canvas' => TRUE], 'Save configuration');
    $regions = PageRegion::loadMultiple();
    $this->assertCount(12, $regions);
    $this->drupalGet('/admin/structure/block');
    $assert->elementsCount('css', '[aria-label="Warning message"]', 1);
    $assert->elementTextContains('css', '[aria-label="Warning message"] .messages__content', 'configured to use Drupal Canvas for managing the block layout');

    // Check the regions are created correctly.
    $expected_page_region_ids = [
      'olivero.breadcrumb',
      'olivero.content_above',
      'olivero.content_below',
      'olivero.footer_bottom',
      'olivero.footer_top',
      'olivero.header',
      'olivero.hero',
      'olivero.highlighted',
      'olivero.primary_menu',
      'olivero.secondary_menu',
      'olivero.sidebar',
      'olivero.social',
    ];
    $regions_with_component_tree = [];
    foreach ($regions as $region) {
      $regions_with_component_tree[$region->id()] = $region->getComponentTree()->getValue();
    }
    $this->assertSame($expected_page_region_ids, \array_keys($regions_with_component_tree));

    foreach ($regions_with_component_tree as $tree) {
      foreach ($tree as $component) {
        $this->assertTrue(Uuid::isValid($component['uuid']));
        $this->assertStringStartsWith('block.', $component['component_id']);
      }
    }
    $front = Url::fromRoute('<front>');
    $this->drupalGet($front);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->elementsCount('css', '#primary-tabs-title', 1);
    $this->assertCacheTags([
      ...$content_cache_tags,
      // Cache tags bubbled by Canvas' page variant.
      // @see \Drupal\canvas\Plugin\DisplayVariant\CanvasPageVariant
      'config:canvas.component.block.local_actions_block',
      'config:canvas.component.block.local_tasks_block',
      'config:canvas.component.block.page_title_block',
      'config:canvas.component.block.system_branding_block',
      'config:canvas.component.block.system_menu_block.account',
      'config:canvas.component.block.system_menu_block.main',
      'config:canvas.component.block.system_messages_block',
      'config:canvas.component.block.system_powered_by_block',
      'config:canvas.page_region.olivero.breadcrumb',
      'config:canvas.page_region.olivero.content_above',
      'config:canvas.page_region.olivero.content_below',
      'config:canvas.page_region.olivero.footer_bottom',
      'config:canvas.page_region.olivero.footer_top',
      'config:canvas.page_region.olivero.header',
      'config:canvas.page_region.olivero.hero',
      'config:canvas.page_region.olivero.highlighted',
      'config:canvas.page_region.olivero.primary_menu',
      'config:canvas.page_region.olivero.secondary_menu',
      'config:canvas.page_region.olivero.sidebar',
      'config:canvas.page_region.olivero.social',
    ]);

    // The template is disabled again when we disable Canvas.
    $this->drupalGet('/admin/appearance/settings/olivero');
    $this->submitForm(['use_canvas' => FALSE], 'Save configuration');
    $regions = PageRegion::loadMultiple();
    $this->assertCount(12, $regions);
    foreach ($regions as $region) {
      $this->assertFalse($region->status());
    }

    $this->drupalGet($front);
    $this->assertSession()->statusCodeEquals(200);
  }

}
