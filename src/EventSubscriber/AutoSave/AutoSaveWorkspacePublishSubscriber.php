<?php

declare(strict_types=1);

namespace Drupal\canvas\EventSubscriber\AutoSave;

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
 * Post-publish: clears every Canvas staging store for the workspace (core
 * clears the association), cancels any schedule, and resets the review
 * state for the next editing cycle.
 *
 * @see \Drupal\canvas\Workspace\CanvasWorkspacePublisher
 * @see \Drupal\workspaces\WorkspacePublisher::publish()
 */
final class AutoSaveWorkspacePublishSubscriber implements EventSubscriberInterface {

  public function __construct(
    private readonly WorkspaceReview $workspaceReview,
    private readonly WorkspaceAutoSave $workspaceAutoSave,
  ) {}

  public static function getSubscribedEvents(): array {
    return [
      WorkspacePrePublishEvent::class => 'onPrePublish',
      WorkspacePostPublishEvent::class => 'onPostPublish',
    ];
  }

  public function onPrePublish(WorkspacePrePublishEvent $event): void {
    $workspace = $event->getWorkspace();
    if ($this->workspaceReview->isPublishBlocked($workspace)) {
      $event->stopPublishing();
      $event->setPublishingStoppedReason(\sprintf(
        'The "%s" workspace requires review: it must be approved before it can be published. Its current review state is "%s".',
        (string) $workspace->label(),
        $this->workspaceReview->getStatus($workspace),
      ));
    }
  }

  public function onPostPublish(WorkspacePostPublishEvent $event): void {
    $workspace = $event->getWorkspace();
    $this->workspaceAutoSave->clearWorkspaceStores((string) $workspace->id());
    // A publish consumes the approval and any schedule; the next editing
    // cycle starts from draft.
    $workspace->set('canvas_workspace_status', WorkspaceReview::STATUS_DRAFT);
    $workspace->set('canvas_scheduled_publish_at', NULL);
    $workspace->set('canvas_scheduled_publish_by', NULL);
    $workspace->set('canvas_scheduled_publish_error', NULL);
    $workspace->save();
  }

}
