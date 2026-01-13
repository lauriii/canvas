<?php

declare(strict_types=1);

namespace Drupal\canvas;

/**
 * Defines routes where content template logic applies.
 *
 * @internal
 */
final class ContentTemplateRoutes {

  /**
   * Route names where content templates apply.
   *
   * This only includes routes where Canvas needs to override access control,
   * controllers, or modify links. The main "Manage display" page
   * (entity.entity_view_display.node.default) is excluded because it only
   * needs cache invalidation, not Canvas control.
   *
   * @todo Remove the hardcoded node "entity_view_display" route check after
   *   https://www.drupal.org/project/canvas/issues/3498525 is resolved.
   */
  private const array VIEW_MODE_ROUTES = [
    'entity.entity_view_display.node.view_mode',
  ];

  /**
   * Determines if content template logic applies to the given route.
   *
   * @param string $route_name
   *   The route name to check.
   *
   * @return bool
   *   TRUE if content template logic applies, FALSE otherwise.
   */
  public static function applies(string $route_name): bool {
    return in_array($route_name, self::VIEW_MODE_ROUTES, TRUE);
  }

}
