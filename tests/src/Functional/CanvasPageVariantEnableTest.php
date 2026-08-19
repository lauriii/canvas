<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Functional;

use Drupal\block\Entity\Block;
use Drupal\canvas\Entity\PageRegion;
use Drupal\canvas\Entity\PageVariant;
use Drupal\Component\Uuid\Uuid;
use Drupal\Core\Url;
use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\canvas\Traits\GenerateComponentConfigTrait;
use Drupal\Tests\system\Functional\Cache\AssertPageCacheContextsAndTagsTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests Canvas Page Variant Enable.
 *
 * @legacy-covers \Drupal\canvas\Hook\PageRegionHooks::formSystemThemeSettingsAlter
 * @legacy-covers \Drupal\canvas\Hook\PageRegionHooks::formSystemThemeSettingsSubmit
 * @legacy-covers \Drupal\canvas\Controller\CanvasBlockListController
 * @legacy-covers \Drupal\canvas\Entity\PageRegion::createFromBlockLayout
 */
#[RunTestsInSeparateProcesses]
#[Group('canvas')]
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
      // The page variant resolver checks the site default selection even
      // before any variant exists.
      // @see \Drupal\canvas\PageVariantResolver
      'config:canvas.settings',
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
      // Drupal 11.4 uses only the `config:block_list` list cache tag for blocks,
      // dropping the per-block `config:block.block.*` tags (and `block_view`).
      // @see https://www.drupal.org/project/drupal/issues/3341042
      // @see \Drupal\block\Entity\Block::getCacheTagsToInvalidate()
      ...(version_compare(\Drupal::VERSION, '11.4', '<') ? [
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
      ] : []),
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
    // Enabling converted the block layout into the theme's page variant and
    // selected it as the site default, so Canvas now renders the page.
    $variant = PageVariant::load('theme_olivero');
    self::assertInstanceOf(PageVariant::class, $variant);
    self::assertSame('theme_olivero', \Drupal::config('canvas.settings')->get(PageVariant::DEFAULT_SETTING));

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
      'config:canvas.component.marker.page_content',
      'config:canvas.component.theme_page_template.olivero',
      'config:canvas.page_variant.theme_olivero',
    ]);

    // The template is disabled again when we disable Canvas: the site default
    // selection is cleared (the variant is kept for re-enabling) and core
    // block layout renders the page again.
    $this->drupalGet('/admin/appearance/settings/olivero');
    $this->submitForm(['use_canvas' => FALSE], 'Save configuration');
    $regions = PageRegion::loadMultiple();
    $this->assertCount(12, $regions);
    foreach ($regions as $region) {
      $this->assertFalse($region->status());
    }
    self::assertNull(\Drupal::config('canvas.settings')->get(PageVariant::DEFAULT_SETTING));
    self::assertInstanceOf(PageVariant::class, PageVariant::load('theme_olivero'));

    $this->drupalGet($front);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->responseContains('block-olivero-site-branding');

    // Re-enabling Canvas must not install a disabled variant as the site
    // default (that would bypass SiteDefaultPageVariantEnabled and then fail
    // every validated save of the variant): the reused variant is re-enabled
    // before being selected. Rebuild the container first: the variant's tree
    // holds a theme page template component, whose source plugin was
    // installed through the UI (so only the child site knows it yet), and
    // saving the variant below instantiates that plugin.
    $this->rebuildContainer();
    $storage = \Drupal::entityTypeManager()->getStorage(PageVariant::ENTITY_TYPE_ID);
    $variant = $storage->loadUnchanged('theme_olivero');
    self::assertInstanceOf(PageVariant::class, $variant);
    $variant->disable()->save();
    $this->drupalGet('/admin/appearance/settings/olivero');
    $this->submitForm(['use_canvas' => TRUE], 'Save configuration');
    // Bypass the test runner's static entity cache: the re-enable happened in
    // the child site.
    $reenabled = $storage->loadUnchanged('theme_olivero');
    self::assertInstanceOf(PageVariant::class, $reenabled);
    self::assertTrue($reenabled->status());
    self::assertSame('theme_olivero', \Drupal::config('canvas.settings')->get(PageVariant::DEFAULT_SETTING));
  }

}
