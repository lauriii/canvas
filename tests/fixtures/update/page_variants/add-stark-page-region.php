<?php

/**
 * @file
 * Adds a stark page region so the page-variant migration has data to convert.
 */

use Drupal\Core\Database\Database;

$connection = Database::getConnection();

// A page region for the fixture site's default theme (stark), holding one
// block component instance. `block.system_powered_by_block` ships in the
// fixture database dump with active version 3332388cade78d20.
// @see canvas_post_update_0029_migrate_page_regions_to_variants()
$page_region_data = [
  'uuid' => 'e63caff9-05bb-478b-b447-c6f5e2f5f0ac',
  'langcode' => 'en',
  'status' => TRUE,
  'dependencies' => [
    'config' => ['canvas.component.block.system_powered_by_block'],
    'theme' => ['stark'],
  ],
  'id' => 'stark.sidebar_first',
  'theme' => 'stark',
  'region' => 'sidebar_first',
  'component_tree' => [
    '5d306ef8-0778-4bd2-adcf-59e764ee73d5' => [
      'uuid' => '5d306ef8-0778-4bd2-adcf-59e764ee73d5',
      'component_id' => 'block.system_powered_by_block',
      'component_version' => '3332388cade78d20',
      'inputs' => [
        'label' => '',
        'label_display' => '0',
      ],
    ],
  ],
];

$connection->insert('config')
  ->fields(['collection', 'name', 'data'])
  ->values([
    'collection' => '',
    'name' => 'canvas.page_region.stark.sidebar_first',
    'data' => serialize($page_region_data),
  ])
  ->execute();
