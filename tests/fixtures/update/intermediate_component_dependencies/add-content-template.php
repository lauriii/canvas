<?php

/**
 * @file
 * Adds a canvas content template.
 */

require_once 'common-component-tree.php';

use Drupal\Core\Database\Database;

$sort = function (array $array): array {
  sort($array, \SORT_STRING);
  return $array;
};

$connection = Database::getConnection();

$content_template_data = [
  "uuid" => "039bc7da-1610-4696-9b56-3796dc9df114",
  "langcode" => "en",
  "status" => TRUE,
  "dependencies" => [
    'config' => $sort([
      'canvas.component.sdc.canvas_test_sdc.heading',
      'node.type.article',
      'core.entity_view_mode.node.reverse',
      ...COMPONENT_TREE_INCLUDING_REFERENCE_FIELD_TYPE_PROP_EXPRESSION_DEPENDENCIES_BEFORE['config'],
    ]),
    'content' => COMPONENT_TREE_INCLUDING_REFERENCE_FIELD_TYPE_PROP_EXPRESSION_DEPENDENCIES_BEFORE['content'],
    'module' => $sort([
      'node',
      'options',
      ...COMPONENT_TREE_INCLUDING_REFERENCE_FIELD_TYPE_PROP_EXPRESSION_DEPENDENCIES_BEFORE['module'],
    ]),
  ],
  "id" => "node.article.reverse",
  "content_entity_type_id" => "node",
  "content_entity_type_bundle" => "article",
  "content_entity_type_view_mode" => "reverse",
  "component_tree" => [
    ...COMPONENT_TREE_INCLUDING_REFERENCE_FIELD_TYPE_PROP_EXPRESSION,
    ...COMPONENT_TREE_INCLUDING_DYNAMIC_PROP_EXPRESSION,
  ],
  "exposed_slots" => [],
];
$connection->insert('config')
  ->fields([
    'collection',
    'name',
    'data',
  ])
  ->values([
    'collection' => '',
    'name' => 'core.entity_view_mode.node.reverse',
    'data' => 'a:9:{s:4:"uuid";s:36:"eec27e32-a2e4-4e3f-9049-d5283c647785";s:8:"langcode";s:2:"en";s:6:"status";b:1;s:12:"dependencies";a:1:{s:6:"module";a:1:{i:0;s:6:"canvas";}}s:2:"id";s:12:"node.reverse";s:5:"label";s:7:"Reverse";s:11:"description";N;s:16:"targetEntityType";s:4:"node";s:5:"cache";b:1;}',
  ])
  ->values([
    'collection' => '',
    'name' => 'canvas.content_template.node.article.reverse',
    'data' => serialize($content_template_data),
  ])
  ->execute();
