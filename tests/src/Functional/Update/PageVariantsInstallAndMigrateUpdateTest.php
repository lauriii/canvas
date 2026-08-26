<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Functional\Update;

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\PageVariant;
use Drupal\canvas\Plugin\Canvas\ComponentSource\Marker;
use Drupal\Core\Config\StorageCacheInterface;
use Drupal\Core\Config\StorageInterface;
use PHPUnit\Framework\Attributes\CoversFunction;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests installing page variants and migrating page regions to a variant.
 */
#[CoversFunction('canvas_post_update_0028_install_page_variants')]
#[CoversFunction('canvas_post_update_0029_migrate_page_regions_to_variants')]
#[CoversFunction('canvas_post_update_0030_page_variant_selection_options')]
#[RunTestsInSeparateProcesses]
#[Group('canvas')]
final class PageVariantsInstallAndMigrateUpdateTest extends CanvasUpdatePathTestBase {

  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected function setDatabaseDumpFiles(): void {
    $this->databaseDumpFiles[] = \dirname(__DIR__, 3) . '/fixtures/update/drupal-11.2.10-with-canvas-1.2.0.bare.php.gz';
    $this->databaseDumpFiles[] = \dirname(__DIR__, 3) . '/fixtures/update/page_variants/add-stark-page-region.php';
    $this->databaseDumpFiles[] = \dirname(__DIR__, 3) . '/fixtures/update/page_variants/add-skipped-page-regions.php';
    $this->databaseDumpFiles[] = \dirname(__DIR__, 3) . '/fixtures/update/page_variants/add-page-region-draft-translation-and-stale-region.php';
  }

  /**
   * Tests that page variants are installed and page regions migrated.
   */
  public function testPageVariantsInstalledAndPageRegionsMigrated(): void {
    // The fixture site predates page variants entirely.
    $this->assertNull(\Drupal::entityDefinitionUpdateManager()->getEntityType(PageVariant::ENTITY_TYPE_ID));
    $this->assertTrue(\Drupal::configFactory()->get('canvas.settings')->isNew());
    $this->assertFalse(\Drupal::moduleHandler()->moduleExists('canvas_page_template_component'));

    $this->runUpdates();

    // 0028: the entity type, the selection field on canvas_page, the "Page
    // content" marker component, and the settings object all exist.
    $this->assertNotNull(\Drupal::entityDefinitionUpdateManager()->getEntityType(PageVariant::ENTITY_TYPE_ID));
    $this->assertNotNull(\Drupal::entityDefinitionUpdateManager()->getFieldStorageDefinition('page_variant', 'canvas_page'));
    $marker = Component::load(Marker::PAGE_CONTENT_COMPONENT_ID);
    $this->assertNotNull($marker);
    $this->assertEntityIsValid($marker);

    // 0029: the stark page region was converted into the `theme_stark`
    // variant, which became the site default (stark is the default theme).
    $this->assertTrue(\Drupal::moduleHandler()->moduleExists('canvas_page_template_component'));
    $template_component = Component::load('theme_page_template.stark');
    $this->assertNotNull($template_component);
    $this->assertEntityIsValid($template_component);

    $variant = PageVariant::load('theme_stark');
    $this->assertNotNull($variant);
    $this->assertEntityIsValid($variant);
    $this->assertSame('Stark theme', $variant->label());
    $this->assertSame('theme_stark', \Drupal::config('canvas.settings')->get(PageVariant::DEFAULT_SETTING));

    // The variant tree is: the theme page template at the root, the marker in
    // its `content` slot, and the region's block re-parented into the
    // `sidebar_first` slot.
    $items = $variant->getComponentTree()->getValue();
    $this->assertCount(3, $items);
    $by_component = \array_column($items, NULL, 'component_id');
    $template_uuid = $by_component['theme_page_template.stark']['uuid'];
    $this->assertArrayNotHasKey('parent_uuid', $by_component['theme_page_template.stark']);
    $this->assertSame($template_uuid, $by_component[Marker::PAGE_CONTENT_COMPONENT_ID]['parent_uuid']);
    $this->assertSame('content', $by_component[Marker::PAGE_CONTENT_COMPONENT_ID]['slot']);
    $this->assertSame($template_uuid, $by_component['block.system_powered_by_block']['parent_uuid']);
    $this->assertSame('sidebar_first', $by_component['block.system_powered_by_block']['slot']);
    $this->assertSame([
      'label' => '',
      'label_display' => '0',
    ], $by_component['block.system_powered_by_block']['inputs']);

    // The disabled `stark.header` region must not resurrect: its block does not
    // appear in the `theme_stark` variant (the tree above stays at 3 items).
    // Neither does the enabled `stark.legacy_zone` region's block: stark's
    // info.yml declares no `legacy_zone` region, so the theme page template
    // component has no matching slot for its items. That stale region can
    // never render or be re-enabled, so its config was deleted outright.
    $this->assertArrayNotHasKey('block.system_messages_block', $by_component);
    $this->assertTrue(\Drupal::config('canvas.page_region.stark.legacy_zone')->isNew());

    // Claro is not the default theme, so its region is not migrated even though
    // it is enabled: only the default theme rendered through regions. No
    // `theme_claro` variant is created, and the site default stays `theme_stark`.
    $this->assertNull(PageVariant::load('theme_claro'));
    $this->assertSame('theme_stark', \Drupal::config('canvas.settings')->get(PageVariant::DEFAULT_SETTING));

    // The pending `stark.sidebar_first` draft was converted into one
    // auto-saved draft on the variant: the same slot placement, applied to the
    // draft's tree. The stored variant above keeps the published inputs; the
    // draft carries the editor's unpublished label change.
    $auto_save_manager = \Drupal::service(AutoSaveManager::class);
    \assert($auto_save_manager instanceof AutoSaveManager);
    $draft_variant = $auto_save_manager->getAutoSaveEntity($variant)->entity;
    $this->assertInstanceOf(PageVariant::class, $draft_variant);
    $draft_items = $draft_variant->getComponentTree()->getValue();
    $this->assertCount(3, $draft_items);
    $draft_by_component = \array_column($draft_items, NULL, 'component_id');
    $this->assertSame($template_uuid, $draft_by_component['block.system_powered_by_block']['parent_uuid']);
    $this->assertSame('sidebar_first', $draft_by_component['block.system_powered_by_block']['slot']);
    $this->assertSame([
      'label' => 'Powered by Drupal (draft)',
      'label_display' => 'visible',
    ], $draft_by_component['block.system_powered_by_block']['inputs']);
    // The draft and the stored variant share the template and marker instance
    // UUIDs, so publishing the draft evolves the stored tree instead of
    // replacing it wholesale.
    $this->assertSame($template_uuid, $draft_by_component['theme_page_template.stark']['uuid']);
    $this->assertSame($by_component[Marker::PAGE_CONTENT_COMPONENT_ID]['uuid'], $draft_by_component[Marker::PAGE_CONTENT_COMPONENT_ID]['uuid']);
    // The consumed region entry is gone from the auto-save store.
    $this->assertNull(\Drupal::keyValue(AutoSaveManager::AUTO_SAVE_STORE)->get('page_region:stark.sidebar_first'));

    // The region's French translation override carried over onto the variant:
    // item UUIDs are preserved by the conversion and are the override keys.
    // cspell:ignore Propulsé
    $config_storage = \Drupal::service(StorageCacheInterface::class);
    \assert($config_storage instanceof StorageInterface);
    $this->assertSame([
      'component_tree' => [
        '5d306ef8-0778-4bd2-adcf-59e764ee73d5' => [
          'inputs' => [
            'label' => 'Propulsé par Drupal',
          ],
        ],
      ],
    ], $config_storage->createCollection('language.fr')->read('canvas.page_variant.theme_stark'));
  }

}
