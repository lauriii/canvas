<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Functional\Update;

use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\PageVariant;
use Drupal\canvas\Plugin\Canvas\ComponentSource\Marker;
use PHPUnit\Framework\Attributes\CoversFunction;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests installing page variants and migrating page regions to a variant.
 */
#[CoversFunction('canvas_post_update_0023_install_page_variants')]
#[CoversFunction('canvas_post_update_0024_migrate_page_regions_to_variants')]
#[CoversFunction('canvas_post_update_0025_page_variant_selection_options')]
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

    // 0023: the entity type, the selection field on canvas_page, the "Page
    // content" marker component, and the settings object all exist.
    $this->assertNotNull(\Drupal::entityDefinitionUpdateManager()->getEntityType(PageVariant::ENTITY_TYPE_ID));
    $this->assertNotNull(\Drupal::entityDefinitionUpdateManager()->getFieldStorageDefinition('page_variant', 'canvas_page'));
    $marker = Component::load(Marker::PAGE_CONTENT_COMPONENT_ID);
    $this->assertNotNull($marker);
    $this->assertEntityIsValid($marker);

    // 0024: the stark page region was converted into the `theme_stark`
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
  }

}
