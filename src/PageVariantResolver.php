<?php

declare(strict_types=1);

namespace Drupal\canvas;

use Drupal\canvas\Entity\ContentTemplate;
use Drupal\canvas\Entity\Page;
use Drupal\canvas\Entity\PageVariant;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\FieldableEntityInterface;

/**
 * Resolves which page variant renders a given request.
 *
 * The resolution chain is: the content entity's own selection (canvas_page
 * only), then the matching content template's selection, then the site default
 * (`canvas.settings:default_page_variant`), then none. A selection that points
 * at a variant which no longer exists is skipped, so resolution falls through
 * to the next step (ultimately the default) rather than failing.
 *
 * @see \Drupal\canvas\EventSubscriber\PageVariantSelectorSubscriber
 * @see \Drupal\canvas\Plugin\DisplayVariant\CanvasPageVariant
 */
final class PageVariantResolver {

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Resolves the page variant for the given route entity, if any.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface|null $entity
   *   The content entity being rendered on the route, or NULL for routes that
   *   render no content entity (the site default still applies).
   *
   * @return \Drupal\canvas\Entity\PageVariant|null
   *   The resolved variant, or NULL when none applies (core block layout).
   */
  public function resolve(?FieldableEntityInterface $entity): ?PageVariant {
    // 1. A canvas_page's own selection.
    if ($entity instanceof Page) {
      $variant = self::loadVariant($entity->get('page_variant')->value);
      if ($variant !== NULL) {
        return $variant;
      }
    }
    // 2. The matching content template's selection (full view mode).
    elseif ($entity !== NULL) {
      $template = ContentTemplate::loadForEntity($entity, 'full');
      if ($template !== NULL) {
        $variant = self::loadVariant($template->getPageVariant());
        if ($variant !== NULL) {
          return $variant;
        }
      }
    }
    // 3. The site default.
    $default = $this->configFactory->get('canvas.settings')->get('default_page_variant');
    return self::loadVariant($default);
  }

  /**
   * Loads a page variant by id, tolerating null/empty/missing ids.
   */
  private static function loadVariant(mixed $id): ?PageVariant {
    if (!\is_string($id) || $id === '') {
      return NULL;
    }
    return PageVariant::load($id);
  }

}
