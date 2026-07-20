<?php

declare(strict_types=1);

namespace Drupal\canvas_headless\EventSubscriber;

use Drupal\canvas_headless\Controller\AutoSavePreviewController;
use Drupal\canvas_headless\PreviewTokenInspector;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\lupus_ce_renderer\Controller\CustomElementsController;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\ParameterBag;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Selects Canvas auto-saves for scoped headless Custom Elements previews.
 */
final class AutoSavePreviewControllerSubscriber implements EventSubscriberInterface {

  public function __construct(
    private readonly AccountProxyInterface $currentUser,
    private readonly AutoSavePreviewController $previewController,
  ) {}

  /**
   * Replaces Lupus's routed-entity controller for Canvas preview tokens.
   */
  public function onKernelController(ControllerEvent $event): void {
    $request = $event->getRequest();
    if ($request->getRequestFormat() !== 'custom_elements') {
      return;
    }

    $controller = $event->getController();
    if (!\is_array($controller)
      || !($controller[0] instanceof CustomElementsController)
      || $controller[1] !== 'entityView'
      || !PreviewTokenInspector::hasPreviewScope($this->currentUser->getAccount())) {
      return;
    }

    $parameters = $request->attributes->get('_raw_variables');
    $entity_type_id = $parameters instanceof ParameterBag
      ? ($parameters->keys()[0] ?? NULL)
      : NULL;
    if (!\is_string($entity_type_id)
      || !$request->attributes->get($entity_type_id) instanceof ContentEntityInterface) {
      return;
    }

    $event->setController([$this->previewController, 'entityView']);
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [KernelEvents::CONTROLLER => ['onKernelController', 5]];
  }

}
