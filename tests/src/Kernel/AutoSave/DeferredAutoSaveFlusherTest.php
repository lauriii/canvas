<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\AutoSave;

use Drupal\canvas\AutoSave\Workspace\AutoSaveWorkspace;
use Drupal\canvas\AutoSave\Workspace\DeferredAutoSaveFlusher;
use Drupal\canvas\AutoSave\Workspace\WorkspaceAutoSave;
use Drupal\entity_test\Entity\EntityTestMulRevPub;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\workspaces\Entity\Workspace;
use PHPUnit\Framework\Attributes\Group;

/**
 * @coversDefaultClass \Drupal\canvas\AutoSave\Workspace\DeferredAutoSaveFlusher
 */
#[Group('canvas')]
#[Group('canvas_auto_save')]
final class DeferredAutoSaveFlusherTest extends CanvasKernelTestBase {

  use UserCreationTrait;

  protected static $modules = [
    'field',
    'entity_test',
    'path_alias',
  ];

  protected function setUp(): void {
    parent::setUp();
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

    \putenv('CANVAS_TEST_FORCE_DEFER_AUTOSAVE=1');
  }

  protected function tearDown(): void {
    \putenv('CANVAS_TEST_FORCE_DEFER_AUTOSAVE');
    parent::tearDown();
  }

  /**
   * Deferred persist does not write workspace revisions until flushed.
   */
  public function testDeferredDoesNotWriteWorkspaceUntilFlush(): void {
    $this->container->get('workspaces.manager')->switchToLive();

    /** @var \Drupal\entity_test\Entity\EntityTestMulRevPub $entity */
    $entity = EntityTestMulRevPub::create([
      'name' => 'initial',
      'status' => TRUE,
    ]);
    $entity->save();
    $entity_id = (string) $entity->id();

    $workspaceAutoSave = $this->getWorkspaceAutoSave();
    $tracker = $this->container->get('workspaces.tracker');

    $storage = $this->container->get('entity_type.manager')->getStorage('entity_test_mulrevpub');
    $storage->resetCache([$entity_id]);
    /** @var \Drupal\entity_test\Entity\EntityTestMulRevPub $working */
    $working = $storage->load($entity_id);
    self::assertNotNull($working);
    $working->set('name', 'deferred edit');
    $workspaceAutoSave->persistStagedEntity($working, NULL, FALSE);

    $tracked = $tracker->getTrackedEntities(AutoSaveWorkspace::ID, 'entity_test_mulrevpub', [$entity_id]);
    self::assertEmpty($tracked['entity_test_mulrevpub'] ?? [], 'No workspace revision should exist until flush.');

    /** @var \Drupal\canvas\AutoSave\Workspace\DeferredAutoSaveFlusher $flusher */
    $flusher = $this->container->get(DeferredAutoSaveFlusher::class);
    $flusher->flushNow($working);

    $tracked = $tracker->getTrackedEntities(AutoSaveWorkspace::ID, 'entity_test_mulrevpub', [$entity_id]);
    self::assertNotEmpty($tracked['entity_test_mulrevpub'] ?? [], 'Flush should persist a workspace-tracked revision.');
  }

  /**
   * Immediate persist writes to the workspace without requiring flush.
   */
  public function testImmediatePersistWritesWorkspaceRevision(): void {
    $this->container->get('workspaces.manager')->switchToLive();

    /** @var \Drupal\entity_test\Entity\EntityTestMulRevPub $entity */
    $entity = EntityTestMulRevPub::create([
      'name' => 'initial',
      'status' => TRUE,
    ]);
    $entity->save();
    $entity_id = (string) $entity->id();

    $workspaceAutoSave = $this->getWorkspaceAutoSave();
    $tracker = $this->container->get('workspaces.tracker');

    $storage = $this->container->get('entity_type.manager')->getStorage('entity_test_mulrevpub');
    $storage->resetCache([$entity_id]);
    /** @var \Drupal\entity_test\Entity\EntityTestMulRevPub $working */
    $working = $storage->load($entity_id);
    self::assertNotNull($working);
    $working->set('name', 'immediate');
    $workspaceAutoSave->persistStagedEntity($working, NULL, TRUE);

    $tracked = $tracker->getTrackedEntities(AutoSaveWorkspace::ID, 'entity_test_mulrevpub', [$entity_id]);
    self::assertNotEmpty($tracked['entity_test_mulrevpub'] ?? []);
  }

  private function getWorkspaceAutoSave(): WorkspaceAutoSave {
    $ws = $this->container->get(WorkspaceAutoSave::class);
    self::assertInstanceOf(WorkspaceAutoSave::class, $ws);
    return $ws;
  }

}
