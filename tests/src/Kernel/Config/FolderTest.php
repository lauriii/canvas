<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\Config;

use Drupal\canvas\ComponentSource\ComponentSourceManager;
use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\Folder;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\ConfigTestTrait;
use Drupal\Tests\canvas\Traits\ContribStrictConfigSchemaTestTrait;

class FolderTest extends KernelTestBase {

  use ConfigTestTrait;
  use ContribStrictConfigSchemaTestTrait;

  protected Folder $entity;

  protected static $modules = [
    'canvas',
    'sdc',
    // Canvas's dependencies (modules providing field types + widgets).
    'datetime',
    'file',
    'image',
    'options',
    'path',
    'link',
    'system',
    'user',
    'text',
    'filter',
    'ckeditor5',
    'editor',
  ];

  protected function setUp(): void {
    parent::setUp();
    $this->installConfig('canvas');
    $this->entity = Folder::create([
      'name' => 'Test folder, please ignore',
      'configEntityTypeId' => Component::ENTITY_TYPE_ID,
    ]);
    $this->entity->save();
  }

  public function testFolderAutoCreationValidation(): void {
    $folders = Folder::loadMultiple();
    // 1. At the start, only the ::setUp()-created Folder exists.
    $this->assertEquals([$this->entity->id()], array_keys($folders));
    $this->enableModules(['canvas_test_sdc']);

    // 2. Generate Component config entities, this will create additional Folder
    // entities.
    $this->container->get(ComponentSourceManager::class)->generateComponents();
    $folders = Folder::loadMultiple();

    // 3. Folder created during ::setUp() still exists.
    $this->assertArrayHasKey($this->entity->id(), $folders);

    // 4. It is not the only Folder entity anymore.
    $folder_labels = \array_map(fn (Folder $folder) => $folder->label(), \array_values($folders));
    \sort($folder_labels);
    $this->assertEquals([
      'Atom/Media',
      'Atom/Tabs',
      'Atom/Text',
      'Container',
      'Container/Special',
      'Forms',
      'Other',
      'Status',
      'System',
      'Test folder, please ignore',
      'core',
    ], $folder_labels);

    // 5. Delete all folders and regenerate Components. As no new Components
    // will be created, no Folder entities will be created either.
    foreach ($folders as $folder) {
      $folder->delete();
    }
    $this->container->get(ComponentSourceManager::class)->generateComponents();
    $folders = Folder::loadMultiple();

    $folder_labels = \array_map(fn (Folder $folder) => $folder->label(), \array_values($folders));
    \sort($folder_labels);
    $this->assertEquals([], $folder_labels);

    // 6. Delete all Components and folders and regenerate Components. As new
    // Components will created, Folder entities matching Component default
    // folder values will be created.
    foreach ($folders as $folder) {
      $folder->delete();
    }
    foreach (Component::loadMultiple() as $component) {
      $component->delete();
    }

    $this->container->get(ComponentSourceManager::class)->generateComponents();
    $folders = Folder::loadMultiple();

    $folder_labels = \array_map(fn (Folder $folder) => $folder->label(), \array_values($folders));
    \sort($folder_labels);
    $this->assertEquals([
      'Atom/Media',
      'Atom/Tabs',
      'Atom/Text',
      'Container',
      'Container/Special',
      'Forms',
      'Other',
      'Status',
      'System',
      'core',
    ], $folder_labels);
  }

  public function testComponentDeletionModifiesFolder(): void {
    $this->enableModules(['canvas_test_sdc']);
    $this->container->get(ComponentSourceManager::class)->generateComponents();

    // 1. Load Folder that contains 'sdc.canvas_test_sdc.card'.
    $original_folder = Folder::loadByItemAndConfigEntityTypeId('sdc.canvas_test_sdc.card', Component::ENTITY_TYPE_ID);
    \assert($original_folder instanceof Folder);

    // 2. Remove 'sdc.canvas_test_sdc.card' from its default Folder.
    $original_folder->removeItem('sdc.canvas_test_sdc.card')->save();

    // 3. Add 'sdc.canvas_test_sdc.card' to our test Folder.
    $this->entity->addItems(['sdc.canvas_test_sdc.card'])->save();
    $this->assertEquals(['sdc.canvas_test_sdc.card'], $this->entity->get('items'));

    // 4. Delete Component entity, this should remove it from test Folder.
    Component::load('sdc.canvas_test_sdc.card')?->delete();
    $this->assertEquals([], Folder::load($this->entity->id())?->get('items'));

    // 5. Delete another Component from its default Folder, this should remove
    // the Component from Folder items list.
    $default_folder_id = Folder::loadByItemAndConfigEntityTypeId('sdc.canvas_test_sdc.attributes', Component::ENTITY_TYPE_ID);
    \assert($default_folder_id instanceof Folder);
    Component::load('sdc.canvas_test_sdc.attributes')?->delete();
    $this->assertNotContains('sdc.canvas_test_sdc.attributes', Folder::load($default_folder_id->id())?->get('items'));
  }

}
