<?php

declare(strict_types=1);

namespace Drupal\canvas_headless\Routing;

use Drupal\canvas_headless\Controller\CanvasContentController;
use Drupal\canvas_headless\StackMiddleware\CanvasContentApiRequest;
use Drupal\Core\Routing\EnhancerInterface;
use Drupal\Core\Routing\RouteObjectInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Route;

/**
 * Selects the Canvas response controller for routed content API requests.
 */
final class CanvasContentRouteEnhancer implements EnhancerInterface {

  /**
   * {@inheritdoc}
   */
  public function enhance(array $defaults, Request $request): array {
    if (!\is_string($request->attributes->get(CanvasContentApiRequest::REQUESTED_URI_ATTRIBUTE))) {
      return $defaults;
    }

    $route = $defaults[RouteObjectInterface::ROUTE_OBJECT] ?? NULL;
    if ($route instanceof Route) {
      // The controller may enable template draft rendering for this request.
      $defaults[RouteObjectInterface::ROUTE_OBJECT] = clone $route;
    }
    $defaults['_controller'] = CanvasContentController::class . '::get';

    return $defaults;
  }

}
