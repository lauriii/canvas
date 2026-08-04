<?php

declare(strict_types=1);

namespace Drupal\canvas\Controller;

use Drupal\canvas\AutoSave\Workspace\AutoSaveWorkspace;
use Drupal\canvas\Workspace\WorkspaceReview;
use Drupal\canvas\Workspace\WorkspaceReviewAccessException;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Transliteration\TransliterationInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\workspaces\WorkspaceInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Workspace management endpoints for the Canvas UI (and external switchers).
 *
 * Thin wrappers over core Workspaces: every access decision delegates to the
 * workspace entity's access handler and core permissions; switching persists
 * through core's negotiators so non-Canvas surfaces observe the same active
 * workspace.
 *
 * @internal This HTTP API is intended only for the Canvas UI. These
 *   controllers and associated routes may change at any time.
 */
final class ApiWorkspaceController extends ApiControllerBase {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AccountInterface $currentUser,
    #[Autowire(service: 'transliteration')]
    private readonly TransliterationInterface $transliteration,
    private readonly TimeInterface $time,
    /**
     * @var \Drupal\workspaces\WorkspaceManagerInterface
     */
    #[Autowire(service: 'workspaces.manager')]
    private readonly object $workspaceManager,
    /**
     * @var \Drupal\workspaces\WorkspaceTrackerInterface
     */
    #[Autowire(service: 'workspaces.tracker')]
    private readonly object $workspaceAssociation,
  ) {}

  /**
   * Lists the workspaces the current user may view.
   */
  public function list(): JsonResponse {
    $storage = $this->entityTypeManager->getStorage('workspace');
    /** @var \Drupal\workspaces\WorkspaceManagerInterface $wm */
    $wm = $this->workspaceManager;
    $active_id = $wm->hasActiveWorkspace() ? (string) $wm->getActiveWorkspace()?->id() : NULL;
    $data = [];
    foreach ($storage->loadMultiple() as $workspace) {
      \assert($workspace instanceof WorkspaceInterface);
      if (!$workspace->access('view', $this->currentUser)) {
        continue;
      }
      // Sub-workspaces cannot be published and are not part of the Canvas
      // flow; list only top-level workspaces.
      if ($workspace->hasParent()) {
        continue;
      }
      $data[] = $this->normalizeWorkspace($workspace, $active_id);
    }
    return new JsonResponse(data: ['data' => $data, 'activeWorkspaceId' => $active_id], status: Response::HTTP_OK);
  }

  /**
   * Creates a workspace.
   */
  public function create(Request $request): JsonResponse {
    $body = \json_decode($request->getContent(), TRUE);
    $label = \is_array($body) ? \trim((string) ($body['label'] ?? '')) : '';
    if ($label === '') {
      throw new BadRequestHttpException('A non-empty "label" is required.');
    }
    $storage = $this->entityTypeManager->getStorage('workspace');
    $access = $this->entityTypeManager->getAccessControlHandler('workspace')->createAccess(NULL, $this->currentUser, [], TRUE);
    if (!$access->isAllowed()) {
      throw new AccessDeniedHttpException('You do not have permission to create workspaces.');
    }
    $id = $this->deriveMachineName($label);
    /** @var \Drupal\workspaces\WorkspaceInterface $workspace */
    $workspace = $storage->create([
      'id' => $id,
      'label' => $label,
      'uid' => $this->currentUser->id(),
    ]);
    if (\array_key_exists('requireReview', $body) && \is_bool($body['requireReview'])) {
      $workspace->set('canvas_require_review', $body['requireReview']);
    }
    $violations = $workspace->validate();
    if ($violations->count() > 0) {
      throw new BadRequestHttpException((string) $violations->get(0)->getMessage());
    }
    $workspace->save();
    /** @var \Drupal\workspaces\WorkspaceManagerInterface $wm */
    $wm = $this->workspaceManager;
    $active_id = $wm->hasActiveWorkspace() ? (string) $wm->getActiveWorkspace()?->id() : NULL;
    return new JsonResponse(data: $this->normalizeWorkspace($workspace, $active_id), status: Response::HTTP_CREATED);
  }

  /**
   * Deletes a workspace, discarding its staged work.
   */
  public function delete(WorkspaceInterface $workspace): JsonResponse {
    if (!$workspace->access('delete', $this->currentUser)) {
      throw new AccessDeniedHttpException('You do not have permission to delete this workspace.');
    }
    if ($workspace->id() === AutoSaveWorkspace::ID) {
      throw new ConflictHttpException('The Main workspace cannot be deleted.');
    }
    /** @var \Drupal\workspaces\WorkspaceManagerInterface $wm */
    $wm = $this->workspaceManager;
    if ($wm->hasActiveWorkspace() && $wm->getActiveWorkspace()?->id() === $workspace->id()) {
      $wm->switchToLive();
    }
    $workspace->delete();
    return new JsonResponse(status: Response::HTTP_NO_CONTENT, data: NULL);
  }

  /**
   * Activates a workspace for the current user, persisting via negotiation.
   */
  public function activate(WorkspaceInterface $workspace): JsonResponse {
    if (!$workspace->access('view', $this->currentUser)) {
      throw new AccessDeniedHttpException('You do not have permission to switch to this workspace.');
    }
    /** @var \Drupal\workspaces\WorkspaceManagerInterface $wm */
    $wm = $this->workspaceManager;
    $wm->setActiveWorkspace($workspace);
    return new JsonResponse(data: $this->normalizeWorkspace($workspace, (string) $workspace->id()), status: Response::HTTP_OK);
  }

  /**
   * Applies a review-state transition: submit, approve, or reject.
   */
  public function status(WorkspaceInterface $workspace, Request $request): JsonResponse {
    $body = \json_decode($request->getContent(), TRUE);
    $transition = \is_array($body) ? (string) ($body['transition'] ?? '') : '';
    $target = match ($transition) {
      'submit' => WorkspaceReview::STATUS_IN_REVIEW,
      'approve' => WorkspaceReview::STATUS_APPROVED,
      'reject' => WorkspaceReview::STATUS_DRAFT,
      default => throw new BadRequestHttpException('The "transition" must be one of "submit", "approve", or "reject".'),
    };
    if (!$workspace->access('view', $this->currentUser)) {
      throw new AccessDeniedHttpException('You do not have permission to act on this workspace.');
    }
    try {
      WorkspaceReview::transition($workspace, $target, $this->currentUser);
    }
    catch (WorkspaceReviewAccessException $e) {
      throw new AccessDeniedHttpException($e->getMessage(), $e);
    }
    catch (\InvalidArgumentException $e) {
      throw new ConflictHttpException($e->getMessage(), $e);
    }
    return new JsonResponse(data: $this->normalizeWorkspace($workspace, $this->activeWorkspaceIdOrNull()), status: Response::HTTP_OK);
  }

  /**
   * Schedules the workspace to publish at a given time.
   */
  public function schedule(WorkspaceInterface $workspace, Request $request): JsonResponse {
    if (!$workspace->access('publish', $this->currentUser)) {
      throw new AccessDeniedHttpException('You do not have permission to publish this workspace.');
    }
    $body = \json_decode($request->getContent(), TRUE);
    $publish_at = \is_array($body) ? $body['publishAt'] ?? NULL : NULL;
    if (!\is_int($publish_at)) {
      throw new BadRequestHttpException('An integer "publishAt" timestamp is required.');
    }
    if ($publish_at <= $this->time->getRequestTime()) {
      throw new BadRequestHttpException('The "publishAt" timestamp must be in the future.');
    }
    // Scheduling inherits the review gate: a review-required workspace must
    // already be approved, exactly as if it were being published now.
    if (WorkspaceReview::isPublishBlocked($workspace)) {
      throw new ConflictHttpException(\sprintf(
        'The workspace must be approved before it can be scheduled; its review state is "%s".',
        WorkspaceReview::getStatus($workspace),
      ));
    }
    $workspace->set('canvas_scheduled_publish_at', $publish_at);
    $workspace->set('canvas_scheduled_publish_by', $this->currentUser->id());
    $workspace->set('canvas_scheduled_publish_error', NULL);
    $workspace->save();
    return new JsonResponse(data: $this->normalizeWorkspace($workspace, $this->activeWorkspaceIdOrNull()), status: Response::HTTP_OK);
  }

  /**
   * Cancels the workspace's scheduled publish.
   */
  public function unschedule(WorkspaceInterface $workspace): JsonResponse {
    if (!$workspace->access('publish', $this->currentUser)) {
      throw new AccessDeniedHttpException('You do not have permission to publish this workspace.');
    }
    $workspace->set('canvas_scheduled_publish_at', NULL);
    $workspace->set('canvas_scheduled_publish_by', NULL);
    $workspace->save();
    return new JsonResponse(data: $this->normalizeWorkspace($workspace, $this->activeWorkspaceIdOrNull()), status: Response::HTTP_OK);
  }

  private function activeWorkspaceIdOrNull(): ?string {
    /** @var \Drupal\workspaces\WorkspaceManagerInterface $wm */
    $wm = $this->workspaceManager;
    return $wm->hasActiveWorkspace() ? (string) $wm->getActiveWorkspace()?->id() : NULL;
  }

  /**
   * The client-side representation of one workspace.
   *
   * @return array<string, mixed>
   */
  private function normalizeWorkspace(WorkspaceInterface $workspace, ?string $active_id): array {
    $scheduled_at = $workspace->get('canvas_scheduled_publish_at')->value;
    return [
      'id' => (string) $workspace->id(),
      'label' => (string) $workspace->label(),
      'isDefault' => $workspace->id() === AutoSaveWorkspace::ID,
      'isActive' => $active_id === (string) $workspace->id(),
      'status' => WorkspaceReview::getStatus($workspace),
      'requireReview' => WorkspaceReview::requiresReview($workspace),
      'scheduledPublishAt' => $scheduled_at !== NULL ? (int) $scheduled_at : NULL,
      'scheduledPublishError' => $workspace->get('canvas_scheduled_publish_error')->value,
      'pendingChangesCount' => $this->countPendingChanges($workspace),
      'access' => [
        'delete' => $workspace->access('delete', $this->currentUser),
        'publish' => $workspace->access('publish', $this->currentUser),
        'submitForReview' => $this->currentUser->hasPermission(WorkspaceReview::SUBMIT_PERMISSION),
        'approve' => $this->currentUser->hasPermission(WorkspaceReview::APPROVE_PERMISSION),
      ],
    ];
  }

  /**
   * The number of entities the workspace tracks, plus Canvas snapshot drafts.
   *
   * An approximation for the switcher and the delete confirmation; the
   * review manifest is the authoritative list.
   */
  private function countPendingChanges(WorkspaceInterface $workspace): int {
    /** @var \Drupal\workspaces\WorkspaceTrackerInterface $tracker */
    $tracker = $this->workspaceAssociation;
    $count = 0;
    foreach ($tracker->getTrackedEntities((string) $workspace->id()) as $entity_type_id => $revision_map) {
      if ($entity_type_id === 'path_alias') {
        continue;
      }
      $count += \count(\array_unique($revision_map));
    }
    $snapshot_storage = $this->entityTypeManager->getStorage('canvas_auto_save_snapshot');
    $count += (int) $snapshot_storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('workspace', (string) $workspace->id())
      ->count()
      ->execute();
    return $count;
  }

  /**
   * Derives a unique workspace machine name from a label.
   */
  private function deriveMachineName(string $label): string {
    $base = \mb_strtolower($this->transliteration->transliterate($label, 'en'));
    $base = \preg_replace('/[^a-z0-9_]+/', '_', $base) ?? '';
    $base = \trim($base, '_');
    if ($base === '' || \preg_match('/^[0-9]/', $base)) {
      $base = 'workspace_' . $base;
    }
    $base = \substr($base, 0, 120);
    $storage = $this->entityTypeManager->getStorage('workspace');
    $id = $base;
    $suffix = 0;
    while ($storage->load($id) !== NULL) {
      $suffix++;
      $id = $base . '_' . $suffix;
    }
    return $id;
  }

}
