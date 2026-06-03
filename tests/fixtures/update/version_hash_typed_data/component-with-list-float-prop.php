<?php

/**
 * @file
 * Seeds a Component config entity whose version hash predates typed-data casting.
 *
 * The `level` prop is a `number` + `enum`, which Canvas maps to the
 * `list_float` field type. Its `field.value.list_float.value` is typed `string`
 * in core, so the default value `2` is cast to the string `"2"`. The stored
 * `active_version` (`e5103d546cfaa008`) was computed from the un-cast native
 * integer, so it no longer matches the hash recomputed from the cast value
 * (`871c4e77625ab1d1`) — exactly the state a pre-fix install ends up in.
 *
 * @see \Drupal\Tests\canvas\Functional\Update\ComponentVersionHashTypedDataCastUpdateTest
 * @see \canvas_post_update_0019_recompute_list_float_component_version_hashes()
 */

use Drupal\Core\Database\Database;

$connection = Database::getConnection();

// Enable the test module that provides the `heading` SDC, so the component
// source (and thus its metadata) is discoverable when the update runs.
$extension = $connection->select('config', 'c')
  ->fields('c', ['data'])
  ->condition('collection', '')
  ->condition('name', 'core.extension')
  ->execute()
  ->fetchField();
$extension = \unserialize($extension);
$extension['module']['canvas_test_list_float'] = 0;
// Keep the module list sorted by weight then name, as core does.
\uksort($extension['module'], static fn(string $a, string $b): int => [$extension['module'][$a], $a] <=> [$extension['module'][$b], $b]);
$connection->update('config')
  ->fields(['data' => \serialize($extension)])
  ->condition('collection', '')
  ->condition('name', 'core.extension')
  ->execute();

// Record the module's installed schema version (no hook_update_N == 8000).
$connection->merge('key_value')
  ->keys([
    'collection' => 'system.schema',
    'name' => 'canvas_test_list_float',
  ])
  ->fields(['value' => \serialize(8000)])
  ->execute();

// The Component config entity, with the pre-fix (incorrect) `active_version`.
$component = [
  'uuid' => 'e4e591ec-95eb-4ae6-9bae-fbc38b128992',
  'langcode' => 'en',
  'status' => TRUE,
  'dependencies' => [
    'module' => [
      'canvas_test_list_float',
      'options',
    ],
  ],
  'active_version' => 'e5103d546cfaa008',
  'versioned_properties' => [
    'active' => [
      'settings' => [
        'prop_field_definitions' => [
          'text' => [
            'required' => TRUE,
            'field_type' => 'string',
            'field_storage_settings' => [],
            'field_instance_settings' => [],
            'field_widget' => 'string_textfield',
            'default_value' => [0 => ['value' => 'Hello, world!']],
            'expression' => 'ℹ︎string␟value',
          ],
          'level' => [
            'required' => TRUE,
            'field_type' => 'list_float',
            'field_storage_settings' => [
              'allowed_values_function' => 'canvas_load_allowed_values_for_component_prop',
            ],
            'field_instance_settings' => [],
            'field_widget' => 'options_select',
            // This stored string `'2'` is the culprit: it is what an exported
            // YAML config entity round-trips the default through, whereas the
            // SDC `examples` default is generated in PHP as the native int `2`.
            // The pre-fix `active_version` above hashed the int; validation
            // recomputes from this string — hence the mismatch this fixture
            // reproduces.
            'default_value' => [0 => ['value' => '2']],
            'expression' => 'ℹ︎list_float␟value',
          ],
        ],
      ],
      'fallback_metadata' => [
        'slot_definitions' => [],
      ],
    ],
  ],
  'label' => 'Heading',
  'id' => 'sdc.canvas_test_list_float.heading',
  'provider' => 'canvas_test_list_float',
  'source' => 'sdc',
  'source_local_id' => 'canvas_test_list_float:heading',
];
$connection->insert('config')
  ->fields(['collection', 'name', 'data'])
  ->values([
    'collection' => '',
    'name' => 'canvas.component.sdc.canvas_test_list_float.heading',
    'data' => \serialize($component),
  ])
  ->execute();
