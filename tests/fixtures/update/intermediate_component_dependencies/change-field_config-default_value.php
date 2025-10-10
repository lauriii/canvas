<?php

/**
 * @file
 * Ensure a `component_tree` field config has a non-empty component tree.
 */

require_once 'common-component-tree.php';

use Drupal\Core\Database\Database;

$sort = function (array $array): array {
  sort($array, \SORT_STRING);
  return $array;
};

$connection = Database::getConnection();

$data = $connection->select('config')
  ->condition('name', 'field.field.node.article.field_canvas_demo')
  ->fields('config', ['data'])
  ->execute()
  ->fetchField();
$data = unserialize($data);
$data['default_value'] = COMPONENT_TREE_INCLUDING_REFERENCE_FIELD_TYPE_PROP_EXPRESSION;
$data['dependencies'] = [
  'config' => $sort([
    'field.storage.node.field_canvas_demo',
    'node.type.article',
    ...COMPONENT_TREE_INCLUDING_REFERENCE_FIELD_TYPE_PROP_EXPRESSION_DEPENDENCIES_BEFORE['config'],
  ]),
  'content' => COMPONENT_TREE_INCLUDING_REFERENCE_FIELD_TYPE_PROP_EXPRESSION_DEPENDENCIES_BEFORE['content'],
  'module' => [
    'canvas',
    ...COMPONENT_TREE_INCLUDING_REFERENCE_FIELD_TYPE_PROP_EXPRESSION_DEPENDENCIES_BEFORE['module'],
  ],
];
$connection->update('config')
  ->condition('name', 'field.field.node.article.field_canvas_demo')
  ->fields([
    'data' => serialize($data),
  ])
  ->execute();
