<?php

/**
 * @file
 * Updates 4 config entities with component inputs as arrays to JSON blobs.
 *
 * This builds upon the (slightly incorrect) test fixture for another test.
 *
 * @see tests/fixtures/update/collapsed_inputs/collapsed-inputs-fixture.php
 * @see \Drupal\Tests\canvas\Functional\Update\CollapseComponentInputsUpdateTest
 */

use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Database\Database;
use Drupal\Core\Database\Statement\FetchAs;

$connection = Database::getConnection();

// Keys are config names, values are the paths within the config data to the
// stored component tree.
$config_with_component_trees = [
  'field.field.node.article.field_canvas_demo' => ['default_value'],
  'canvas.page_region.stark.sidebar_first' => ['component_tree'],
  'canvas.content_template.node.article.reverse' => ['component_tree'],
  'canvas.pattern.test_pattern' => ['component_tree'],
];

$query = $connection->select('config', 'c')
  ->fields('c', ['name', 'data']);
$query->condition('c.name', \array_keys($config_with_component_trees), 'IN');
$config_with_component_trees_to_update = $query->execute()->fetchAllAssoc('name', FetchAs::Associative);

// Due to `type: ignore`, whichever value happened to be assigned would have
// been stored. Due to ComponentTreeItem(List)::getValue(), `inputs` would have
// been encoded into a JSON blob.
// All of Canvas' already did the right conversion at runtime, for robustness
// and testability reasons. When testing an update path, real-world accuracy
// matters, so use JSON blobs as Canvas did in production at the time.
foreach ($config_with_component_trees_to_update as $name => ['data' => $data]) {
  $raw = unserialize($data);

  $path_to_component_tree = $config_with_component_trees[$name];
  $component_tree = NestedArray::getValue($raw, $path_to_component_tree);

  $inputs_as_json_blobs = \array_map(
    function (array $component_instance) {
      \assert(\is_array($component_instance['inputs']));
      $component_instance['inputs'] = json_encode($component_instance['inputs'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
      return $component_instance;
    },
    $component_tree,
  );
  NestedArray::setValue($raw, $path_to_component_tree, $inputs_as_json_blobs);

  $connection->update('config')
    ->condition('name', $name)
    ->fields(['data' => serialize($raw)])
    ->execute();
}
