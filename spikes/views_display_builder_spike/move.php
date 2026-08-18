<?php
use Drupal\canvas\AutoSave\AutoSaveManager;

$page = \Drupal::entityTypeManager()->getStorage('canvas_page')->load(6);
$asm = \Drupal::service(AutoSaveManager::class);
$auto = $asm->getAutoSaveEntity($page, TRUE);
$entity = $auto->isEmpty() ? $page : $auto->entity;
$items = $entity->get('components')->getValue();
print "draft items: " . count($items) . "\n";
$vl_uuid = NULL;
foreach ($items as $v) {
  if (str_starts_with($v['component_id'] ?? '', 'views_list.')) { $vl_uuid = $v['uuid']; }
}
print "views_list uuid: $vl_uuid\n";
$heading_uuids = [];
foreach ($items as $i => $v) {
  if (($v['component_id'] ?? '') === 'js.heading' && ($v['parent_uuid'] ?? NULL) === NULL) { $heading_uuids[$i] = $v['uuid']; }
}
print "top-level headings: " . json_encode(array_values($heading_uuids)) . "\n";
if ($vl_uuid && count($heading_uuids) >= 1) {
  $idxs = array_keys($heading_uuids);
  // Move the first into the slot, drop any extras.
  $first = array_shift($idxs);
  $items[$first]['parent_uuid'] = $vl_uuid;
  $items[$first]['slot'] = 'item_template';
  foreach ($idxs as $i) { unset($items[$i]); }
  $entity->set('components', array_values($items));
  $asm->saveEntity($entity);
  print "moved heading " . $heading_uuids[$first] . " into item_template; removed " . count($idxs) . " duplicate(s)\n";
}
