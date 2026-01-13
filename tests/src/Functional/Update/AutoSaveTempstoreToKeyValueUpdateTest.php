<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Functional\Update;

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\Entity\Page;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;

/**
 * Tests migration of auto-save data from tempstore to key-value store.
 *
 * @covers canvas_post_update_0010_migrate_auto_save()
 * @group canvas
 */
final class AutoSaveTempstoreToKeyValueUpdateTest extends CanvasUpdatePathTestBase {

  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected function setDatabaseDumpFiles(): void {
    $this->databaseDumpFiles[] = \dirname(__DIR__, 3) . '/fixtures/update/drupal-11.2.2-with-canvas-1.0.0-alpha1.bare.php.gz';
  }

  /**
   * Tests auto-save data migration from tempstore to key-value store.
   */
  public function testAutoSaveMigration(): void {
    // Set up test data in tempstore before the update.
    $tempstore_factory = \Drupal::service('tempstore.shared');

    // Create test auto-save data for a page entity.
    $page = Page::load(1);
    \assert($page instanceof Page);
    $auto_save_key = 'canvas_page:1:en';
    $auto_save_data = [
      'entity_type' => 'canvas_page',
      'entity_id' => '1',
      'data' => [
        'title' => 'Test auto-save migration',
        'components' => [],
      ],
      'langcode' => 'en',
      'label' => 'Test auto-save migration',
      'data_hash' => 'test_hash_123',
      'client_id' => NULL,
    ];

    // Store in tempstore using set() which will wrap it properly.
    $tempstore = $tempstore_factory->get(AutoSaveManager::AUTO_SAVE_STORE);
    $tempstore->set($auto_save_key, $auto_save_data);

    // Create test form violations.
    $violations_key = 'canvas_page:1:en';
    $violations = new ConstraintViolationList([
      new ConstraintViolation(
        'Test violation message',
        NULL,
        [],
        NULL,
        'test.property',
        'invalid_value'
      ),
    ]);
    $violations_tempstore = $tempstore_factory->get(AutoSaveManager::FORM_VIOLATIONS_STORE);
    $violations_tempstore->set($violations_key, $violations);

    // Create test component instance form violations.
    $component_uuid = 'test-component-uuid-123';
    $component_violations = new ConstraintViolationList([
      new ConstraintViolation(
        'Component violation message',
        NULL,
        [],
        NULL,
        'component.property',
        'invalid_component_value'
      ),
    ]);
    $component_violations_tempstore = $tempstore_factory->get(AutoSaveManager::COMPONENT_INSTANCE_FORM_VIOLATIONS_STORE);
    $component_violations_tempstore->set($component_uuid, $component_violations);

    // Verify data exists in tempstore before update.
    $this->assertNotNull($tempstore->get($auto_save_key));
    $this->assertNotNull($violations_tempstore->get($violations_key));
    $this->assertNotNull($component_violations_tempstore->get($component_uuid));

    // Verify data does NOT exist in key-value store before update.
    $keyvalue_factory = \Drupal::service('keyvalue');
    \assert($keyvalue_factory instanceof KeyValueFactoryInterface);
    $keyvalue_store = $keyvalue_factory->get(AutoSaveManager::AUTO_SAVE_STORE);
    $this->assertNull($keyvalue_store->get($auto_save_key));

    // Run the update.
    $this->runUpdates();

    // Verify data was migrated to key-value store.
    $keyvalue_factory = \Drupal::service('keyvalue');
    $keyvalue_store = $keyvalue_factory->get(AutoSaveManager::AUTO_SAVE_STORE);
    $migrated_data = $keyvalue_store->get($auto_save_key);
    $this->assertNotNull($migrated_data);
    $this->assertIsArray($migrated_data);
    $this->assertSame($auto_save_data['entity_type'], $migrated_data['entity_type']);
    $this->assertSame($auto_save_data['entity_id'], $migrated_data['entity_id']);
    $this->assertSame($auto_save_data['label'], $migrated_data['label']);
    $this->assertSame($auto_save_data['data_hash'], $migrated_data['data_hash']);

    // Verify form violations were migrated.
    $violations_keyvalue = $keyvalue_factory->get(AutoSaveManager::FORM_VIOLATIONS_STORE);
    $migrated_violations = $violations_keyvalue->get($violations_key);
    $this->assertNotNull($migrated_violations);
    $this->assertInstanceOf(ConstraintViolationList::class, $migrated_violations);
    $this->assertCount(1, $migrated_violations);

    // Verify component instance form violations were migrated.
    $component_violations_keyvalue = $keyvalue_factory->get(AutoSaveManager::COMPONENT_INSTANCE_FORM_VIOLATIONS_STORE);
    $migrated_component_violations = $component_violations_keyvalue->get($component_uuid);
    $this->assertNotNull($migrated_component_violations);
    $this->assertInstanceOf(ConstraintViolationList::class, $migrated_component_violations);
    $this->assertCount(1, $migrated_component_violations);
  }

}
