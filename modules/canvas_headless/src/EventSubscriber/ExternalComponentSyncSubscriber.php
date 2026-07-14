<?php

declare(strict_types=1);

namespace Drupal\canvas_headless\EventSubscriber;

use Drupal\canvas\Controller\CanvasController;
use Drupal\canvas_headless\ExternalComponentSync;
use Drupal\canvas_headless\PreviewUrlGeneratorInterface;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Synchronizes external components when the Canvas UI boots.
 */
final class ExternalComponentSyncSubscriber implements EventSubscriberInterface {

  public function __construct(
    private readonly ExternalComponentSync $synchronizer,
    private readonly AccountInterface $currentUser,
  ) {}

  /**
   * Synchronizes external component metadata before Canvas builds its page.
   */
  public function onController(ControllerEvent $event): void {
    if (!$event->isMainRequest()) {
      return;
    }

    $controller = $event->getController();
    $controller = \is_array($controller) ? $controller[0] : $controller;
    if (!$controller instanceof CanvasController) {
      return;
    }
    if (!$this->currentUser->hasPermission(PreviewUrlGeneratorInterface::PREVIEW_PERMISSION)) {
      return;
    }

    $this->synchronizer->synchronize();
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [KernelEvents::CONTROLLER => 'onController'];
  }

}
