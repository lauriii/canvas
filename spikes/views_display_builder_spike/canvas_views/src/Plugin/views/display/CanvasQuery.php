<?php

declare(strict_types=1);

namespace Drupal\canvas_views\Plugin\views\display;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\views\Attribute\ViewsDisplay;
use Drupal\views\Plugin\views\display\Embed;

/**
 * The query-only display type: a view's exposure point to Canvas.
 *
 * Not routable and renders nothing on its own. It carries the query half of
 * the view (filters, sorts, relationships, contextual filters, items per
 * page) and declares the view's fields through its field handlers. Canvas
 * views displays execute the view through this display; the experience is
 * designed entirely in Canvas.
 */
#[ViewsDisplay(
  id: 'canvas',
  title: new TranslatableMarkup('Canvas'),
  help: new TranslatableMarkup('Exposes this view as a query to Drupal Canvas. The display is designed in Canvas.'),
  theme: 'views_view',
  uses_menu_links: FALSE,
)]
final class CanvasQuery extends Embed {

}
