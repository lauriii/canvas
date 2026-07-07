<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\AutoSave;

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\AutoSave\Workspace\AutoSaveRevisionPruner;
use Drupal\canvas\AutoSave\Workspace\AutoSaveSnapshotRepository;
use Drupal\canvas\AutoSave\Workspace\AutoSaveWorkspace;
use Drupal\canvas\AutoSave\Workspace\CanvasWorkspaceProvider;
use Drupal\canvas\AutoSave\Workspace\PendingContentAutoSaveBuffer;
use Drupal\canvas\Entity\CanvasAutoSaveSnapshot;
use Drupal\entity_test\Entity\EntityTest;
use Drupal\entity_test\Entity\EntityTestMulRevPub;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\path_alias\Entity\PathAlias;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\Entity\User;
use Drupal\workspaces\Entity\Workspace;
use Drupal\workspaces\WorkspacePublishException;
use PHPUnit\Framework\Attributes\Group;

/**
 * Workspace-backed auto-save staging invariants.
 *
 * @coversDefaultClass \Drupal\canvas\AutoSave\Workspace\WorkspaceAutoSave
 */
#[Group('canvas')]
#[Group('canvas_auto_save')]
final class WorkspaceAutoSaveStagingTest extends CanvasKernelTestBase {

  use UserCreationTrait;

  protected static $modules = [
    'field',
    'entity_test',
    'language',
    'path_alias',
  ];

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('path_alias');
    $this->installEntitySchema('user');
    $this->installEntitySchema('entity_test');
    $this->installEntitySchema('entity_test_mulrevpub');
    $this->installEntitySchema(CanvasAutoSaveSnapshot::ENTITY_TYPE_ID);

    $account = $this->createUser([
      'administer workspaces',
      'view any workspace',
      'edit any workspace',
    ]);
    self::assertInstanceOf(User::class, $account);
    $this->setCurrentUser($account);

    Workspace::create([
      'id' => AutoSaveWorkspace::ID,
      'label' => AutoSaveWorkspace::LABEL,
      'uid' => (int) $account->id(),
      'provider' => CanvasWorkspaceProvider::getId(),
    ])->save();
  }

  private function autoSaveManager(): AutoSaveManager {
    $manager = $this->container->get(AutoSaveManager::class);
    self::assertInstanceOf(AutoSaveManager::class, $manager);
    return $manager;
  }

  private function trackedRevisionCount(string $entity_type_id, string $entity_id): int {
    $tracked = $this->container->get('workspaces.tracker')
      ->getTrackedEntities(AutoSaveWorkspace::ID, $entity_type_id, [$entity_id]);
    return \count($tracked[$entity_type_id] ?? []);
  }

  /**
   * All revisions of an entity, live and pending.
   *
   * The workspace association tracks only the newest pending revision, so it
   * cannot measure revision churn.
   */
  private function revisionCount(string $entity_type_id, string $entity_id): int {
    return (int) $this->container->get('entity_type.manager')->getStorage($entity_type_id)
      ->getQuery()
      ->allRevisions()
      ->condition('id', $entity_id)
      ->count()
      ->accessCheck(FALSE)
      ->execute();
  }

  /**
   * An identical retry from the same client must not touch staged state.
   */
  public function testIdenticalPayloadRetryIsANoOp(): void {
    $entity = EntityTestMulRevPub::create(['name' => 'live', 'status' => TRUE]);
    $entity->save();
    $manager = $this->autoSaveManager();

    $id = (string) $entity->id();
    $baseline = $this->revisionCount('entity_test_mulrevpub', $id);

    $draft = clone $entity;
    $draft->set('name', 'draft one');
    $manager->saveEntity($draft, 'client-a');
    self::assertSame($baseline + 1, $this->revisionCount('entity_test_mulrevpub', $id));
    self::assertSame(1, $this->trackedRevisionCount('entity_test_mulrevpub', $id));

    // Simulate a client retry: rebuild the draft from the staged state (the
    // base a retrying client works from) and re-send the identical payload.
    $staged = $manager->getEntityForLayoutEditing($entity);
    $manager->saveEntity($staged, 'client-a');
    self::assertSame($baseline + 1, $this->revisionCount('entity_test_mulrevpub', $id), 'An identical retry does not create another staged revision.');
    self::assertFalse($manager->getAutoSaveEntity($entity)->isEmpty(), 'The staged draft survives the retry.');

    // A genuinely different payload stages a new revision.
    $staged = $manager->getEntityForLayoutEditing($entity);
    $changed = clone $staged;
    $changed->set('name', 'draft two');
    $manager->saveEntity($changed, 'client-a');
    self::assertSame($baseline + 2, $this->revisionCount('entity_test_mulrevpub', $id));
    $staged = $manager->getAutoSaveEntity($entity)->entity;
    self::assertInstanceOf(EntityTestMulRevPub::class, $staged);
    self::assertSame('draft two', $staged->get('name')->value);
  }

  /**
   * Drafts the storage layer rejects as revisions fall back to snapshots.
   */
  public function testUnstorableDraftFallsBackToReadableSnapshot(): void {
    // Plain entity_test is neither revisionable nor publishable, so core
    // Workspaces refuses to save it while a workspace is active.
    $entity = EntityTest::create(['name' => 'live']);
    $entity->save();
    $manager = $this->autoSaveManager();

    $draft = clone $entity;
    $draft->set('name', 'unstorable draft');
    $manager->saveEntity($draft, 'client-a');

    $snapshot = $this->container->get(AutoSaveSnapshotRepository::class)
      ->resolveLatestStaged('entity_test', (string) $entity->id(), $entity->language()->getId());
    self::assertNotNull($snapshot, 'The draft was retained as a snapshot row.');

    $auto_save = $manager->getAutoSaveEntity($entity);
    self::assertFalse($auto_save->isEmpty(), 'Snapshot-staged drafts are readable for any entity type.');
    self::assertInstanceOf(EntityTest::class, $auto_save->entity);
    self::assertSame('unstorable draft', $auto_save->entity->get('name')->value);

    // The pending list contains the snapshot-backed entry.
    $list = $manager->getAllAutoSaveList(FALSE, FALSE);
    self::assertArrayHasKey(AutoSaveManager::getAutoSaveKey($entity), $list);

    // Discarding removes the snapshot row.
    $manager->delete($entity);
    self::assertTrue($manager->getAutoSaveEntity($entity)->isEmpty());
  }

  /**
   * Deleting all auto-saves clears every staging store.
   */
  public function testDeleteAllClearsWorkspaceTracking(): void {
    $manager = $this->autoSaveManager();

    // One workspace-staged draft.
    $revisionable = EntityTestMulRevPub::create(['name' => 'live', 'status' => TRUE]);
    $revisionable->save();
    $draft = clone $revisionable;
    $draft->set('name', 'staged');
    $manager->saveEntity($draft);
    self::assertSame(1, $this->trackedRevisionCount('entity_test_mulrevpub', (string) $revisionable->id()));

    // One snapshot-staged draft.
    $unstorable = EntityTest::create(['name' => 'live']);
    $unstorable->save();
    $snapshot_draft = clone $unstorable;
    $snapshot_draft->set('name', 'staged');
    $manager->saveEntity($snapshot_draft);

    self::assertNotSame([], $manager->getAllAutoSaveList(FALSE, FALSE));

    $manager->deleteAll();

    self::assertSame([], $manager->getAllAutoSaveList(FALSE, FALSE), 'No pending entries remain after deleteAll().');
    self::assertSame(0, $this->trackedRevisionCount('entity_test_mulrevpub', (string) $revisionable->id()), 'Workspace tracking is discarded by deleteAll().');
    $pruner_state = $this->container->get('keyvalue')->get(AutoSaveRevisionPruner::STORE)->getAll();
    self::assertSame([], $pruner_state, 'Pruner bookkeeping is discarded by deleteAll().');
  }

  /**
   * Staged translations are listed per language with per-language hashes.
   */
  public function testPerTranslationPendingEntries(): void {
    ConfigurableLanguage::createFromLangcode('fr')->save();
    $manager = $this->autoSaveManager();

    $entity = EntityTestMulRevPub::create(['name' => 'english live', 'status' => TRUE]);
    $entity->addTranslation('fr', ['name' => 'french live']);
    $entity->save();

    $en_draft = clone $entity;
    $en_draft->set('name', 'english draft');
    $manager->saveEntity($en_draft);

    $staged = $manager->getEntityForLayoutEditing($entity);
    $fr_draft = (clone $staged)->getTranslation('fr');
    $fr_draft->set('name', 'french draft');
    $manager->saveEntity($fr_draft);

    $list = $manager->getAllAutoSaveList(FALSE, FALSE);
    $id = $entity->id();
    self::assertArrayHasKey("entity_test_mulrevpub:$id:en", $list);
    self::assertArrayHasKey("entity_test_mulrevpub:$id:fr", $list);
    self::assertNotSame($list["entity_test_mulrevpub:$id:en"]['data_hash'], $list["entity_test_mulrevpub:$id:fr"]['data_hash']);
    self::assertSame('en', $list["entity_test_mulrevpub:$id:en"]['langcode']);
    self::assertSame('fr', $list["entity_test_mulrevpub:$id:fr"]['langcode']);
  }

  /**
   * Deferred buffer rows survive a missed terminate and flush on read.
   */
  public function testDeferredBufferFlushesOnRead(): void {
    \putenv('CANVAS_TEST_FORCE_DEFER_AUTOSAVE=1');
    try {
      $entity = EntityTestMulRevPub::create(['name' => 'live', 'status' => TRUE]);
      $entity->save();
      $manager = $this->autoSaveManager();

      $draft = clone $entity;
      $draft->set('name', 'buffered draft');
      $manager->saveEntity($draft, 'client-a');

      // The write is buffered, not yet a workspace revision, and durable: the
      // buffer uses non-expirable key-value storage.
      self::assertSame(0, $this->trackedRevisionCount('entity_test_mulrevpub', (string) $entity->id()));
      $buffer = $this->container->get(PendingContentAutoSaveBuffer::class);
      $row = $buffer->get(AutoSaveManager::getAutoSaveKey($entity));
      self::assertNotNull($row);
      self::assertSame('buffered draft', $row['data']['name'][0]['value'] ?? NULL);

      // The buffered draft is what readers see even before flush.
      $buffered = $manager->getAutoSaveEntity($entity)->entity;
      self::assertInstanceOf(EntityTestMulRevPub::class, $buffered);
      self::assertSame('buffered draft', $buffered->get('name')->value);

      // Reads that return authoritative hashes flush first; simulate the
      // missed-terminate recovery.
      $manager->flushDeferredContentEntity($entity);
      self::assertSame(1, $this->trackedRevisionCount('entity_test_mulrevpub', (string) $entity->id()), 'The buffered draft was flushed into a workspace revision on read.');
      $tombstone = $buffer->get(AutoSaveManager::getAutoSaveKey($entity));
      self::assertNotNull($tombstone);
      self::assertArrayNotHasKey('data', $tombstone, 'Only the metadata tombstone remains after the flush.');
    }
    finally {
      \putenv('CANVAS_TEST_FORCE_DEFER_AUTOSAVE');
    }
  }

  /**
   * Staged dependent entities follow their host and are never listed.
   */
  public function testDependentPathAliasFollowsHost(): void {
    $manager = $this->autoSaveManager();
    $entity = EntityTestMulRevPub::create(['name' => 'live', 'status' => TRUE]);
    $entity->save();

    $draft = clone $entity;
    $draft->set('name', 'draft');
    $manager->saveEntity($draft);

    // Simulate the alias implicitly staged by saving a host whose path field
    // changed: an alias entity saved while the auto-save workspace is active.
    $host_path = '/' . $entity->toUrl()->getInternalPath();
    $workspace_manager = $this->container->get('workspaces.manager');
    $workspace_manager->executeInWorkspace(AutoSaveWorkspace::ID, static function () use ($host_path): void {
      PathAlias::create(['path' => $host_path, 'alias' => '/staged-alias'])->save();
    });
    self::assertNotSame(0, $this->trackedRevisionCount('path_alias', '1'));

    // Dependents are not pending changes of their own.
    foreach (\array_keys($manager->getAllAutoSaveList(FALSE, FALSE)) as $key) {
      self::assertStringStartsNotWith('path_alias:', $key);
    }

    // Discarding the host discards the dependent's staging with it.
    $manager->delete($entity);
    self::assertSame(0, $this->trackedRevisionCount('path_alias', '1'), 'The staged alias follows its host on discard.');
  }

  /**
   * The Canvas workspace provider locks the workspace down.
   */
  public function testWorkspaceProviderAccess(): void {
    $workspace = Workspace::load(AutoSaveWorkspace::ID);
    self::assertNotNull($workspace);
    self::assertSame(CanvasWorkspaceProvider::getId(), $workspace->get('provider')->value);

    $editor = $this->createUser([AutoSaveManager::PUBLISH_PERMISSION]);
    self::assertInstanceOf(User::class, $editor);
    self::assertTrue($workspace->access('view', $editor), 'Canvas editors may view (and therefore activate) the workspace.');
    self::assertFalse($workspace->access('update', $editor));
    self::assertFalse($workspace->access('publish', $editor));
    self::assertFalse($workspace->access('delete', $editor));

    $plain = $this->createUser(['view any workspace', 'edit any workspace']);
    self::assertInstanceOf(User::class, $plain);
    self::assertFalse($workspace->access('view', $plain), 'Generic workspace permissions grant nothing on the Canvas workspace.');

    $admin = $this->createUser(['administer workspaces']);
    self::assertInstanceOf(User::class, $admin);
    self::assertTrue($workspace->access('view', $admin));
    self::assertFalse($workspace->access('publish', $admin), 'Not even workspace administrators may core-publish the Canvas workspace: that would bypass per-item publish validation.');
  }

  /**
   * Core workspace-level publishing is stopped, even programmatically.
   *
   * Workspace::publish() would push every staged revision live without
   * validating any entity, and does not check access. The pre-publish
   * subscriber must stop it regardless of who calls it.
   */
  public function testCoreWorkspacePublishIsStopped(): void {
    $entity = EntityTestMulRevPub::create(['name' => 'live', 'status' => TRUE]);
    $entity->save();
    $draft = clone $entity;
    $draft->set('name', 'staged draft');
    $this->autoSaveManager()->saveEntity($draft);

    $workspace = Workspace::load(AutoSaveWorkspace::ID);
    self::assertNotNull($workspace);
    try {
      $workspace->publish();
      $this->fail('Core-publishing the Canvas workspace must throw.');
    }
    catch (WorkspacePublishException $e) {
      self::assertStringContainsString('Canvas publish endpoint', $e->getMessage());
    }

    // Nothing leaked to Live, and the staged draft is intact.
    $live = $this->container->get('entity_type.manager')->getStorage('entity_test_mulrevpub')->loadUnchanged((string) $entity->id());
    self::assertInstanceOf(EntityTestMulRevPub::class, $live);
    self::assertSame('live', $live->get('name')->value);
    $staged = $this->autoSaveManager()->getAutoSaveEntity($entity)->entity;
    self::assertInstanceOf(EntityTestMulRevPub::class, $staged);
    self::assertSame('staged draft', $staged->get('name')->value);
  }

}
