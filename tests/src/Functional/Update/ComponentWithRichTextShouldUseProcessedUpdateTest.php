<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Functional\Update;

use Drupal\canvas\Entity\Component;

/**
 * @covers canvas_post_update_0004_use_processed_for_text_props_in_components()
 * @group canvas
 */
final class ComponentWithRichTextShouldUseProcessedUpdateTest extends CanvasUpdatePathTestBase {

  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected function setDatabaseDumpFiles(): void {
    $this->databaseDumpFiles[] = \dirname(__DIR__, 3) . '/fixtures/update/drupal-11.2.2-with-canvas-1.0.0-alpha1.bare.php.gz';
    $this->databaseDumpFiles[] = \dirname(__DIR__, 3) . '/fixtures/update/component_text_processed/components-with-rich-text-fixture.php';
  }

  private function assertExpectedVersionsExpression(string $component_id, string $prop_name, string $expected_expression): void {
    $component = Component::load($component_id);
    self::assertInstanceOf(Component::class, $component);
    foreach ($component->getVersions() as $version) {
      $component->loadVersion($version);
      self::assertSame($expected_expression, $component->getSettings()['prop_field_definitions'][$prop_name]['expression']);
    }
  }

  private function assertExpectedVersionsCount(string $component_id, int $versions_count): void {
    $component = Component::load($component_id);
    self::assertInstanceOf(Component::class, $component);
    self::assertCount($versions_count, $component->getVersions());
  }

  /**
   * Tests the text props expressions are using `processed`.
   */
  public function testComponentTextPropsExpression(): void {
    $component_ids = [
      'js.component_with_rich_text' => [
        'props' => [
          'text' => [
            'before' => 'ℹ︎text_long␟value',
            'after' => 'ℹ︎text_long␟processed',
          ],
        ],
        'expected_versions' => [
          'before' => 1,
          // There will be 3 versions after the update, because the update runs
          // canvas_post_update_0004_use_processed_for_text_props_in_components
          // but also the one post_update affecting components before that one,
          // canvas_post_update_0001_track_props_have_required_flag_in_components.
          'after' => 3,
        ],
      ],
      'sdc.canvas_test_sdc.banner' => [
        'props' => [
          'text' => [
            'before' => 'ℹ︎text_long␟value',
            'after' => 'ℹ︎text_long␟processed',
          ],
        ],
        'expected_versions' => [
          'before' => 1,
          'after' => 2,
        ],
      ],
    ];

    foreach ($component_ids as $component_id => $component_data) {
      self::assertExpectedVersionsCount($component_id, $component_data['expected_versions']['before']);
      foreach ($component_data['props'] as $prop_name => $expressions) {
        self::assertExpectedVersionsExpression($component_id, $prop_name, $expressions['before']);
      }
    }

    $this->runUpdates();

    foreach ($component_ids as $component_id => $component_data) {
      self::assertExpectedVersionsCount($component_id, $component_data['expected_versions']['after']);
      foreach ($component_data['props'] as $prop_name => $expressions) {
        self::assertExpectedVersionsExpression($component_id, $prop_name, $expressions['after']);
      }
      $updated_component = Component::load($component_id);
      self::assertNotNull($updated_component);
      self::assertEntityIsValid($updated_component);
    }
  }

}
