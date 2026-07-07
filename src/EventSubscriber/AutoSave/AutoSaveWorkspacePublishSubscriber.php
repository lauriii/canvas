<?php

declare(strict_types=1);

namespace Drupal\canvas\EventSubscriber\AutoSave;

use Drupal\canvas\AutoSave\Workspace\AutoSaveWorkspace;
use Drupal\workspaces\Event\WorkspacePrePublishEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Stops core workspace-level publishing of the Canvas auto-save workspace.
 *
 * Core's Workspace::publish() pushes every staged revision live without
 * validating any entity, and programmatic calls do not check access. Canvas
 * publishes selectively: each item is validated and access checked
 * individually before its first live write. Stopping the pre-publish event
 * makes WorkspacePublisher::publish() throw a WorkspacePublishException, so
 * no code path can push unvalidated staged auto-saves live.
 *
 * @see \Drupal\canvas\AutoSave\Workspace\CanvasWorkspaceProvider::checkAccess()
 * @see \Drupal\canvas\Controller\ApiAutoSaveController::post()
 * @see \Drupal\workspaces\WorkspacePublisher::publish()
 */
final class AutoSaveWorkspacePublishSubscriber implements EventSubscriberInterface {

  public static function getSubscribedEvents(): array {
    return [WorkspacePrePublishEvent::class => 'onPrePublish'];
  }

  public static function onPrePublish(WorkspacePrePublishEvent $event): void {
    if ($event->getWorkspace()->id() === AutoSaveWorkspace::ID) {
      $event->stopPublishing();
      $event->setPublishingStoppedReason('The Canvas workspace can only be published through the Canvas publish endpoint, which validates and access checks each item.');
    }
  }

}
