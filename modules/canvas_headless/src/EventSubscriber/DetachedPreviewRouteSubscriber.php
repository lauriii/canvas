<?php

declare(strict_types=1);

namespace Drupal\canvas_headless\EventSubscriber;

use Drupal\canvas_headless\PreviewTokenInspector;
use Drupal\canvas_headless\StackMiddleware\CanvasContentApiRequest;
use Drupal\Core\Routing\CacheableRouteProviderInterface;
use Drupal\Core\Routing\RouteProviderInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Prepares authenticated detached preview routing.
 */
final class DetachedPreviewRouteSubscriber implements EventSubscriberInterface {

  public function __construct(
    private readonly AccountProxyInterface $currentUser,
    private readonly RouteProviderInterface $routeProvider,
  ) {}

  /**
   * Separates authenticated preview route matches from public requests.
   */
  public function prepareDetachedPreviewRouting(RequestEvent $event): void {
    if (
      !$event->isMainRequest() ||
      $event->getRequest()->attributes->get(CanvasContentApiRequest::DETACHED_PREVIEW_ATTRIBUTE) !== TRUE
    ) {
      return;
    }
    $has_preview_scope = PreviewTokenInspector::hasPreviewScope($this->currentUser->getAccount());
    if ($has_preview_scope) {
      // The matched route differs from the frontend URI retained by detached
      // previews. Prevent Redirect from normalizing it to the internal route.
      $event->getRequest()->attributes->set('_disable_route_normalizer', TRUE);
    }
    if ($this->routeProvider instanceof CacheableRouteProviderInterface) {
      $this->routeProvider->addExtraCacheKeyPart(
        'canvas_headless_detached_preview',
        $has_preview_scope ? '1' : '0',
      );
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // Authentication runs at priority 300. Run after it but immediately before
    // Symfony's router listener at priority 32.
    $events[KernelEvents::REQUEST][] = ['prepareDetachedPreviewRouting', 33];
    return $events;
  }

}
