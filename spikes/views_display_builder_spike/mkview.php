<?php
// A Fields-row view: rows are built from field handlers, not from an entity.
$storage = \Drupal::entityTypeManager()->getStorage('view');
$existing = $storage->load('poc_fields_rows');
if ($existing) {
  $existing->delete();
}
$view = $storage->create([
  'id' => 'poc_fields_rows',
  'label' => 'POC: fields rows',
  'base_table' => 'node_field_data',
  'base_field' => 'nid',
  'display' => [
    'default' => [
      'display_plugin' => 'default',
      'id' => 'default',
      'display_title' => 'Default',
      'position' => 0,
      'display_options' => [
        'access' => ['type' => 'none'],
        'cache' => ['type' => 'tag'],
        'query' => ['type' => 'views_query'],
        'exposed_form' => ['type' => 'basic'],
        'pager' => ['type' => 'some', 'options' => ['items_per_page' => 10]],
        'style' => ['type' => 'default'],
        'row' => [
          'type' => 'canvas_component',
          'options' => [
            'component_id' => 'js.heading',
            'prop_map' => ['text' => 'title'],
          ],
        ],
        'fields' => [
          'title' => [
            'id' => 'title',
            'table' => 'node_field_data',
            'field' => 'title',
            'plugin_id' => 'field',
            'entity_type' => 'node',
            'entity_field' => 'title',
            'label' => '',
          ],
        ],
        'filters' => [
          'type' => [
            'id' => 'type',
            'table' => 'node_field_data',
            'field' => 'type',
            'plugin_id' => 'bundle',
            'entity_type' => 'node',
            'entity_field' => 'type',
            'value' => ['article' => 'article'],
          ],
        ],
        'sorts' => [],
      ],
    ],
    'page_1' => [
      'display_plugin' => 'page',
      'id' => 'page_1',
      'display_title' => 'Page',
      'position' => 1,
      'display_options' => ['path' => 'poc-fields-rows'],
    ],
  ],
]);
$view->save();
print "view saved: poc_fields_rows at /poc-fields-rows\n";
