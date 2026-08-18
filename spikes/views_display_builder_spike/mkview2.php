<?php
// A view over watchdog: a base table with no entity type whatsoever.
// Nothing about this row can be expressed by a content template.
$storage = \Drupal::entityTypeManager()->getStorage('view');
if ($existing = $storage->load('poc_no_entity')) {
  $existing->delete();
}
$view = $storage->create([
  'id' => 'poc_no_entity',
  'label' => 'POC: non-entity rows',
  'base_table' => 'watchdog',
  'base_field' => 'wid',
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
        'pager' => ['type' => 'some', 'options' => ['items_per_page' => 5]],
        'style' => ['type' => 'default'],
        'row' => [
          'type' => 'canvas_component',
          'options' => [
            'component_id' => 'js.heading',
            'prop_map' => ['text' => 'type'],
          ],
        ],
        'fields' => [
          'type' => [
            'id' => 'type',
            'table' => 'watchdog',
            'field' => 'type',
            'plugin_id' => 'standard',
            'label' => '',
          ],
        ],
        'sorts' => [
          'wid' => [
            'id' => 'wid',
            'table' => 'watchdog',
            'field' => 'wid',
            'plugin_id' => 'standard',
            'order' => 'DESC',
          ],
        ],
      ],
    ],
    'page_1' => [
      'display_plugin' => 'page',
      'id' => 'page_1',
      'display_title' => 'Page',
      'position' => 1,
      'display_options' => ['path' => 'poc-no-entity'],
    ],
  ],
]);
$view->save();
print "view saved: poc_no_entity at /poc-no-entity (base table: watchdog, no entity type)\n";
