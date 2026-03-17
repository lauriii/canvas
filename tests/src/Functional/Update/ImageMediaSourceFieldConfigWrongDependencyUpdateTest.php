<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Functional\Update;

use Drupal\field\Entity\FieldConfig;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests removing incorrect image style dependency from field configs.
 *
 * @see https://www.drupal.org/project/canvas/issues/3575579
 *
 * @legacy-covers \canvas_post_update_0015_remove_wrong_image_style_dependency_in_field_configs
 */
#[RunTestsInSeparateProcesses]
#[Group('canvas')]
final class ImageMediaSourceFieldConfigWrongDependencyUpdateTest extends CanvasUpdatePathTestBase {

  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected function setDatabaseDumpFiles(): void {
    $this->databaseDumpFiles[] = \dirname(__DIR__, 3) . '/fixtures/update/drupal-11.2.10-with-canvas-1.2.0.bare.php.gz';
  }

  public function test(): void {
    $field = FieldConfig::load('media.image.field_media_image');
    self::assertNotNull($field);
    self::assertNotContains('image.style.canvas_parametrized_width', $field->getDependencies()['config']);

    // Install the test module that overrides the image field type with the
    // buggy calculateDependencies() and re-save the field config.
    \Drupal::service('module_installer')->install(['canvas_test_buggy_image_item_override']);
    $field = FieldConfig::load('media.image.field_media_image');
    self::assertNotNull($field);
    $field->save();
    \Drupal::service('module_installer')->uninstall(['canvas_test_buggy_image_item_override']);

    // Confirm the bad dependency.
    $field = FieldConfig::load('media.image.field_media_image');
    self::assertNotNull($field);
    self::assertContains('image.style.canvas_parametrized_width', $field->getDependencies()['config']);

    $this->runUpdates();

    // Confirm the bad dependency has been removed.
    $field = FieldConfig::load('media.image.field_media_image');
    self::assertNotNull($field);
    self::assertEntityIsValid($field);
    self::assertNotContains('image.style.canvas_parametrized_width', $field->getDependencies()['config'] ?? []);
  }

}
