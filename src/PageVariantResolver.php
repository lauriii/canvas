<?php

declare(strict_types=1);

namespace Drupal\canvas;

use Drupal\canvas\AutoSave\AutoSaveManager;
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
    private readonly AutoSaveManager $autoSaveManager,
  ) {}

  /**
   * Resolves the page variant for the given route entity, if any.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface|null $entity
   *   The content entity being rendered on the route, or NULL for routes that
   *   render no content entity (the site default still applies).
   * @param \Drupal\canvas\Entity\ContentTemplate|null $content_template
   *   The already selected full-view content template, including its auto-save
   *   during a preview. When omitted, the published full-view template is
   *   loaded.
   * @param bool $is_preview
   *   Whether to return a valid auto-saved draft of the resolved variant.
   *
   * @return \Drupal\canvas\Entity\PageVariant|null
   *   The resolved variant, or NULL when none applies (core block layout).
   */
  public function resolve(
    ?FieldableEntityInterface $entity,
    ?ContentTemplate $content_template = NULL,
    bool $is_preview = FALSE,
  ): ?PageVariant {
    // 1. A canvas_page's own selection.
    if ($entity instanceof Page) {
      $variant = self::loadVariant($entity->get('page_variant')->value);
      if ($variant !== NULL) {
        return $is_preview
          ? $this->resolvePreviewVariant($variant)
          : $variant;
      }
    }
    // 2. The matching content template's selection (full view mode). A
    // disabled template never renders the entity, so its selection must not
    // dictate the page chrome either.
    elseif ($entity !== NULL) {
      $template = $content_template ?? ContentTemplate::loadForEntity($entity, 'full');
      if ($template !== NULL && $template->status()) {
        $variant = self::loadVariant($template->getPageVariant());
        if ($variant !== NULL) {
          return $is_preview
            ? $this->resolvePreviewVariant($variant)
            : $variant;
        }
      }
    }
    // 3. The site default.
    $default = $this->configFactory->get('canvas.settings')->get('default_page_variant');
    $variant = self::loadVariant($default);
    return $variant !== NULL && $is_preview
      ? $this->resolvePreviewVariant($variant)
      : $variant;
  }

  /**
   * Returns the page variant that should render in a preview.
   *
   * Invalid auto-saved drafts are ignored because auto-saves are written
   * without validation. The published variant remains renderable while an
   * invalid edit is in progress.
   */
  public function resolvePreviewVariant(PageVariant $variant): PageVariant {
    $auto_save = $this->autoSaveManager->getAutoSaveEntity($variant);
    if (
      $auto_save->entity instanceof PageVariant &&
      \count($auto_save->entity->getTypedData()->validate()) === 0
    ) {
      return $auto_save->entity;
    }
    return $variant;
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
