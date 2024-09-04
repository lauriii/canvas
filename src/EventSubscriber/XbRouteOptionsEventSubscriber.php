<?php

declare(strict_types=1);

namespace Drupal\experience_builder\EventSubscriber;

use Drupal\Core\Routing\RouteMatchInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Affects only XB-owned routes.
 *
 * @internal
 */
final class XbRouteOptionsEventSubscriber implements EventSubscriberInterface {

  public function __construct(
    private readonly RouteMatchInterface $routeMatch,
  ) {}

  public function transformWrapperFormatRouteOption(RequestEvent $event): void {
    if (!str_starts_with($this->routeMatch->getRouteName() ?? '', 'experience_builder.api.')) {
      return;
    }

    // Allow XB routes to declare they must always use a particular main content
    // renderer, by accepting a `_wrapper_format` route option that is upcast
    // to the URL query parameter that Drupal core expects.
    // @see \Drupal\Core\EventSubscriber\MainContentViewSubscriber::WRAPPER_FORMAT
    // @see \Drupal\experience_builder\Render\MainContent\XBEndpointRenderer
    $route_object = $this->routeMatch->getRouteObject();
    if (!is_null($route_object) && $wrapper_format = $route_object->getOption('_wrapper_format')) {
      $event->getRequest()->query->set('_wrapper_format', $wrapper_format);
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    $events[KernelEvents::REQUEST][] = ['transformWrapperFormatRouteOption'];
    return $events;
  }

}
