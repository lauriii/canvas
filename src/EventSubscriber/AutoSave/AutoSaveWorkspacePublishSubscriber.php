<?php

declare(strict_types=1);

namespace Drupal\canvas\EventSubscriber\AutoSave;

use Drupal\canvas\AutoSave\Workspace\AutoSaveWorkspace;
use Drupal\canvas\AutoSave\Workspace\WorkspaceAutoSave;
use Drupal\canvas\Workspace\WorkspaceReview;
use Drupal\workspaces\Event\WorkspacePostPublishEvent;
use Drupal\workspaces\Event\WorkspacePrePublishEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Guards and finalizes workspace publishes for Canvas.
 *
 * Pre-publish: enforces the review gate — a review-required workspace must
 * be approved. Because core dispatches this event inside every publish
 * (Canvas API, core Workspaces UI, cron), no surface can bypass the gate.
 *
 * Post-publish: clears every Canvas staging store for the workspace, then
 * deletes the workspace — a published workspace is a completed unit of work.
 * The Main workspace is the one permanent workspace: it survives its
 * publishes with its schedule consumed and its review state reset.
 *
 * @see \Drupal\canvas\Workspace\CanvasWorkspacePublisher
 * @see \Drupal\workspaces\WorkspacePublisher::publish()
 */
final class AutoSaveWorkspacePublishSubscriber implements EventSubscriberInterface {

  public function __construct(
    private readonly WorkspaceAutoSave $workspaceAutoSave,
    private readonly WorkspaceReview $workspaceReview,
  ) {}

  public static function getSubscribedEvents(): array {
    return [
      WorkspacePrePublishEvent::class => 'onPrePublish',
      // After core's association cleanup (priority -500), which resolves the
      // workspace tree and must still find the workspace.
      // @see \Drupal\workspaces\WorkspaceTracker::getSubscribedEvents()
      WorkspacePostPublishEvent::class => ['onPostPublish', -600],
    ];
  }

  public function onPrePublish(WorkspacePrePublishEvent $event): void {
    $workspace = $event->getWorkspace();
    if ($this->workspaceReview->isPublishBlocked($workspace)) {
      $event->stopPublishing();
      $event->setPublishingStoppedReason(\sprintf(
        'The "%s" workspace requires review: it must be approved before it can be published. Its current review state is "%s".',
        (string) $workspace->label(),
        $this->workspaceReview->getStatusLabel($workspace),
      ));
      return;
    }
    // Snapshot-held drafts (code editor working copies, payloads the storage
    // layer rejected) are invisible to core's publish. Only the Canvas
    // publisher stages them into the workspace — or refuses when they are
    // invalid — before this event fires, signalled by its publish-time
    // staging latch. Refuse every other surface (core Workspaces UI, direct
    // API calls): letting them proceed would promote the workspace without
    // those drafts and then silently discard them in the post-publish
    // cleanup.
    if (!$this->workspaceReview->isDemotionSuppressed()
      && $this->workspaceAutoSave->workspaceHasSnapshotRows((string) $workspace->id())) {
      $event->stopPublishing();
      $event->setPublishingStoppedReason(\sprintf(
        'The "%s" workspace has draft changes that cannot be published from here. Publish it from Drupal Canvas instead.',
        (string) $workspace->label(),
      ));
    }
  }

  public function onPostPublish(WorkspacePostPublishEvent $event): void {
    $workspace = $event->getWorkspace();
    $this->workspaceAutoSave->clearWorkspaceStores((string) $workspace->id());
    if ($workspace->id() === AutoSaveWorkspace::ID) {
      // The Main workspace is permanent: a publish consumes the approval and
      // any schedule, and the next editing cycle starts over. An empty state
      // resolves to the review workflow's initial state.
      // @see \Drupal\canvas\Workspace\WorkspaceReview::getStatus()
      $workspace->set('canvas_workspace_status', NULL);
      $workspace->set('canvas_scheduled_publish_at', NULL);
      $workspace->set('canvas_scheduled_publish_by', NULL);
      $workspace->set('canvas_scheduled_publish_error', NULL);
      $workspace->save();
      return;
    }
    // A named workspace is a unit of work; publishing completes it. Its
    // content is live and its staging stores are cleared, so nothing is
    // lost. Sessions still pointing at it re-negotiate to no workspace and
    // the editor falls back to the Main workspace.
    $workspace->delete();
  }

}
