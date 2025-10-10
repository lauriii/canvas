<?php

/**
 * @file
 * Adds a canvas pattern.
 */

require_once 'common-component-tree.php';

use Drupal\Core\Database\Database;

$connection = Database::getConnection();

$pattern_data = [
  "uuid" => "aef3da09-dd4e-45ee-ad3c-a44ea897af54",
  "langcode" => "en",
  "status" => TRUE,
  "dependencies" => COMPONENT_TREE_INCLUDING_REFERENCE_FIELD_TYPE_PROP_EXPRESSION_DEPENDENCIES_BEFORE,
  "id" => "a_pattern_to_be_reused",
  "label" => "A pattern to be reused",
  "component_tree" => COMPONENT_TREE_INCLUDING_REFERENCE_FIELD_TYPE_PROP_EXPRESSION,
];

$connection->insert('config')
  ->fields([
    'collection',
    'name',
    'data',
  ])
  ->values([
    'collection' => '',
    'name' => 'canvas.pattern.a_pattern_to_be_reused',
    'data' => serialize($pattern_data),
  ])
  ->execute();
