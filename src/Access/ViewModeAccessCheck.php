<?php

declare(strict_types=1);

namespace Drupal\canvas\Access;

use Drupal\canvas\ContentTemplateRoutes;
use Drupal\canvas\Entity\ContentTemplate;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\Routing\Route;

/**
 * Defines an access checker for canvas content template entity.
 */
class ViewModeAccessCheck implements AccessInterface {

  public function __construct(
    private readonly AccessInterface $inner,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function access(Route $route, RouteMatchInterface $route_match, AccountInterface $account, string $view_mode_name = 'default', ?string $bundle = NULL): AccessResultInterface {
    // Check if a ContentTemplate access applies to this route.
    if ($route_match->getRouteName() && ContentTemplateRoutes::applies($route_match->getRouteName())) {
      $template = $this->loadFromRoute($view_mode_name, $route_match, $bundle);
      $access = AccessResult::neutral();
      if ($template) {
        // Add the ContentTemplate as a cacheable dependency. As the access
        // check needs to rebuild when content templates are added or updated.
        $access = $access->addCacheableDependency($template);
        if ($template->status()) {
          // If the content template exists and is enabled, allow access based
          // on the required permission.
          return $access->allowedIfHasPermission($account, ContentTemplate::ADMIN_PERMISSION);
        }
      }
    }
    // The AccessInterface class does not define the access method. Hence,
    // we ignore. Possibly will be fixed in https://www.drupal.org/node/2266817
    // @phpstan-ignore-next-line
    return $this->inner->access($route, $route_match, $account, $view_mode_name, $bundle);
  }

  /**
   * Loads a ContentTemplate from the current route context.
   *
   * This method assumes the route has specific structure (entity_type_id
   * default and bundle parameter) which currently limits it to Field UI's
   * entity view display routes.
   *
   * @param string $view_mode
   *   The view mode machine name.
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The route match.
   * @param string|null $bundle
   *   The bundle machine name. If NULL, will be extracted from route.
   *
   * @return \Drupal\canvas\Entity\ContentTemplate|null
   *   The loaded ContentTemplate, or NULL if not found or route structure
   *   doesn't match expectations.
   *
   * @todo This method has limited scope and only works with a tiny subset of
   *   routes (Field UI entity view display routes). It will need to be
   *   refactored when Canvas is generalized beyond nodes. See
   *   https://www.drupal.org/project/canvas/issues/3498525.
   */
  private function loadFromRoute(string $view_mode, RouteMatchInterface $route_match, ?string $bundle = NULL): ?ContentTemplate {
    $entity_type_id = $route_match->getRouteObject()?->getDefault('entity_type_id');
    if (!$entity_type_id) {
      return NULL;
    }

    if (empty($bundle)) {
      $entity_type = $this->entityTypeManager->getDefinition($entity_type_id);
      if ($bundle_entity_type = $entity_type->getBundleEntityType()) {
        $bundle = $route_match->getRawParameter($bundle_entity_type);
      }
    }
    return ContentTemplate::load("$entity_type_id.$bundle.$view_mode");
  }

}
