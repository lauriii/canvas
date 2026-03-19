<?php

declare(strict_types=1);

namespace Drupal\canvas\Cache;

use Drupal\Core\Cache\Context\RouteNameCacheContext;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Determines if an entity is being viewed in the Canvas editor UI.
 *
 * Cache context ID: 'route.name.is_canvas_editor_ui'.
 *
 * This cache context checks for the '_canvas_use_template_draft' route option
 * to determine if we're in the Canvas editor preview mode, where auto-saved
 * templates should be used, if they exist, instead of published versions.
 *
 * @internal
 */
final class CanvasEditorUiCacheContext extends RouteNameCacheContext {

  /**
   * {@inheritdoc}
   */
  public static function getLabel(): string {
    return (string) new TranslatableMarkup('Canvas editor user interface');
  }

  /**
   * {@inheritdoc}
   */
  public function getContext(): string {
    $route = $this->routeMatch->getRouteObject();
    if ($route?->getOption('_canvas_use_template_draft') === TRUE) {
      return 'is_canvas_editor_ui.1';
    }
    return 'is_canvas_editor_ui.0';
  }

}
