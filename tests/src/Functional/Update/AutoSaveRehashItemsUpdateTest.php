<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Functional\Update;

// cspell:ignore texte Bouton Contactez savoir offres Prêt collaborer Prendre rendez vous

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\JavaScriptComponent;
use Drupal\canvas\Entity\Page;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\FunctionalTests\Update\UpdatePathTestBase;
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

  /**
   * Tests an invalid auto-save item with PHP assertions enabled.
   *
   * Normalizing the invalid item reaches an `assert()` in
   * JsonSchemaPropsComponentSourceBase::optimizeExplicitInput(), which throws
   * an exception. The update must then fail with an actionable message.
   *
   * @see \Drupal\canvas\Plugin\Canvas\ComponentSource\JsonSchemaPropsComponentSourceBase::optimizeExplicitInput()
   */
  public function testRehashInvalidAutoSaveItemWithAssertionsEnabled(): void {
    [$invalid_page_auto_save_key] = self::createInvalidAndValidAutoSaveItems();

    // Run the updates without the update path test base's assertion that all
    // updates succeed, nor Canvas' doctor assertions after the updates.
    $this->checkFailedUpdates = FALSE;
    UpdatePathTestBase::runUpdates();

    $failure = $this->cssSelect('.failure');
    self::assertNotEmpty($failure);
    $message = reset($failure)->getText();
    self::assertStringContainsString(\sprintf('Auto-save item %s contains invalid data', $invalid_page_auto_save_key), $message);
    self::assertStringContainsString('Disable PHP assertions (zend.assertions) while running database updates per the https://www.drupal.org/docs/develop/drupal-apis/runtime-assertions best practices.', $message);
    self::assertStringContainsString('drush canvas:doctor --checks=auto_save --details', $message);
  }

  /**
   * Tests an invalid auto-save item with PHP assertions disabled.
   *
   * As on production sites: the invalid item is rehashed like any other item.
   */
  public function testRehashInvalidAutoSaveItemWithAssertionsDisabled(): void {
    [$invalid_page_auto_save_key, $valid_page_auto_save_key] = self::createInvalidAndValidAutoSaveItems();

    // Disable assertions for the requests to the site under test.
    file_put_contents(DRUPAL_ROOT . '/' . $this->siteDirectory . '/settings.php', "\nini_set('zend.assertions', '0');\n", FILE_APPEND);

    $this->runUpdates();

    // Re-obtain services after the update path rebuilds the container.
    $keyvalue_factory = \Drupal::service('keyvalue');
    \assert($keyvalue_factory instanceof KeyValueFactoryInterface);
    $after = $keyvalue_factory->get(AutoSaveManager::AUTO_SAVE_STORE)->getAll();
    self::assertArrayHasKey($invalid_page_auto_save_key, $after);
    self::assertArrayHasKey($valid_page_auto_save_key, $after);

    // Both items are rehashed.
    foreach ([$invalid_page_auto_save_key, $valid_page_auto_save_key] as $key) {
      self::assertNotSame('stale_data_hash', $after[$key]['data_hash']);
      self::assertNotSame('stale_original_hash', $after[$key][AutoSaveManager::AUTO_SAVE_STORED_ENTITY_HASH_KEY]);
    }

    // The invalid component instance is kept as-is.
    $component_instance = $after[$invalid_page_auto_save_key]['data']['components'][0];
    self::assertSame('8fe3be948e0194e1', $component_instance['component_version']);
    $inputs = \is_string($component_instance['inputs']) ? \json_decode($component_instance['inputs'], TRUE) : $component_instance['inputs'];
    self::assertSame(['texte', 'titre', 'urlDuBouton', 'texteDuBouton'], \array_keys($inputs));
  }

  /**
   * Creates an invalid and a valid auto-save item, both with stale hashes.
   *
   * Auto-saves may contain invalid data by design: only publishing requires
   * valid data. The invalid item has a component instance whose version has no
   * props, yet it has inputs for props. This is modeled after an auto-save
   * item on a production site.
   *
   * @return array{string, string}
   *   The auto-save keys of the invalid item and the valid item.
   */
  private static function createInvalidAndValidAutoSaveItems(): array {
    $keyvalue_factory = \Drupal::service('keyvalue');
    \assert($keyvalue_factory instanceof KeyValueFactoryInterface);
    $auto_save_store = $keyvalue_factory->get(AutoSaveManager::AUTO_SAVE_STORE);
    self::assertEmpty($auto_save_store->getAll(), 'Auto-save store must be empty before the test.');

    $user = User::load(2);
    \assert($user instanceof User);

    // A code component without props. Every code component without props or
    // slots gets the same version: 8fe3be948e0194e1.
    $js_component = JavaScriptComponent::create([
      'machineName' => 'cta-section',
      'name' => 'CTA Section',
      'status' => TRUE,
      'props' => [],
      'slots' => [],
      'js' => ['original' => '', 'compiled' => ''],
      'css' => ['original' => '', 'compiled' => ''],
      'dataDependencies' => [],
    ]);
    self::assertSame(SAVED_NEW, $js_component->save());
    $component = Component::load('js.cta-section');
    \assert($component instanceof Component);
    $propless_version = $component->getActiveVersion();
    self::assertSame('8fe3be948e0194e1', $propless_version);

    $valid_page = Page::create([
      'title' => 'Valid page',
      'status' => TRUE,
      'path' => ['alias' => '/valid-page'],
      'owner' => $user->id(),
    ]);
    self::assertSame([], self::violationsToArray($valid_page->validate()));
    self::assertSame(SAVED_NEW, $valid_page->save());
    $invalid_page = Page::create([
      'title' => 'Invalid page',
      'status' => TRUE,
      'path' => ['alias' => '/invalid-page'],
      'owner' => $user->id(),
    ]);
    self::assertSame([], self::violationsToArray($invalid_page->validate()));
    self::assertSame(SAVED_NEW, $invalid_page->save());

    // Hardcoded timestamp in case other update hooks re-save these pages.
    $updated = 1234567890;
    $valid_page->set('title', 'Valid page auto-save');
    $invalid_page->set('components', [
      [
        'uuid' => '2ea00085-6cb8-4fd9-888b-f580f69a99a6',
        'component_id' => 'js.cta-section',
        'component_version' => $propless_version,
        // Inputs for props that do not exist in this component version.
        'inputs' => [
          'texte' => 'Contactez-nous pour en savoir plus sur nos offres et services',
          'titre' => 'Prêt à collaborer!',
          'urlDuBouton' => 'http://www.google.fr',
          'texteDuBouton' => 'Prendre rendez-vous',
        ],
      ],
    ]);
    $invalid_page->set('title', 'Invalid page auto-save');
    self::assertNotSame([], self::violationsToArray($invalid_page->validate()));

    $valid_page_auto_save_key = AutoSaveManager::getAutoSaveKey($valid_page);
    $invalid_page_auto_save_key = AutoSaveManager::getAutoSaveKey($invalid_page);
    $auto_save_item = static fn (Page $page): array => [
      'entity_type' => $page->getEntityTypeId(),
      'entity_id' => $page->id(),
      'data' => $page->toArray(),
      'langcode' => $page->language()->getId(),
      'is_default_translation' => $page->isDefaultTranslation(),
      'label' => $page->label(),
      'data_hash' => 'stale_data_hash',
      AutoSaveManager::AUTO_SAVE_STORED_ENTITY_HASH_KEY => 'stale_original_hash',
      'owner' => (int) $page->getOwnerId(),
      'updated' => $updated,
      'client_id' => NULL,
    ];
    $auto_save_store->setMultiple([
      $invalid_page_auto_save_key => $auto_save_item($invalid_page),
      $valid_page_auto_save_key => $auto_save_item($valid_page),
    ]);
    $before = $auto_save_store->getAll();
    self::assertArrayHasKey($invalid_page_auto_save_key, $before);
    self::assertArrayHasKey($valid_page_auto_save_key, $before);

    return [$invalid_page_auto_save_key, $valid_page_auto_save_key];
  }

}
