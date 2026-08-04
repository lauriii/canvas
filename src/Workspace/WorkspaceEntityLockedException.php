<?php

declare(strict_types=1);

namespace Drupal\canvas\Workspace;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * An entity's pending work lives in another workspace (core's one-workspace
 * -per-entity tracking); the attempted staged write was rejected.
 *
 * Mapped to a structured 409 naming the owning workspace, so the client can
 * present "locked in workspace X" with a switch action instead of a generic
 * failure.
 *
 * @see \Drupal\canvas\AutoSave\Workspace\WorkspaceAutoSave::persistStagedEntity()
 * @see \Drupal\canvas\EventSubscriber\ApiExceptionSubscriber
 */
final class WorkspaceEntityLockedException extends ConflictHttpException {

  public function __construct(
    public readonly string $workspaceId,
    public readonly string $workspaceLabel,
  ) {
    parent::__construct(\sprintf('This content has pending changes in the "%s" workspace. Publish or discard them there first, or switch to that workspace to continue editing.', $workspaceLabel));
  }

}
