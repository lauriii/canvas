<?php

declare(strict_types=1);

namespace Drupal\canvas\Workspace;

use Drupal\canvas\Plugin\WorkflowType\WorkspaceReviewWorkflowType;
use Drupal\canvas\WorkspaceReviewPermissions;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\workflows\TransitionInterface;
use Drupal\workflows\WorkflowInterface;
use Drupal\workspaces\WorkspaceInterface;

/**
 * The Canvas workspace review process, driven by a core workflow.
 *
 * The steps are an ordinary workflow of the `canvas_workspace_review` type:
 * states carry an "approved for publishing" flag (the publish gate), one
 * state is the initial state staged writes demote to, and each transition is
 * gated by its own permission. Canvas ships a default workflow
 * (draft → in review → approved); sites can reshape it or define their own
 * and point a workspace at it via the `canvas_review_workflow` field.
 *
 * The publish gate lives in AutoSaveWorkspacePublishSubscriber so every
 * publish surface (Canvas API, core Workspaces UI, cron) passes through it.
 *
 * @see \Drupal\canvas\Plugin\WorkflowType\WorkspaceReviewWorkflowType
 * @see \Drupal\canvas\EventSubscriber\AutoSave\AutoSaveWorkspacePublishSubscriber
 */
final class WorkspaceReview {

  /**
   * Shipped state IDs, also the fallbacks when no workflow is available.
   */
  public const string STATUS_DRAFT = 'draft';
  public const string STATUS_IN_REVIEW = 'in_review';
  public const string STATUS_APPROVED = 'approved';

  /**
   * Legacy permissions, replaced by per-transition workflow permissions.
   *
   * Kept only for the update-path mapping onto the new permissions.
   *
   * @see \Drupal\canvas\WorkspaceReviewPermissions
   * @see canvas_post_update_0025_review_workflow_permissions()
   */
  public const string SUBMIT_PERMISSION = 'canvas submit workspace for review';
  public const string APPROVE_PERMISSION = 'canvas approve workspace';

  /**
   * Whether staged writes currently skip demotion.
   *
   * Publish-time staging saves entities into the workspace being published;
   * those saves are not editorial writes and must not demote the approved
   * state the publish is gated on.
   */
  private bool $demotionSuppressed = FALSE;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * The review workflow governing a workspace, or NULL when unavailable.
   */
  public function getWorkflow(WorkspaceInterface $workspace): ?WorkflowInterface {
    if (!$this->entityTypeManager->hasDefinition('workflow')) {
      return NULL;
    }
    $configured = $workspace->hasField('canvas_review_workflow')
      ? $workspace->get('canvas_review_workflow')->value
      : NULL;
    $workflow_id = \is_string($configured) && $configured !== ''
      ? $configured
      : WorkspaceReviewWorkflowType::DEFAULT_WORKFLOW_ID;
    $workflow = $this->entityTypeManager->getStorage('workflow')->load($workflow_id);
    if (!$workflow instanceof WorkflowInterface
      || $workflow->getTypePlugin()->getPluginId() !== WorkspaceReviewWorkflowType::PLUGIN_ID) {
      return NULL;
    }
    return $workflow;
  }

  /**
   * The workflow type plugin for a workspace's review workflow.
   */
  private function getWorkflowType(WorkspaceInterface $workspace): ?WorkspaceReviewWorkflowType {
    $workflow = $this->getWorkflow($workspace);
    if ($workflow === NULL) {
      return NULL;
    }
    $type = $workflow->getTypePlugin();
    \assert($type instanceof WorkspaceReviewWorkflowType);
    return $type;
  }

  /**
   * The workspace's current review state ID.
   */
  public function getStatus(WorkspaceInterface $workspace): string {
    $type = $this->getWorkflowType($workspace);
    $value = $workspace->get('canvas_workspace_status')->value;
    if (\is_string($value) && $value !== '') {
      // A state the workflow does not define — the workflow was edited, or
      // the workspace was pointed at a different one — resolves to the
      // initial state, mirroring content_moderation's handling.
      if ($type === NULL || $type->hasState($value)) {
        return $value;
      }
    }
    return $type?->getInitialStateId() ?? self::STATUS_DRAFT;
  }

  /**
   * The human-readable label of the workspace's current review state.
   */
  public function getStatusLabel(WorkspaceInterface $workspace): string {
    $status = $this->getStatus($workspace);
    $type = $this->getWorkflowType($workspace);
    if ($type !== NULL && $type->hasState($status)) {
      return (string) $type->getState($status)->label();
    }
    return \ucfirst(\str_replace('_', ' ', $status));
  }

  public static function requiresReview(WorkspaceInterface $workspace): bool {
    return (bool) $workspace->get('canvas_require_review')->value;
  }

  /**
   * Whether the workspace's current state counts as approved for publishing.
   */
  public function isApproved(WorkspaceInterface $workspace): bool {
    $status = $this->getStatus($workspace);
    $type = $this->getWorkflowType($workspace);
    if ($type !== NULL) {
      return $type->isApprovedState($status);
    }
    // No workflow available: fall back to the shipped state semantics.
    return $status === self::STATUS_APPROVED;
  }

  /**
   * Whether the workspace sits in its workflow's initial state.
   */
  public function isInitialState(WorkspaceInterface $workspace): bool {
    $initial = $this->getWorkflowType($workspace)?->getInitialStateId() ?? self::STATUS_DRAFT;
    return $this->getStatus($workspace) === $initial;
  }

  /**
   * Whether the review gate currently blocks publishing this workspace.
   */
  public function isPublishBlocked(WorkspaceInterface $workspace): bool {
    return self::requiresReview($workspace) && !$this->isApproved($workspace);
  }

  /**
   * The transitions the account may execute from the current state.
   *
   * @return \Drupal\workflows\TransitionInterface[]
   *   Permitted transitions, keyed by transition ID.
   */
  public function getAvailableTransitions(WorkspaceInterface $workspace, AccountInterface $account): array {
    $workflow = $this->getWorkflow($workspace);
    $type = $this->getWorkflowType($workspace);
    if ($workflow === NULL || $type === NULL) {
      return [];
    }
    $status = $this->getStatus($workspace);
    if (!$type->hasState($status)) {
      return [];
    }
    $transitions = $type->getTransitionsForState($status, TransitionInterface::DIRECTION_FROM);
    return \array_filter(
      $transitions,
      fn (TransitionInterface $transition): bool => $account->hasPermission(
        WorkspaceReviewPermissions::transitionPermission((string) $workflow->id(), (string) $transition->id()),
      ),
    );
  }

  /**
   * Executes a workflow transition, enforcing the state machine.
   *
   * @throws \InvalidArgumentException
   *   When the transition does not exist or does not apply to the current
   *   state.
   * @throws \Drupal\canvas\Workspace\WorkspaceReviewAccessException
   *   When the account lacks the transition's permission.
   */
  public function transition(WorkspaceInterface $workspace, string $transition_id, AccountInterface $account): void {
    $workflow = $this->getWorkflow($workspace);
    $type = $this->getWorkflowType($workspace);
    if ($workflow === NULL || $type === NULL) {
      throw new \InvalidArgumentException('No review workflow is available for this workspace.');
    }
    if (!$type->hasTransition($transition_id)) {
      throw new \InvalidArgumentException(\sprintf('There is no "%s" transition in the "%s" workflow.', $transition_id, (string) $workflow->id()));
    }
    $transition = $type->getTransition($transition_id);
    $status = $this->getStatus($workspace);
    $from_ids = \array_keys($transition->from());
    if (!\in_array($status, $from_ids, TRUE)) {
      throw new \InvalidArgumentException(\sprintf('The "%s" transition does not apply to the "%s" state.', $transition_id, $status));
    }
    if (!$account->hasPermission(WorkspaceReviewPermissions::transitionPermission((string) $workflow->id(), $transition_id))) {
      throw new WorkspaceReviewAccessException(\sprintf('You do not have permission to use the "%s" transition.', (string) $transition->label()));
    }
    $to_id = (string) $transition->to()->id();
    $workspace->set('canvas_workspace_status', $to_id);
    // A schedule depends on an approved state: leaving approval for any
    // non-approved state invalidates it.
    if (!$type->isApprovedState($to_id)) {
      $workspace->set('canvas_scheduled_publish_at', NULL);
      $workspace->set('canvas_scheduled_publish_by', NULL);
    }
    $workspace->save();
  }

  /**
   * Demotes a workspace to its initial state after a staged write.
   *
   * An approval covers a specific content state, not future edits: any
   * staged write into a workspace beyond the initial state resets it and
   * cancels a scheduled publish.
   *
   * Deliberately permission-free: the demotion is a consequence of the edit,
   * not an action of the editor.
   */
  public function demoteOnStagedWrite(WorkspaceInterface $workspace): void {
    if ($this->demotionSuppressed) {
      return;
    }
    if ($this->isInitialState($workspace)) {
      return;
    }
    $initial = $this->getWorkflowType($workspace)?->getInitialStateId() ?? self::STATUS_DRAFT;
    $workspace->set('canvas_workspace_status', $initial);
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
