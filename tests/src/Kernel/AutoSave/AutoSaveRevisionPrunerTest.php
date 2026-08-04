<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\AutoSave;

use Drupal\canvas\AutoSave\Workspace\AutoSaveRevisionPruner;
use Drupal\canvas\AutoSave\Workspace\AutoSaveWorkspace;
use Drupal\canvas\AutoSave\Workspace\WorkspaceAutoSave;
use Drupal\entity_test\Entity\EntityTestMulRevPub;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\workspaces\Entity\Workspace;
use PHPUnit\Framework\Attributes\Group;

/**
 * @coversDefaultClass \Drupal\canvas\AutoSave\Workspace\AutoSaveRevisionPruner
 */
#[Group('canvas')]
#[Group('canvas_auto_save')]
final class AutoSaveRevisionPrunerTest extends CanvasKernelTestBase {

  use UserCreationTrait;

  protected static $modules = [
    'field',
    'entity_test',
    // Required for permission generation during createUser() when workspaces wraps the alias manager.
    'path_alias',
  ];

  /**
   * @var \Drupal\workspaces\WorkspaceManagerInterface
   */
  protected $workspaceManager;

  protected function setUp(): void {
    parent::setUp();
    $this->workspaceManager = \Drupal::service('workspaces.manager');
    $this->installEntitySchema('path_alias');
    $this->installEntitySchema('user');
    $this->installEntitySchema('entity_test_mulrevpub');

    $account = $this->createUser([
      'administer workspaces',
      'view any workspace',
      'edit any workspace',
    ]);
    self::assertNotFalse($account);
    $this->setCurrentUser($account);

    $ws_storage = $this->container->get('entity_type.manager')->getStorage('workspace');
    if ($ws_storage->load(AutoSaveWorkspace::ID) === NULL) {
      Workspace::create([
        'id' => AutoSaveWorkspace::ID,
        'label' => AutoSaveWorkspace::LABEL,
        'uid' => (int) $account->id(),
      ])->save();
    }
  }

  /**
   * Log-spaced pruning bounds tracked auto-save revisions; live default revision remains.
   */
  public function testPruningReducesTrackedRevisions(): void {
    $this->workspaceManager->switchToLive();

    /** @var \Drupal\entity_test\Entity\EntityTestMulRevPub $entity */
    $entity = EntityTestMulRevPub::create([
      'name' => 'initial',
      'status' => TRUE,
    ]);
    $entity->save();

    $default_revision_id = (int) $entity->getRevisionId();
    $entity_id = (string) $entity->id();

    $workspaceAutoSave = $this->getWorkspaceAutoSave();
    $tracker = \Drupal::service('workspaces.tracker');

    $iterations = 32;
    for ($i = 0; $i < $iterations; $i++) {
      $storage = $this->container->get('entity_type.manager')->getStorage('entity_test_mulrevpub');
      $storage->resetCache([$entity_id]);
      /** @var \Drupal\entity_test\Entity\EntityTestMulRevPub $working */
      $working = $storage->load($entity_id);
      self::assertNotNull($working);
      $working->set('name', 'auto-save step ' . $i);
      $workspaceAutoSave->persistStagedEntity($working, NULL, TRUE);
    }

    $tracked = $tracker->getTrackedEntities(AutoSaveWorkspace::ID, 'entity_test_mulrevpub', [$entity_id]);
    $tracked_latest = $tracked['entity_test_mulrevpub'] ?? [];
    self::assertNotEmpty($tracked_latest, 'Workspace should track at least one pending revision.');

    // With pruning, we keep O(log n) revisions, not one per edit.
    self::assertLessThan($iterations, \count($tracked_latest), 'Pruning should remove old auto-save revisions.');

    // Upper bound from the algorithm (~2 log2(n)); allow slack for implementation details.
    $max_expected = 2 * (int) ceil(log($iterations + 1, 2)) + 6;
    self::assertLessThanOrEqual($max_expected, \count($tracked_latest));

    $this->workspaceManager->switchToLive();
    $live = $this->container->get('entity_type.manager')->getStorage('entity_test_mulrevpub')->load($entity_id);
    self::assertInstanceOf(EntityTestMulRevPub::class, $live);
    self::assertSame($default_revision_id, (int) $live->getRevisionId(), 'Default (published) revision should be unchanged while edits are workspace-only.');
  }

  /**
   * FirstZeroBit matches the reference bit-twiddling implementation.
   */
  public function testFirstZeroBit(): void {
    self::assertSame(2, AutoSaveRevisionPruner::firstZeroBit(1));
    self::assertSame(4, AutoSaveRevisionPruner::firstZeroBit(3));
    self::assertSame(2, AutoSaveRevisionPruner::firstZeroBit(5));
    self::assertSame(8, AutoSaveRevisionPruner::firstZeroBit(7));
  }

  public function testResetClearsKeyValueState(): void {
    $this->workspaceManager->switchToLive();

    /** @var \Drupal\entity_test\Entity\EntityTestMulRevPub $entity */
    $entity = EntityTestMulRevPub::create([
      'name' => 'reset test',
      'status' => TRUE,
    ]);
    $entity->save();

    $workspaceAutoSave = $this->getWorkspaceAutoSave();
    $entity->set('name', 'staged');
    $workspaceAutoSave->persistStagedEntity($entity, NULL, TRUE);

    // Pruner bookkeeping keys are workspace-prefixed; with no active
    // workspace they resolve against the Main workspace.
    $key = AutoSaveWorkspace::ID . ':entity_test_mulrevpub:' . $entity->id();
    $store = \Drupal::keyValue(AutoSaveRevisionPruner::STORE);
    self::assertNotNull($store->get($key), 'KV state should exist after an auto-save save.');

    /** @var \Drupal\canvas\AutoSave\Workspace\AutoSaveRevisionPruner $pruner */
    $pruner = $this->container->get(AutoSaveRevisionPruner::class);
    $pruner->reset($entity);
    self::assertNull($store->get($key), 'reset() should clear snapshot KV for the entity.');
  }

  private function getWorkspaceAutoSave(): WorkspaceAutoSave {
    $ws = $this->container->get(WorkspaceAutoSave::class);
    self::assertInstanceOf(WorkspaceAutoSave::class, $ws);
    return $ws;
  }

}
