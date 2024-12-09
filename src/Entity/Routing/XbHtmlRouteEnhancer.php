<?php

declare(strict_types=1);

namespace Drupal\experience_builder\Entity\Routing;

use Drupal\Core\Routing\EnhancerInterface;
use Drupal\Core\Routing\RouteObjectInterface;
use Drupal\experience_builder\Controller\ExperienceBuilderController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Route;

/**
 * Enhances entity form routes that default to Experience Builder.
 *
 * This is about mapping a content entity type's link template's specific route
 * parameter names (for example `{xb_page}`) to the generic `{entity}`.
 *
 * @see \Drupal\experience_builder\Entity\Routing\XbHtmlRouteProvider
 */
final class XbHtmlRouteEnhancer implements EnhancerInterface {

  /**
   * {@inheritdoc}
   */
  public function enhance(array $defaults, Request $request): array {
    $route = $defaults[RouteObjectInterface::ROUTE_OBJECT];
    if (!$this->applies($route)) {
      return $defaults;
    }
    $defaults['_controller'] = ExperienceBuilderController::class;

    $entity_type_id = $route->getDefault('_experience_builder');
    $defaults['entity_type'] = $entity_type_id;

    $defaults['entity'] = NULL;
    if (!empty($defaults[$entity_type_id])) {
      $defaults['entity'] = $defaults[$entity_type_id];
    }

    unset($defaults['_experience_builder']);
    return $defaults;
  }

  /**
   * Checks if the route applies to this enhancer.
   *
   * @param \Symfony\Component\Routing\Route $route
   *   The route to check.
   *
   * @return bool
   *   Whether the route applies to this enhancer.
   */
  private function applies(Route $route): bool {
    return $route->hasDefault('_experience_builder');
  }

}
