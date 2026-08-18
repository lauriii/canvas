<?php
use Drupal\canvas\Entity\Pattern;

$page = \Drupal::entityTypeManager()->getStorage('canvas_page')->load(7);
$vl_uuid = 'aaaaaaaa-1111-2222-3333-bbbbbbbbbbbb';
$heading = \Drupal\canvas\Entity\Component::load('js.heading');
$tree = [
  [
    'uuid' => $vl_uuid,
    'component_id' => 'views_list.poc_fields_rows.page_1',
    'component_version' => \Drupal\canvas\Entity\Component::load('views_list.poc_fields_rows.page_1')->getActiveVersion(),
    'inputs' => ['bindings' => ['cccccccc-1111-2222-3333-dddddddddddd' => ['text' => 'title']]],
  ],
  [
    'uuid' => 'cccccccc-1111-2222-3333-dddddddddddd',
    'component_id' => 'js.heading',
    'component_version' => $heading->getActiveVersion(),
    'parent_uuid' => $vl_uuid,
    'slot' => 'item_template',
    'inputs' => ['text' => ['sourceType' => 'static:field_item:string', 'value' => 'STATIC PLACEHOLDER', 'expression' => 'ℹ︎string␟value']],
  ],
];
$vehicle = Pattern::create(['id' => 'smoke', 'label' => 'smoke', 'component_tree' => $tree]);
$build = $vehicle->getComponentTree()->toRenderable($vehicle, FALSE);
$html = (string) \Drupal::service('renderer')->renderInIsolation($build);
print "LENGTH: " . strlen($html) . "\n";
print "islands: " . substr_count($html, '<canvas-island') . "\n";
preg_match_all('/text&quot;:\[&quot;raw&quot;,&quot;([^&]{0,60})/', $html, $m);
foreach ($m[1] as $t) { print "row text: $t\n"; }
