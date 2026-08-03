<?php

declare(strict_types=1);

namespace Drupal\canvas\Workspace;

use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\workspaces\WorkspaceInterface;

/**
 * The Canvas workspace review workflow: draft → in review → approved.
 *
 * Three fixed states with fixed semantics, enforced server-side; deliberately
 * not the core Workflows module. The publish gate lives in
 * AutoSaveWorkspacePublishSubscriber so every publish surface (Canvas API,
 * core Workspaces UI, cron) passes through it.
 *
 * @see \Drupal\canvas\Hook\CanvasWorkspaceHooks
 * @see \Drupal\canvas\EventSubscriber\AutoSave\AutoSaveWorkspacePublishSubscriber
 */
final class WorkspaceReview {

  use StringTranslationTrait;

  /**
   * Whether staged writes currently skip demotion.
   *
   * Publish-time staging saves entities into the workspace being published;
   * those saves are not editorial writes and must not demote the approved
   * state the publish is gated on.
   */
  private bool $demotionSuppressed = FALSE;

  public const string STATUS_DRAFT = 'draft';
  public const string STATUS_IN_REVIEW = 'in_review';
  public const string STATUS_APPROVED = 'approved';

  public const string SUBMIT_PERMISSION = 'canvas submit workspace for review';
  public const string APPROVE_PERMISSION = 'canvas approve workspace';

  /**
   * Allowed transitions: from-state → to-state → required permission.
   *
   * Approving implies being able to send back to draft, from either
   * non-draft state.
   */
  private const array TRANSITIONS = [
    self::STATUS_DRAFT => [
      self::STATUS_IN_REVIEW => self::SUBMIT_PERMISSION,
    ],
    self::STATUS_IN_REVIEW => [
      self::STATUS_APPROVED => self::APPROVE_PERMISSION,
      self::STATUS_DRAFT => self::APPROVE_PERMISSION,
    ],
    self::STATUS_APPROVED => [
      self::STATUS_DRAFT => self::APPROVE_PERMISSION,
    ],
  ];

  public function getStatus(WorkspaceInterface $workspace): string {
    $value = $workspace->get('canvas_workspace_status')->value;
    return \is_string($value) && $value !== '' ? $value : self::STATUS_DRAFT;
  }

  public function requiresReview(WorkspaceInterface $workspace): bool {
    return (bool) $workspace->get('canvas_require_review')->value;
  }

  /**
   * Whether the review gate currently blocks publishing this workspace.
   */
  public function isPublishBlocked(WorkspaceInterface $workspace): bool {
    return $this->requiresReview($workspace) && $this->getStatus($workspace) !== self::STATUS_APPROVED;
  }

  /**
   * Applies a review-state transition, enforcing the state machine.
   *
   * @throws \InvalidArgumentException
   *   When the transition does not exist from the current state.
   * @throws \Drupal\canvas\Workspace\WorkspaceReviewAccessException
   *   When the account lacks the transition's permission.
   */
  public function transition(WorkspaceInterface $workspace, string $to, AccountInterface $account): void {
    $from = $this->getStatus($workspace);
    if ($from === $to) {
      return;
    }
    $permission = self::TRANSITIONS[$from][$to] ?? NULL;
    if ($permission === NULL) {
      throw new \InvalidArgumentException(\sprintf('There is no "%s" → "%s" review transition.', $from, $to));
    }
    if (!$account->hasPermission($permission)) {
      throw new WorkspaceReviewAccessException(\sprintf('The "%s" permission is required for the "%s" → "%s" review transition.', $permission, $from, $to));
    }
    $workspace->set('canvas_workspace_status', $to);
    // Leaving the approved state (or re-entering review) always invalidates
    // a schedule: the approval it depended on no longer covers future state.
    if ($to === self::STATUS_DRAFT) {
      $workspace->set('canvas_scheduled_publish_at', NULL);
      $workspace->set('canvas_scheduled_publish_by', NULL);
    }
    $workspace->save();
  }

  /**
   * Demotes a workspace to draft after a staged write, cancelling schedules.
   *
   * An approval covers a specific content state, not future edits: any
   * staged write into an in-review or approved workspace resets it to draft
   * and cancels a scheduled publish.
   *
   * Deliberately permission-free: the demotion is a consequence of the edit,
   * not an action of the editor.
   */
  public function demoteOnStagedWrite(WorkspaceInterface $workspace): void {
    if ($this->demotionSuppressed) {
      return;
    }
    if ($this->getStatus($workspace) === self::STATUS_DRAFT) {
      return;
    }
    $workspace->set('canvas_workspace_status', self::STATUS_DRAFT);
    $workspace->set('canvas_scheduled_publish_at', NULL);
    $workspace->set('canvas_scheduled_publish_by', NULL);
    $workspace->save();
  }

  /**
   * Runs $callable with staged-write demotion suppressed.
   *
   * @see ::demoteOnStagedWrite()
   */
  public function suppressDemotion(callable $callable): mixed {
    $previous = $this->demotionSuppressed;
    $this->demotionSuppressed = TRUE;
    try {
      return $callable();
    }
    finally {
      $this->demotionSuppressed = $previous;
    }
  }

}
