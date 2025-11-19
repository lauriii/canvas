<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Functional\Update;

use Drupal\canvas\Entity\Component;

/**
 * @covers canvas_post_update_0007_respect_prop_ordering()
 * @group canvas
 */
final class RespectPropOrderUpdateTest extends CanvasUpdatePathTestBase {

  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected function setDatabaseDumpFiles(): void {
    $this->databaseDumpFiles[] = \dirname(__DIR__, 3) . '/fixtures/update/drupal-11.2.2-with-canvas-1.0.0-alpha1.bare.php.gz';
  }

  /**
   * Tests Components whose prop order has been lost.
   */
  public function testRespectPropOrder(): void {
    // Canaries to test, with non-alphabetical prop orders.
    $component_ids = [
      // An `sdc` Component.
      // @see tests/modules/canvas_test_sdc/components/simple/heading/heading.component.yml
      'sdc.canvas_test_sdc.heading' => ['text', 'style', 'element'],
      // A `js` Component.
      // @see tests/modules/canvas_test_code_components/config/install/canvas.js_component.canvas_test_code_components_with_props.yml
      'js.canvas_test_code_components_with_props' => ['name', 'age'],
    ];

    $before = [];
    foreach (array_keys($component_ids) as $component_id) {
      $component = Component::load($component_id);
      self::assertNotNull($component);
      $before[$component_id] = array_keys($component->getSettings()['prop_field_definitions']);
    }

    $this->runUpdates();

    $after = [];
    foreach (array_keys($component_ids) as $component_id) {
      $component = Component::load($component_id);
      self::assertNotNull($component);
      $after[$component_id] = array_keys($component->getSettings()['prop_field_definitions']);
    }

    foreach ($component_ids as $component_id => $expectation) {
      // Prop order must have been updated…
      self::assertNotSame($before[$component_id], $after[$component_id], $component_id);
      // … to the expected order.
      self::assertSame($after[$component_id], $expectation, $component_id);
    }
  }

}
