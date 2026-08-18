<?php
use Drupal\canvas\AutoSave\AutoSaveManager;
$page = \Drupal::entityTypeManager()->getStorage('canvas_page')->load(6);
$auto = \Drupal::service(AutoSaveManager::class)->getAutoSaveEntity($page, TRUE);
$entity = $auto->isEmpty() ? $page : $auto->entity;
foreach ($entity->get('components')->getValue() as $v) {
  if (str_starts_with($v['component_id'] ?? '', 'views_list.')) {
    print "inputs raw: " . var_export($v['inputs'], TRUE) . "\n";
  }
}
