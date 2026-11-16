<?php

declare(strict_types=1);

namespace Drupal\canvas\Access;

use Drupal\canvas\ContentTemplateRepository;
use Drupal\canvas\Entity\ContentTemplate;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Cache\RefinableCacheableDependencyInterface;
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
    // Only the view mode display route, whose controller Canvas replaces, is
    // decided by the template permission. Other routes using this access check
    // (for example Layout Builder's `layout_builder.defaults.*`) are not.
    // @see \Drupal\canvas\Routing\ViewModeRouteSubscriber::alterRoutes()
    $entity_type_id = $route->getDefault('entity_type_id');
    if (!\is_string($entity_type_id) || $route_match->getRouteName() !== ContentTemplateRepository::getViewModeDisplayRouteName($entity_type_id)) {
      // @phpstan-ignore-next-line method.notFound
      return $this->inner->access($route, $route_match, $account, $view_mode_name, $bundle);
    }
    $template = $this->loadFromRoute($view_mode_name, $route_match, $bundle);
    // Add the ContentTemplate as a cacheable dependency, so access rebuilds
    // when content templates are enabled or disabled. When no template exists,
    // the list cache tag rebuilds access when one is created.
    $cacheable_metadata = new CacheableMetadata();
    if ($template !== NULL) {
      $cacheable_metadata->addCacheableDependency($template);
    }
    else {
      $cacheable_metadata->addCacheTags($this->entityTypeManager->getDefinition(ContentTemplate::ENTITY_TYPE_ID)->getListCacheTags());
    }
    if ($template?->status()) {
      return AccessResult::allowedIfHasPermission($account, ContentTemplate::ADMIN_PERMISSION)
        ->addCacheableDependency($cacheable_metadata);
    }
    // AccessInterface does not declare access(). Possibly will be fixed in
    // https://www.drupal.org/node/2266817.
    // @phpstan-ignore-next-line method.notFound
    $access = $this->inner->access($route, $route_match, $account, $view_mode_name, $bundle);
    // AccessResultInterface does not declare addCacheableDependency(), so the
    // decorated access checker is only allowed to return an access result that
    // is also refinable.
    \assert($access instanceof AccessResultInterface);
    \assert($access instanceof RefinableCacheableDependencyInterface);
    return $access->addCacheableDependency($cacheable_metadata);
  }

  /**
   * Loads a ContentTemplate from the current route context.
   *
   * Assumes the route has an `entity_type_id` default and, for entity
   * types with bundles, a route parameter named after the bundle entity type —
   * the standard structure of Field UI entity view display routes.
   *
   * @param string $view_mode
   *   The view mode machine name.
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The route match.
   * @param string|null $bundle
   *   The bundle machine name. If NULL, will be extracted from route.
   *
   * @return \Drupal\canvas\Entity\ContentTemplate|null
   *   The loaded ContentTemplate, or NULL if not found or the route structure
   *   doesn't match expectations.
   */
  private function loadFromRoute(string $view_mode, RouteMatchInterface $route_match, ?string $bundle = NULL): ?ContentTemplate {
    $entity_type_id = $route_match->getRouteObject()?->getDefault('entity_type_id');
    if (!$entity_type_id) {
      return NULL;
    }

    if (empty($bundle)) {
      $bundle_entity_type = $this->entityTypeManager
        ->getDefinition($entity_type_id)
        ->getBundleEntityType();
      // Entity types without a bundle entity type have a single implicit bundle
      // equal to the entity type ID.
      $bundle = $bundle_entity_type === NULL
        ? $entity_type_id
        : $route_match->getRawParameter($bundle_entity_type);
    }
    if (!\is_string($bundle) || $bundle === '') {
      return NULL;
    }
    return ContentTemplate::loadFor($entity_type_id, $bundle, $view_mode);
  }

}
