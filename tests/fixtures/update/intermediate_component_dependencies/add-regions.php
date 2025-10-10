<?php

/**
 * @file
 * Adds a canvas page region.
 */

require_once 'common-component-tree.php';

use Drupal\Core\Database\Database;

$connection = Database::getConnection();

$region_data = [
  "uuid" => "ab930f5a-b97a-4fbb-8749-b5196b1df555",
  "langcode" => "en",
  "status" => TRUE,
  "dependencies" => COMPONENT_TREE_INCLUDING_REFERENCE_FIELD_TYPE_PROP_EXPRESSION_DEPENDENCIES_BEFORE
  + ['theme' => ['stark']],
  "id" => "stark.sidebar_first",
  "region" => "sidebar_first",
  "theme" => "stark",
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
    'name' => 'canvas.page_region.stark.sidebar_first',
    'data' => serialize($region_data),
  ])
  ->execute();
