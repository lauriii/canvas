<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Functional\Update;

use Drupal\Core\Database\Connection;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests converting legacy boolean block `label_display` inputs to strings.
 *
 * Core 11.3 (#3547808) made `block.settings` `label_display` a string enum
 * ('0' | 'visible'); component trees written under 11.2 could hold a boolean,
 * which now fails validation.
 *
 * @legacy-covers \canvas_post_update_0023_block_label_display_boolean_to_string
 */
#[RunTestsInSeparateProcesses]
#[Group('canvas')]
final class BlockLabelDisplayBooleanToStringUpdateTest extends CanvasUpdatePathTestBase {

  protected $defaultTheme = 'stark';

  /**
   * Content component-tree input columns the alpha1 fixture seeds.
   */
  private const CONTENT_INPUT_COLUMNS = [
    'canvas_page__components' => 'components_inputs',
    'canvas_page_revision__components' => 'components_inputs',
    'node__field_canvas_demo' => 'field_canvas_demo_inputs',
    'node_revision__field_canvas_demo' => 'field_canvas_demo_inputs',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setDatabaseDumpFiles(): void {
    $this->databaseDumpFiles[] = \dirname(__DIR__, 3) . '/fixtures/update/drupal-11.2.2-with-canvas-1.0.0-alpha1.bare.php.gz';
  }

  /**
   * Boolean `label_display` becomes the string '0' across every revision.
   */
  public function testBlockLabelDisplayBooleanToString(): void {
    $connection = \Drupal::database();

    // Before: the 1.0.0-alpha1 fixture seeds a block instance whose
    // `label_display` is a boolean, invalid under core 11.3's string enum.
    $before = self::readContentInputs($connection);
    self::assertStringContainsString('"label_display":false', $before, 'Expected the fixture to seed a boolean label_display.');

    $this->runUpdates();

    // After: every boolean is coerced to a string; none survive, in any
    // revision. The base class also asserts the data-health check reads clean.
    $after = self::readContentInputs($connection);
    self::assertStringNotContainsString('"label_display":false', $after);
    self::assertStringNotContainsString('"label_display":true', $after);
    self::assertStringContainsString('"label_display":"0"', $after);
  }

  /**
   * Reads and whitespace-normalizes every content component-tree input blob.
   */
  private static function readContentInputs(Connection $connection): string {
    $all = '';
    foreach (self::CONTENT_INPUT_COLUMNS as $table => $column) {
      if (!$connection->schema()->tableExists($table)) {
        continue;
      }
      $values = $connection->select($table, 't')->fields('t', [$column])->execute()?->fetchCol() ?? [];
      foreach ($values as $value) {
        $all .= (string) $value;
      }
    }
    return \preg_replace('/\s+/', '', $all) ?? '';
  }

}
