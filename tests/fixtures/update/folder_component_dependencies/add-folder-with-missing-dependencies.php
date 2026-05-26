<?php

/**
 * @file
 * Adds a code component and a folder referencing it, with missing dependencies.
 */

use Drupal\Core\Database\Database;

$connection = Database::getConnection();

// A minimal code component (JavaScriptComponent).
$js_component_data = [
  'uuid' => 'a1b2c3d4-e5f6-4890-ab12-ef1234567890',
  'langcode' => 'en',
  'status' => TRUE,
  'dependencies' => [],
  'machineName' => 'test-folder-update-component',
  'name' => 'Test folder update component',
  'props' => [],
  'required' => [],
  'slots' => [],
  'js' => ['original' => '', 'compiled' => ''],
  'css' => ['original' => '', 'compiled' => ''],
  'dataDependencies' => [],
];

$connection->insert('config')
  ->fields(['collection', 'name', 'data'])
  ->values([
    'collection' => '',
    'name' => 'canvas.js_component.test-folder-update-component',
    'data' => serialize($js_component_data),
  ])
  ->execute();

// A folder that references the code component above, but with empty
// dependencies: the `canvas.js_component.test-folder-update-component` config
// dependency is missing. This is the state before Folder::calculateDependencies()
// was introduced.
$folder_data = [
  'uuid' => 'b2c3d4e5-f6a7-4890-bc12-f12345678901',
  'langcode' => 'en',
  'status' => TRUE,
  'dependencies' => [],
  'name' => 'Test folder (update fixture)',
  'configEntityTypeId' => 'js_component',
  'weight' => 0,
  'items' => ['test-folder-update-component'],
];

$connection->insert('config')
  ->fields(['collection', 'name', 'data'])
  ->values([
    'collection' => '',
    'name' => 'canvas.folder.b2c3d4e5-f6a7-4890-bc12-f12345678901',
    'data' => serialize($folder_data),
  ])
  ->execute();
