<?php

declare(strict_types=1);

namespace Drupal\canvas_headless\Routing;

use Drupal\canvas_headless\Controller\CanvasContentController;
use Drupal\canvas_headless\StackMiddleware\CanvasContentApiRequest;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Routing\EnhancerInterface;
use Drupal\Core\Routing\RouteObjectInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Route;

/**
 * Selects the Canvas response controller for routed content API requests.
 */
final class CanvasContentRouteEnhancer implements EnhancerInterface {

  public const PRIMARY_ENTITY_PARAMETER = '_canvas_headless_primary_entity_param';

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

    $route_name = $defaults[RouteObjectInterface::ROUTE_NAME] ?? NULL;
    if (
      !\is_string($route_name) ||
      !preg_match('/^entity\.([a-z0-9_]+)\.canonical$/', $route_name, $matches)
    ) {
      return $defaults;
    }

    $entity_type_id = $matches[1];
    $entity = $defaults[$entity_type_id] ?? NULL;
    if (
      !$entity instanceof ContentEntityInterface ||
      $entity->getEntityTypeId() !== $entity_type_id
    ) {
      return $defaults;
    }

    $defaults[self::PRIMARY_ENTITY_PARAMETER] = $entity_type_id;
    return $defaults;
  }

}
