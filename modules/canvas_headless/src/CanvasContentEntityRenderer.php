<?php

declare(strict_types=1);

namespace Drupal\canvas_headless;

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\Entity\ComponentTreeEntityInterface;
use Drupal\canvas\Entity\ContentTemplate;
use Drupal\canvas\Entity\PageVariant;
use Drupal\canvas\EntityHandlers\ContentTemplateAwareViewBuilder;
use Drupal\canvas\PageVariantResolver;
use Drupal\canvas\Plugin\DisplayVariant\CanvasPageVariant;
use Drupal\canvas_headless\RenderConverter\JsComponentCanvasRenderConverter;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Builds content only when Canvas owns the entity's full rendered output.
 */
final class CanvasContentEntityRenderer {

  private const string THEME_PAGE_TEMPLATE_COMPONENT_PREFIX = 'theme_page_template.';

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AutoSaveManager $autoSaveManager,
    private readonly PageVariantResolver $pageVariantResolver,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Resolves and builds the Canvas rendering strategy for an entity.
   *
   * @return array{build: ?array, cacheability: \Drupal\Core\Cache\CacheableMetadata}
   *   The Canvas render array, or NULL when Canvas does not render the entity,
   *   plus dependencies that determined the result.
   */
  public function build(
    ContentEntityInterface $entity,
    string $view_mode,
    bool $is_preview,
  ): array {
    $template = NULL;
    $view_builder = NULL;
    $cacheability = (new CacheableMetadata())
      // A previously unmanaged route can become managed when a headless-
      // compatible site default is selected, so invalidate negative results.
      ->addCacheableDependency($this->configFactory->get('canvas.settings'));
    if ($is_preview) {
      $cacheability->addCacheTags([AutoSaveManager::CACHE_TAG]);
    }

    if ($entity instanceof ComponentTreeEntityInterface) {
      $build = $entity
        ->getComponentTree()
        ->toRenderable($entity, $is_preview);
    }
    else {
      $entity_type = $this->entityTypeManager
        ->getDefinition($entity->getEntityTypeId());
      if ($entity_type->hasHandlerClass('view_builder')) {
        $candidate = $this->entityTypeManager
          ->getViewBuilder($entity->getEntityTypeId());
        if ($candidate instanceof ContentTemplateAwareViewBuilder) {
          $view_builder = $candidate;
        }
      }

      $template = ContentTemplate::loadForEntity($entity, $view_mode);
      $cacheability->addCacheTags(
        $this->entityTypeManager
          ->getDefinition(ContentTemplate::ENTITY_TYPE_ID)
          ->getListCacheTags(),
      );
      if ($is_preview) {
        if ($template !== NULL) {
          $auto_save = $this->autoSaveManager->getAutoSaveEntity($template);
          $cacheability->addCacheableDependency($auto_save);
          if ($auto_save->entity instanceof ContentTemplate) {
            $template = $auto_save->entity;
            $template->setStatus(TRUE);
          }
          elseif (!$template->status()) {
            $template = clone $template;
            $template->setStatus(TRUE);
          }
        }
      }
      if ($template !== NULL) {
        $cacheability->addCacheableDependency($template);
      }
      $build = $view_builder !== NULL && $template !== NULL && ($is_preview || $template->status())
        ? $view_builder->build($view_builder->view($entity, $view_mode))
        : NULL;
    }

    // Page variants provide the chrome for a canonical page. Other view modes
    // render only their content template, matching the coupled preview.
    if ($view_mode !== 'full') {
      return [
        'build' => $build,
        'cacheability' => $cacheability,
      ];
    }

    $variant = $this->pageVariantResolver->resolve(
      $entity,
      $template,
      $is_preview,
    );
    if ($variant === NULL) {
      return [
        'build' => $build,
        'cacheability' => $cacheability,
      ];
    }
    $cacheability->addCacheableDependency($variant);

    // Theme page templates reproduce Drupal's coupled page.html.twig and are
    // not part of a headless application's component tree. Ignore the complete
    // variant rather than rendering its wrapper as markup. The already built
    // main content still determines whether Canvas manages this route.
    if (self::containsThemePageTemplate($variant)) {
      return [
        'build' => $build,
        'cacheability' => $cacheability,
      ];
    }

    // A page variant owns the complete response even when the route's main
    // content is rendered by Drupal rather than by a Canvas content template.
    if ($build === NULL) {
      if ($view_builder === NULL) {
        return self::unsupported($cacheability);
      }
      $build = $view_builder->build($view_builder->view($entity, $view_mode));
    }

    // Keep the editable content region at the page variant's marker. Without
    // this renderless wire element, SDK renderers can only put the region
    // boundary around the complete tree, including page chrome.
    $main_content = $is_preview
      ? [
        JsComponentCanvasRenderConverter::PREVIEW_CONTENT_REGION => TRUE,
        'content' => $build,
      ]
      : $build;
    $messages_block_displayed = FALSE;
    $build = CanvasPageVariant::renderComponentTree(
      $variant->getComponentTree(),
      $variant,
      $is_preview,
      $messages_block_displayed,
      $main_content,
      (string) $entity->label(),
    );
    return [
      'build' => $build,
      'cacheability' => $cacheability,
    ];
  }

  /**
   * Builds the edited page variant itself for a headless editor preview.
   *
   * This renders the auto-saved tree directly, including temporarily invalid
   * drafts. The page content marker therefore remains a visible editor
   * placeholder instead of receiving routed content.
   *
   * @return array{build: ?array, cacheability: \Drupal\Core\Cache\CacheableMetadata}
   *   The page variant render array, or NULL for a theme-backed variant, plus
   *   its cacheability.
   */
  public function buildPageVariantPreview(PageVariant $variant): array {
    $cacheability = (new CacheableMetadata())
      ->addCacheableDependency($variant);
    $auto_save = $this->autoSaveManager->getAutoSaveEntity($variant);
    $cacheability->addCacheableDependency($auto_save);
    if ($auto_save->entity instanceof PageVariant) {
      $variant = $auto_save->entity;
      $cacheability->addCacheableDependency($variant);
    }

    if (self::containsThemePageTemplate($variant)) {
      return self::unsupported($cacheability);
    }

    return [
      'build' => $variant
        ->getComponentTree()
        ->toRenderable($variant, isPreview: TRUE),
      'cacheability' => $cacheability,
    ];
  }

  /**
   * Whether the variant contains Drupal's coupled theme page template.
   */
  private static function containsThemePageTemplate(PageVariant $variant): bool {
    foreach ($variant->getComponentTree()->getValue() as $component) {
      $component_id = $component['component_id'] ?? NULL;
      if (\is_string($component_id) && \str_starts_with($component_id, self::THEME_PAGE_TEMPLATE_COMPONENT_PREFIX)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Returns an unsupported decision with its cacheability.
   *
   * @return array{build: null, cacheability: \Drupal\Core\Cache\CacheableMetadata}
   *   The unsupported decision.
   */
  private static function unsupported(
    ?CacheableMetadata $cacheability = NULL,
  ): array {
    return [
      'build' => NULL,
      'cacheability' => $cacheability ?? new CacheableMetadata(),
    ];
  }

}
