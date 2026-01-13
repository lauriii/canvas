<?php

declare(strict_types=1);

namespace Drupal\canvas\Routing;

use Drupal\canvas\ContentTemplateRoutes;
use Drupal\canvas\Controller\ViewModeDisplayController;
use Drupal\Core\Routing\RouteSubscriberBase;
use Drupal\Core\Routing\RoutingEvents;
use Symfony\Component\Routing\RouteCollection;

/**
 * Alters routes for view mode display to use Canvas alternative.
 */
final class ViewModeRouteSubscriber extends RouteSubscriberBase {

  /**
   * {@inheritdoc}
   */
  protected function alterRoutes(RouteCollection $collection): void {
    $routes = $collection->all();
    foreach ($routes as $route_name => $route) {
      if (ContentTemplateRoutes::applies($route_name)) {
        $route->setDefault('_controller', ViewModeDisplayController::class);
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    $events = parent::getSubscribedEvents();
    // Register alterRoutes with high priority to ensure it runs after
    // "field_ui" route subscriber.
    // @see \Drupal\field_ui\Routing\RouteSubscriber::getSubscribedEvents()
    $events[RoutingEvents::ALTER] = ['onAlterRoutes', -110];
    return $events;
  }

}
