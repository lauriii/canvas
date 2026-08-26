<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Functional\Update;

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\Entity\Page;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Tests\canvas\Traits\ConstraintViolationsTestTrait;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\CoversFunction;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests rehashing of auto-save items with the strengthened normalization.
 */
#[CoversFunction('canvas_post_update_0023_rehash_auto_save_items')]
#[RunTestsInSeparateProcesses]
#[Group('canvas')]
final class AutoSaveRehashItemsUpdateTest extends CanvasUpdatePathTestBase {

  use ConstraintViolationsTestTrait;

  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected function setDatabaseDumpFiles(): void {
    $this->databaseDumpFiles[] = \dirname(__DIR__, 3) . '/fixtures/update/drupal-11.2.2-with-canvas-1.0.0-alpha1.bare.php.gz';
  }

  /**
   * Tests that auto-save items are rehashed with the new normalization.
   *
   * Verifies three behaviors:
   * - Valid items: data_hash and original_hash are recomputed; other metadata
   *   (owner, updated, label, client_id, langcode, entity_type, entity_id) is
   *   left untouched.
   * - Items without the required entity_type/data/entity_id keys are skipped.
   * - Items whose entity type is no longer registered are skipped.
   */
  public function testRehashAutoSaveItems(): void {
    $keyvalue_factory = \Drupal::service('keyvalue');
    \assert($keyvalue_factory instanceof KeyValueFactoryInterface);
    $auto_save_store = $keyvalue_factory->get(AutoSaveManager::AUTO_SAVE_STORE);
    self::assertEmpty($auto_save_store->getAll(), 'Auto-save store must be empty before the test.');

    $user = User::load(2);
    \assert($user instanceof User);

    // Create two new page entities as the rehash targets:
    // - `$page1` tests auto-save item without original_hash update
    // - `$page2` tests auto-save item with original_hash update
    $page1 = Page::create([
      'title' => "Page 1",
      'status' => TRUE,
      'path' => ['alias' => "/page-1"],
      'owner' => $user->id(),
    ]);

    self::assertSame([], self::violationsToArray($page1->validate()));
    self::assertSame(SAVED_NEW, $page1->save());

    $page2 = Page::create([
      'title' => "Page 2",
      'status' => TRUE,
      'path' => ['alias' => "/page-2"],
      'owner' => $user->id(),
    ]);
    self::assertSame([], self::violationsToArray($page2->validate()));
    self::assertSame(SAVED_NEW, $page2->save());

    // Hardcoded timestamp in case other update hooks re-save this Page.
    $updated = 1234567890;
    // Make sure there's at least one actual change for each auto-save item.
    $page1->set('title', 'Test page 1 auto save with update 0023');
    $page2->set('title', 'Test page 2 auto save with update 0023');

    // Get auto-save item keys for the page entities.
    $page1_auto_save_key = AutoSaveManager::getAutoSaveKey($page1);
    $page2_auto_save_key = AutoSaveManager::getAutoSaveKey($page2);

    // Create two valid auto-save items with fake data_hash values, that will be
    // recalculated during the update process.
    // First item has no original_hash.
    $item_without_original_hash = [
      'entity_type' => $page1->getEntityTypeId(),
      'entity_id' => $page1->id(),
      'data' => $page1->toArray(),
      'langcode' => $page1->language()->getId(),
      'is_default_translation' => $page1->isDefaultTranslation(),
      'label' => $page1->label(),
      'data_hash' => 'stale_data_hash',
      'owner' => (int) $page1->getOwnerId(),
      'updated' => $updated,
      'client_id' => NULL,
    ];
    // Second item has original_hash.
    $item_with_original_hash = [
      'entity_type' => $page2->getEntityTypeId(),
      'entity_id' => $page2->id(),
      'data' => $page2->toArray(),
      'langcode' => $page2->language()->getId(),
      'is_default_translation' => $page2->isDefaultTranslation(),
      'label' => $page2->label(),
      'data_hash' => 'stale_data_hash',
      AutoSaveManager::AUTO_SAVE_STORED_ENTITY_HASH_KEY => 'stale_original_hash',
      'owner' => (int) $page2->getOwnerId(),
      'updated' => $updated,
      'client_id' => NULL,
    ];

    // Store valid auto-save items with stale hashes before the update.
    $auto_save_store->setMultiple([
      $page1_auto_save_key => $item_without_original_hash,
      $page2_auto_save_key => $item_with_original_hash,
    ]);

    $before = $auto_save_store->getAll();
    self::assertArrayHasKey($page1_auto_save_key, $before);
    self::assertArrayHasKey($page2_auto_save_key, $before);

    // Confirm stale data_hash is present and original_hash is absent before the
    // update for the page 1 auto-save item.
    self::assertArrayHasKey('data_hash', $before[$page1_auto_save_key]);
    self::assertSame($item_without_original_hash['data_hash'], $before[$page1_auto_save_key]['data_hash']);
    self::assertArrayNotHasKey(AutoSaveManager::AUTO_SAVE_STORED_ENTITY_HASH_KEY, $before[$page1_auto_save_key]);

    // Confirm stale data_hash and original_hash are present before the update
    // for the page 2 auto-save item.
    self::assertArrayHasKey('data_hash', $before[$page2_auto_save_key]);
    self::assertSame($item_with_original_hash['data_hash'], $before[$page2_auto_save_key]['data_hash']);
    self::assertArrayHasKey(AutoSaveManager::AUTO_SAVE_STORED_ENTITY_HASH_KEY, $before[$page2_auto_save_key]);
    self::assertSame($item_with_original_hash[AutoSaveManager::AUTO_SAVE_STORED_ENTITY_HASH_KEY], $before[$page2_auto_save_key][AutoSaveManager::AUTO_SAVE_STORED_ENTITY_HASH_KEY]);

    // Run all pending updates (includes 0023).
    $this->runUpdates();

    // Re-obtain services after the update path rebuilds the container.
    $keyvalue_factory = \Drupal::service('keyvalue');
    \assert($keyvalue_factory instanceof KeyValueFactoryInterface);
    $auto_save_store = $keyvalue_factory->get(AutoSaveManager::AUTO_SAVE_STORE);

    $after = $auto_save_store->getAll();
    self::assertIsArray($after);
    self::assertArrayHasKey($page1_auto_save_key, $after);
    self::assertArrayHasKey($page2_auto_save_key, $after);

    // Stale data_hash must be recomputed for both auto-save items.
    foreach ([$page1_auto_save_key, $page2_auto_save_key] as $key) {
      self::assertArrayHasKey('data_hash', $after[$key]);
      self::assertNotSame($before[$key]['data_hash'], $after[$key]['data_hash'], 'data_hash must be recomputed by the rehash update.');
      self::assertNotEmpty($after[$key]['data_hash']);
    }

    // Update hook computed original_hash for the page 1 auto-save item, which
    // did not have it before.
    self::assertArrayHasKey(AutoSaveManager::AUTO_SAVE_STORED_ENTITY_HASH_KEY, $after[$page1_auto_save_key]);
    self::assertNotEmpty($after[$page1_auto_save_key][AutoSaveManager::AUTO_SAVE_STORED_ENTITY_HASH_KEY], 'original_hash must be recomputed by the rehash update.');

    // Update hook recomputed original_hash for the page 2 auto-save item.
    self::assertArrayHasKey(AutoSaveManager::AUTO_SAVE_STORED_ENTITY_HASH_KEY, $after[$page2_auto_save_key]);
    self::assertNotSame($before[$page2_auto_save_key][AutoSaveManager::AUTO_SAVE_STORED_ENTITY_HASH_KEY], $after[$page2_auto_save_key][AutoSaveManager::AUTO_SAVE_STORED_ENTITY_HASH_KEY], 'original_hash must be recomputed by the rehash update.');
    self::assertNotEmpty($after[$page2_auto_save_key][AutoSaveManager::AUTO_SAVE_STORED_ENTITY_HASH_KEY], 'original_hash must be recomputed by the rehash update.');
  }

}
