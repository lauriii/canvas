<?php

declare(strict_types=1);

namespace Drupal\canvas\Workspace;

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\AutoSave\Workspace\WorkspaceAutoSave;
use Drupal\canvas\Controller\ApiAutoSaveController;
use Drupal\canvas\Entity\AutoSavePublishAwareInterface;
use Drupal\canvas\Entity\ComponentTreeConfigEntityBase;
use Drupal\canvas\Entity\EntityConstraintViolationList;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityConstraintViolationListInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;

/**
 * Publishes a workspace atomically through core workspace publish.
 *
 * The pipeline: flush deferred buffers, validate every tracked item up
 * front (content entities via entity validation plus recorded form
 * violations, snapshot-staged drafts after reconstructing them), check
 * per-item update access, then — inside one database transaction — stage
 * any remaining snapshot drafts into the workspace and call core
 * Workspace::publish(). Core promotes every tracked revision (sibling
 * translations and dependent path aliases included), the workspace_config
 * module applies staged configuration on the pre-publish event, and the
 * post-publish subscriber clears Canvas's staging stores.
 *
 * The review gate is enforced by the pre-publish subscriber, so it also
 * covers publishes triggered from the core Workspaces UI; this service
 * checks it up front only to fail before doing expensive validation.
 *
 * @see \Drupal\canvas\EventSubscriber\AutoSave\AutoSaveWorkspacePublishSubscriber
 * @see \Drupal\workspaces\WorkspacePublisher::publish()
 */
final class CanvasWorkspacePublisher {

  public function __construct(
    private readonly Connection $database,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AutoSaveManager $autoSaveManager,
    private readonly WorkspaceAutoSave $workspaceAutoSave,
    private readonly WorkspaceReview $workspaceReview,
    /**
     * @var \Drupal\workspaces\WorkspaceManagerInterface
     */
    #[Autowire(service: 'workspaces.manager')]
    private readonly object $workspaceManager,
  ) {}

  /**
   * Validates and publishes a workspace atomically.
   *
   * @param string $workspace_id
   *   The workspace to publish.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account publishing; per-item update access is checked against it.
   *
   * @return int
   *   The number of published pending changes (as listed in the manifest).
   *
   * @throws \Drupal\canvas\Workspace\WorkspacePublishValidationException
   *   When any tracked item fails validation or update access.
   * @throws \Drupal\workspaces\WorkspacePublishException
   *   When core (or a pre-publish subscriber, e.g. the review gate) refuses
   *   the publish.
   */
  public function publish(string $workspace_id, AccountInterface $account): int {
    /** @var \Drupal\workspaces\WorkspaceInterface $workspace */
    $workspace = $this->entityTypeManager->getStorage('workspace')->load($workspace_id);
    \assert($workspace !== NULL);

    /** @var \Drupal\workspaces\WorkspaceManagerInterface $wm */
    $wm = $this->workspaceManager;
    return $wm->executeInWorkspace($workspace_id, function () use ($workspace, $account): int {
      // Flush deferred buffers so validation sees durable staged state.
      $entries = $this->autoSaveManager->getAllAutoSaveList(with_entities: TRUE, with_conflicts: FALSE);
      foreach ($entries as $entry) {
        if ($entry['entity'] instanceof ContentEntityInterface) {
          $this->autoSaveManager->flushDeferredContentEntity($entry['entity']);
        }
      }
      $entries = $this->autoSaveManager->getAllAutoSaveList(with_entities: TRUE, with_conflicts: FALSE);

      // Validate every listed item and check update access up front, so no
      // live write happens when anything is invalid.
      $violation_sets = [];
      $snapshot_staged = [];
      foreach ($entries as $entry) {
        $entity = $entry['entity'] ?? NULL;
        if (!$entity instanceof EntityInterface) {
          continue;
        }
        $entity->enforceIsNew(FALSE);
        $access = $entity->access('update', $account, return_as_object: TRUE);
        \assert($access instanceof AccessResultInterface);
        if (!$access->isAllowed() && !self::isManifestOnlyEntity($entity)) {
          $violation_sets[] = self::accessViolation($entity);
          continue;
        }
        $item_violations = $this->validateItem($entity);
        if ($item_violations !== NULL && $item_violations->count() > 0) {
          $violation_sets[] = $item_violations;
          continue;
        }
        // Items still staged as snapshot rows (config drafts from the code
        // editor, content the storage layer once rejected) are not tracked
        // by the workspace yet: they must be staged into it before core
        // publish can promote them.
        if ($this->workspaceAutoSave->hasSnapshotStaging($entity)) {
          $snapshot_staged[] = $entity;
        }
      }
      if ($violation_sets !== []) {
        throw new WorkspacePublishValidationException($violation_sets);
      }

      $published_count = \count($entries);

      // Either everything goes live, or nothing: the snapshot staging, the
      // config application (pre-publish event), and core's revision
      // promotion all commit or roll back together. Core's own transaction
      // becomes a savepoint inside this one.
      $transaction = $this->database->startTransaction();
      try {
        // Publish-time staging is not an editorial write: it must not demote
        // the (approved) review state it is about to be gated on.
        $this->workspaceReview->suppressDemotion(function () use ($snapshot_staged, $workspace): void {
          foreach ($snapshot_staged as $entity) {
            $this->stageSnapshotEntity($entity);
          }
          $workspace->publish();
        });
        $transaction->commitOrRelease();
      }
      catch (\Throwable $e) {
        $transaction->rollBack();
        throw $e;
      }
      return $published_count;
    });
  }

  /**
   * Validates one manifest item; NULL when it has no validatable form.
   */
  private function validateItem(EntityInterface $entity): EntityConstraintViolationListInterface|ConstraintViolationList|NULL {
    if (self::isManifestOnlyEntity($entity)) {
      return NULL;
    }
    if ($entity instanceof ConfigEntityInterface) {
      $violations = $entity->getTypedData()->validate();
      return $violations->count() > 0 ? new EntityConstraintViolationList($entity, $violations) : NULL;
    }
    if ($entity instanceof ContentEntityInterface) {
      $violations = $entity->validate();
      $form_violations = $this->autoSaveManager->getEntityFormViolations($entity);
      foreach ($form_violations as $form_violation) {
        $violations->add($form_violation);
      }
      if ($violations->count() === 0) {
        return NULL;
      }
      return ApiAutoSaveController::getViolationSetsFromPropertyPathsAndRoot($entity, $violations);
    }
    return NULL;
  }

  /**
   * Entities listed for review completeness that Canvas cannot validate.
   *
   * Simple config staged by workspace_config has no entity-level validation
   * or update access; it is applied by workspace_config's ConfigImporter at
   * publish, which enforces schema itself.
   */
  private static function isManifestOnlyEntity(EntityInterface $entity): bool {
    return $entity->getEntityTypeId() === 'workspace_config';
  }

  /**
   * Stages a snapshot-held draft into the workspace and clears the snapshot.
   */
  private function stageSnapshotEntity(EntityInterface $entity): void {
    if ($entity instanceof AutoSavePublishAwareInterface) {
      $entity->autoSavePublish();
    }
    $entity->enforceIsNew(FALSE);
    // Inside the workspace: a config entity save stages via workspace_config;
    // a content entity save becomes a tracked pending revision, both promoted
    // by the core publish that follows.
    $entity->save();
    if ($entity instanceof ComponentTreeConfigEntityBase) {
      foreach ($this->autoSaveManager->groupConfigEntityAutoSaves($entity) as $override) {
        $override->autoSavePublish();
        $override->enforceIsNew(FALSE);
        $override->save();
      }
    }
  }

  /**
   * A per-item violation set for an update-access failure.
   */
  private static function accessViolation(EntityInterface $entity): EntityConstraintViolationList {
    $message = \sprintf('You do not have permission to update %s.', (string) ($entity->label() ?? $entity->id()));
    $violation = new ConstraintViolation(
      message: $message,
      messageTemplate: $message,
      parameters: [],
      root: $entity,
      propertyPath: AutoSaveManager::getAutoSaveKey($entity),
      invalidValue: NULL,
    );
    return new EntityConstraintViolationList($entity, [$violation]);
  }

}
