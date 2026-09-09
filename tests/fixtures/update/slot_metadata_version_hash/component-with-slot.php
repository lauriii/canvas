<?php

/**
 * @file
 * Seeds a Component config entity whose version hash includes slot metadata.
 *
 * A code component with a single `content` slot. The stored `active_version`
 * (`800f65a3ec61c647`) was computed while the slot's `title` and first
 * `examples` entry were part of the hashed data, so it no longer matches the
 * hash recomputed from the slot name alone (`9226f2d36be768b4`) — exactly the
 * state a pre-fix install ends up in.
 *
 * @see \Drupal\Tests\canvas\Functional\Update\SlottedComponentVersionHashUpdateTest
 * @see \canvas_post_update_0031_recompute_slotted_component_version_hashes()
 */

use Drupal\Core\Database\Database;

$connection = Database::getConnection();

// The code component providing the slot.
$js_component = [
  'uuid' => '68c2565a-f845-4c9f-a49f-8a7f62837dd4',
  'langcode' => 'en',
  'status' => TRUE,
  'dependencies' => [],
  'machineName' => 'slotted_component',
  'name' => 'Slotted component',
  'required' => [],
  'props' => [],
  'slots' => [
    'content' => [
      'title' => 'Content',
      'description' => 'Content to display below the greeting',
      'examples' => ['<div>Example slot content</div>'],
    ],
  ],
  'js' => [
    'original' => 'console.log("hey");',
    'compiled' => 'console.log("hey");',
  ],
  'css' => [
    'original' => '',
    'compiled' => '',
  ],
  'dataDependencies' => [],
];

// The Component config entity, with the pre-fix `active_version`.
$component = [
  'uuid' => '513683bb-21de-4cb9-868e-02b13524a1db',
  'langcode' => 'en',
  'status' => TRUE,
  'dependencies' => [
    'config' => [
      'canvas.js_component.slotted_component',
    ],
  ],
  'active_version' => '800f65a3ec61c647',
  'versioned_properties' => [
    'active' => [
      'settings' => [
        'prop_field_definitions' => [],
      ],
      'fallback_metadata' => [
        'slot_definitions' => [
          'content' => [
            'title' => 'Content',
            'description' => 'Content to display below the greeting',
            'examples' => ['<div>Example slot content</div>'],
          ],
        ],
      ],
    ],
  ],
  'label' => 'Slotted component',
  'id' => 'js.slotted_component',
  'provider' => NULL,
  'source' => 'js',
  'source_local_id' => 'slotted_component',
];

$connection->insert('config')
  ->fields(['collection', 'name', 'data'])
  ->values([
    'collection' => '',
    'name' => 'canvas.js_component.slotted_component',
    'data' => \serialize($js_component),
  ])
  ->values([
    'collection' => '',
    'name' => 'canvas.component.js.slotted_component',
    'data' => \serialize($component),
  ])
  ->execute();
