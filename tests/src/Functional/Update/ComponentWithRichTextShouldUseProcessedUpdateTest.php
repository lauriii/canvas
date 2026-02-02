<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Functional\Update;

use Drupal\canvas\Entity\Component;

/**
 * @covers canvas_post_update_0005_use_processed_for_text_props_in_components()
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

  private function assertExpectedVersions(string $component_id, array $versions): void {
    $component = Component::load($component_id);
    self::assertInstanceOf(Component::class, $component);
    self::assertSame($versions, $component->getVersions(), $component_id);
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
        'versions' => [
          'before' => [
            '467583e3f9bdfa95',
          ],
          'after' => [
            // New versions: one for each upgrade path that runs.
            '9c51b17efe5e61b6',
            '1b5d1287a2acc173',
            '4757f4fd316da603',
            // Same as before.
            '467583e3f9bdfa95',
          ],
        ],
      ],
      'sdc.canvas_test_sdc.banner' => [
        'props' => [
          'text' => [
            'before' => 'ℹ︎text_long␟value',
            'after' => 'ℹ︎text_long␟processed',
          ],
        ],
        'versions' => [
          'before' => [
            'fbe4167cd14f85a1',
          ],
          'after' => [
            // New versions: one for each upgrade path that runs.
            '5bfcfeb80ef248ae',
            '523708be2aeffd2a',
            '44ce4837d1471050',
            'aab57a17fac1fac6',
            // Same as before.
            'fbe4167cd14f85a1',
          ],
        ],
      ],
    ];

    foreach ($component_ids as $component_id => $component_data) {
      self::assertExpectedVersions($component_id, $component_data['versions']['before']);
      foreach ($component_data['props'] as $prop_name => $expressions) {
        self::assertExpectedVersionsExpression($component_id, $prop_name, $expressions['before']);
      }
    }

    $this->runUpdates();

    foreach ($component_ids as $component_id => $component_data) {
      self::assertExpectedVersions($component_id, $component_data['versions']['after']);
      foreach ($component_data['props'] as $prop_name => $expressions) {
        self::assertExpectedVersionsExpression($component_id, $prop_name, $expressions['after']);
      }
      $updated_component = Component::load($component_id);
      self::assertNotNull($updated_component);
      self::assertEntityIsValid($updated_component);
    }
  }

}
