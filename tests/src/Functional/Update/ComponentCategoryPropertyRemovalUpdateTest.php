<?php

declare(strict_types=1);

// cspell:ignore hasnot Requiredness

namespace Drupal\Tests\canvas\Functional\Update;

use Drupal\canvas\Entity\Component;

/**
 * @covers canvas_post_update_0009_unset_category_property_on_components()
 * @group canvas
 */
final class ComponentCategoryPropertyRemovalUpdateTest extends CanvasUpdatePathTestBase {

  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected function setDatabaseDumpFiles(): void {
    $this->databaseDumpFiles[] = \dirname(__DIR__, 3) . '/fixtures/update/drupal-11.2.2-with-canvas-1.0.0-alpha1.bare.php.gz';
  }

  /**
   * Tests removal of 'category' property.
   */
  public function testComponentCategoryPropertyRemoval(): void {

    // All Component entities prior to update contain 'category' property.
    $original_components = Component::loadMultiple();
    foreach ($original_components as $component) {
      $this->assertNotNull($component->get('category'));
    }

    $this->runUpdates();

    // No Component entities contain post update contain 'category' property.
    $updated_components = Component::loadMultiple();
    foreach ($updated_components as $component) {
      $this->assertNull($component->get('category'));
    }
  }

}
