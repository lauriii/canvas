<?php

/**
 * @file
 * Adds page regions the page-variant migration must not convert.
 *
 * The migration only converts the default theme's enabled regions, because
 * those are the only ones the live site rendered. This fixture covers the two
 * things it must skip: a disabled region in the default theme, and an enabled
 * region in a non-default theme.
 *
 * @see canvas_post_update_0029_migrate_page_regions_to_variants()
 */

use Drupal\Core\Database\Database;

$connection = Database::getConnection();

// A disabled region in the default theme (stark), holding one block component
// instance distinct from the enabled region's block. It must not leak into the
// `theme_stark` variant built from the theme's enabled region.
// `block.system_messages_block` ships in the fixture database dump with active
// version b92f802cf68eb83e.
$disabled_stark_region = [
  'uuid' => 'c2a1f0d4-8e77-4a9b-9f3c-1b2d3e4f5a6b',
  'langcode' => 'en',
  'status' => FALSE,
  'dependencies' => [
    'config' => ['canvas.component.block.system_messages_block'],
    'theme' => ['stark'],
  ],
  'id' => 'stark.header',
  'theme' => 'stark',
  'region' => 'header',
  'component_tree' => [
    'a1b2c3d4-e5f6-47a8-99b0-c1d2e3f4a5b6' => [
      'uuid' => 'a1b2c3d4-e5f6-47a8-99b0-c1d2e3f4a5b6',
      'component_id' => 'block.system_messages_block',
      'component_version' => 'b92f802cf68eb83e',
      'inputs' => [
        'label' => '',
        'label_display' => '0',
      ],
    ],
  ],
];

// An ENABLED region in a theme (claro) that is installed but not the default.
// It rendered nowhere on the live site (only the active/default theme's regions
// did), so the migration must create no `theme_claro` variant and must not
// promote it to the site default.
$enabled_claro_region = [
  'uuid' => 'd3e4f5a6-b7c8-49d0-a1e2-f3a4b5c6d7e8',
  'langcode' => 'en',
  'status' => TRUE,
  'dependencies' => [
    'config' => ['canvas.component.block.system_messages_block'],
    'theme' => ['claro'],
  ],
  'id' => 'claro.sidebar_first',
  'theme' => 'claro',
  'region' => 'sidebar_first',
  'component_tree' => [
    'b2c3d4e5-f6a7-48b9-8ac1-d2e3f4a5b6c7' => [
      'uuid' => 'b2c3d4e5-f6a7-48b9-8ac1-d2e3f4a5b6c7',
      'component_id' => 'block.system_messages_block',
      'component_version' => 'b92f802cf68eb83e',
      'inputs' => [
        'label' => '',
        'label_display' => '0',
      ],
    ],
  ],
];

$insert = $connection->insert('config')
  ->fields(['collection', 'name', 'data']);
foreach ([$disabled_stark_region, $enabled_claro_region] as $region_data) {
  $insert->values([
    'collection' => '',
    'name' => 'canvas.page_region.' . $region_data['id'],
    'data' => serialize($region_data),
  ]);
}
$insert->execute();
