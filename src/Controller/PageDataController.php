<?php

declare(strict_types=1);

namespace Drupal\canvas\Controller;

use Drupal\canvas\CodeComponentDataProvider;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Routing\RequestContext;
use Drupal\Core\Routing\RouteMatch;
use Drupal\Core\Routing\RouteProviderInterface;

/**
 * Returns the page-level canvasData.v0 fields for an explicit target entity.
 *
 * The site-level counterpart is SiteDataController; both delegate the field
 * computation to CodeComponentDataProvider. This endpoint exists for preview
 * environments (e.g. the Workbench dev server) that render code components
 * outside a Drupal page render and therefore have no route-matched entity to
 * derive `pageTitle`, `breadcrumbs`, and `mainEntity` from.
 *
 * @internal This HTTP API is intended only for the Canvas UI. These controllers
 *   and associated routes may change at any time.
 */
final class PageDataController extends ApiControllerBase {

  public function __construct(
    private readonly CodeComponentDataProvider $codeComponentDataProvider,
    private readonly RouteProviderInterface $routeProvider,
    private readonly RequestContext $requestContext,
  ) {}

  public function get(EntityInterface $entity): CacheableJsonResponse {
    $provider = $this->codeComponentDataProvider;
    $cacheability = new CacheableMetadata();
    // The entity's own cache tags: invalidated when the entity or any of its
    // translations is saved or deleted. The returned translation follows the
    // negotiated content language (e.g. via a `?canvas_preview_langcode`
    // redirect to a language-prefixed URL), so downstream caches must vary on
    // it too.
    $cacheability->addCacheableDependency($entity);
    $cacheability->addCacheContexts(['languages:language_content']);

    // Breadcrumbs come from the site's breadcrumb builders, which build for a
    // route match of the page being rendered. This route is an API route, so
    // construct a route match for the entity's canonical route — the route a
    // real render of this entity would match. For the core-standard canonical
    // routes of content entity types the route parameter name equals the
    // entity type ID.
    try {
      $url = $entity->toUrl('canonical');
      $route_name = $url->getRouteName();
      \assert(\is_string($route_name));
      $route = $this->routeProvider->getRouteByName($route_name);
      $route_match = new RouteMatch(
        $route_name,
        $route,
        [$entity->getEntityTypeId() => $entity],
        [$entity->getEntityTypeId() => (string) $entity->id()],
      );
      // Path-based breadcrumb builders (e.g. core's default) read the current
      // path from the router's request context, which on this route is the API
      // path — its `/canvas` ancestor would surface as a spurious crumb. Point
      // the context at the entity's canonical path (as a real render of the
      // entity would see it) for the duration of the build.
      $canonical_path = $url->toString();
      $base_url = $this->requestContext->getBaseUrl();
      if ($base_url !== '' && \str_starts_with($canonical_path, $base_url)) {
        $canonical_path = \substr($canonical_path, \strlen($base_url));
      }
      $original_path_info = $this->requestContext->getPathInfo();
      $this->requestContext->setPathInfo($canonical_path);
      try {
        $breadcrumbs = $provider->getCanvasDataBreadcrumbsV0($route_match, $cacheability);
      }
      finally {
        $this->requestContext->setPathInfo($original_path_info);
      }
    }
    catch (\Exception) {
      // An entity type with no canonical link template or a non-standard
      // canonical route must not break the endpoint: `pageTitle` and
      // `mainEntity` remain independently useful, so fall back to no
      // breadcrumbs instead of a 500.
      $breadcrumbs = [CodeComponentDataProvider::V0 => ['breadcrumbs' => []]];
    }

    $data = NestedArray::mergeDeep(
      $provider->getCanvasDataPageTitleV0($entity),
      $breadcrumbs,
      $provider->getCanvasDataMainEntityV0($cacheability, $entity),
    );
    $response = new CacheableJsonResponse($data[CodeComponentDataProvider::V0]);
    $response->addCacheableDependency($cacheability);
    return $response;
  }

}
