<?php

/**
 * @file
 * Adds region data the page-variant migration must convert or drop.
 *
 * Three scenarios on top of the enabled `stark.sidebar_first` region:
 * - a pending auto-save draft for that region, which the migration converts
 *   into one auto-saved draft on the `theme_stark` variant;
 * - a French config translation override for that region's component tree,
 *   which the migration copies onto the variant (item UUIDs are preserved by
 *   the conversion and are the override keys);
 * - an enabled region naming a region stark's info.yml does not declare, whose
 *   items would land in a nonexistent slot of the theme page template
 *   component and must therefore be dropped.
 *
 * cspell:ignore Propulsé
 *
 * @see canvas_post_update_0029_migrate_page_regions_to_variants()
 * @see tests/fixtures/update/page_variants/add-stark-page-region.php
 */

use Drupal\Core\Database\Database;

$connection = Database::getConnection();

// A pending auto-save draft for the enabled `stark.sidebar_first` region: the
// editor changed the block's label settings but never published. Mirrors the
// entry \Drupal\canvas\AutoSave\AutoSaveManager::saveEntity() writes.
$draft_region_data = [
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
        'label' => 'Powered by Drupal (draft)',
        'label_display' => 'visible',
      ],
    ],
  ],
];
$connection->insert('key_value')
  ->fields(['collection', 'name', 'value'])
  ->values([
    'collection' => 'canvas.auto_save',
    'name' => 'page_region:stark.sidebar_first',
    'value' => serialize([
      'entity_type' => 'page_region',
      'entity_id' => 'stark.sidebar_first',
      'data' => $draft_region_data,
      'langcode' => 'en',
      'is_default_translation' => TRUE,
      'label' => 'stark.sidebar_first',
      'original_hash' => 'fixture-original-hash',
      'data_hash' => 'fixture-data-hash',
      'client_id' => 'fixture-client-id',
      'owner' => 1,
      'updated' => 1752200000,
    ]),
  ])
  ->execute();

// A French translation override for the enabled region's component tree,
// keyed by item UUID.
$connection->insert('config')
  ->fields(['collection', 'name', 'data'])
  ->values([
    'collection' => 'language.fr',
    'name' => 'canvas.page_region.stark.sidebar_first',
    'data' => serialize([
      'component_tree' => [
        '5d306ef8-0778-4bd2-adcf-59e764ee73d5' => [
          'inputs' => [
            'label' => 'Propulsé par Drupal',
          ],
        ],
      ],
    ]),
  ])
  ->execute();

// An ENABLED region in the default theme naming a region stark's info.yml
// does not declare (e.g. left over from a theme update). The theme page
// template component has no matching slot, so its item must not enter the
// `theme_stark` variant.
$stale_region_data = [
  'uuid' => 'f4a5b6c7-d8e9-40f1-82a3-b4c5d6e7f8a9',
  'langcode' => 'en',
  'status' => TRUE,
  'dependencies' => [
    'config' => ['canvas.component.block.system_messages_block'],
    'theme' => ['stark'],
  ],
  'id' => 'stark.legacy_zone',
  'theme' => 'stark',
  'region' => 'legacy_zone',
  'component_tree' => [
    'c3d4e5f6-a7b8-491c-8bd2-e3f4a5b6c7d8' => [
      'uuid' => 'c3d4e5f6-a7b8-491c-8bd2-e3f4a5b6c7d8',
      'component_id' => 'block.system_messages_block',
      'component_version' => 'b92f802cf68eb83e',
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
    'name' => 'canvas.page_region.stark.legacy_zone',
    'data' => serialize($stale_region_data),
  ])
  ->execute();
