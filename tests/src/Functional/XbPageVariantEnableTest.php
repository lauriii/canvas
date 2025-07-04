<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Functional;

use Drupal\Component\Uuid\Uuid;
use Drupal\Core\Url;
use Drupal\experience_builder\Entity\PageRegion;
use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\experience_builder\Traits\GenerateComponentConfigTrait;

/**
 * @group experience_builder
 * @covers \Drupal\experience_builder\Hook\PageRegionHooks::formSystemThemeSettingsAlter()
 * @covers \Drupal\experience_builder\Hook\PageRegionHooks::formSystemThemeSettingsSubmit()
 * @covers \Drupal\experience_builder\Controller\XbBlockListController
 * @covers \Drupal\experience_builder\Entity\PageRegion::createFromBlockLayout()
 */
class XbPageVariantEnableTest extends BrowserTestBase {

  use GenerateComponentConfigTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['block', 'experience_builder', 'node'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'olivero';

  public function test(): void {
    $assert = $this->assertSession();

    $this->drupalLogin($this->rootUser);
    $this->generateComponentConfig();

    // No XB settings on the global settings page.
    $this->drupalGet('/admin/appearance/settings');
    $this->assertSession()->pageTextNotContains('Experience Builder');
    $this->assertSession()->fieldNotExists('use_xb');

    // XB checkbox on the Olivero theme page.
    $this->drupalGet('/admin/appearance/settings/olivero');
    $this->assertSession()->pageTextContains('Experience Builder');
    $this->assertSession()->fieldExists('use_xb');

    // We start with no templates.
    $this->assertEmpty(PageRegion::loadMultiple());

    // No template is created if we do not enable XB; no warning messages on
    // block listing.
    $this->submitForm(['use_xb' => FALSE], 'Save configuration');
    $this->assertEmpty(PageRegion::loadMultiple());
    $this->drupalGet('/admin/structure/block');
    $assert->elementsCount('css', '[aria-label="Warning message"]', 0);

    // Regions are created when we enable XB; warning message appears on block
    // listing.
    $this->drupalGet('/admin/appearance/settings/olivero');
    $this->submitForm(['use_xb' => TRUE], 'Save configuration');
    $regions = PageRegion::loadMultiple();
    $this->assertCount(12, $regions);
    $this->drupalGet('/admin/structure/block');
    $assert->elementsCount('css', '[aria-label="Warning message"]', 1);
    $assert->elementTextContains('css', '[aria-label="Warning message"] .messages__content', 'configured to use Experience Builder for managing the block layout');

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
    $this->assertSame($expected_page_region_ids, array_keys($regions_with_component_tree));

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

    // The template is disabled again when we disable XB.
    $this->drupalGet('/admin/appearance/settings/olivero');
    $this->submitForm(['use_xb' => FALSE], 'Save configuration');
    $regions = PageRegion::loadMultiple();
    $this->assertCount(12, $regions);
    foreach ($regions as $region) {
      $this->assertFalse($region->status());
    }

    $this->drupalGet($front);
    $this->assertSession()->statusCodeEquals(200);
  }

}
