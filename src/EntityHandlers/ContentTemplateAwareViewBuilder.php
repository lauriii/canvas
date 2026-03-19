<?php

declare(strict_types=1);

namespace Drupal\canvas\EntityHandlers;

use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityViewBuilder;
use Drupal\Core\Entity\EntityViewBuilderInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\Entity\ContentTemplate;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Decorates a view builder so it can take advantage of content templates.
 *
 * @see \Drupal\canvas\Hook\ContentTemplateHooks::entityTypeAlter()
 */
final class ContentTemplateAwareViewBuilder extends EntityViewBuilder {

  /**
   * The key under which we store the original view builder class name.
   *
   * @var string
   */
  public const string DECORATED_HANDLER_KEY = 'canvas_original_view_builder';

  /**
   * The decorated view builder.
   *
   * @var \Drupal\Core\Entity\EntityViewBuilderInterface
   */
  private EntityViewBuilderInterface $decorated;

  private EntityTypeManagerInterface $entityTypeManager;

  private RouteMatchInterface $routeMatch;

  private AutoSaveManager $autoSaveManager;

  /**
   * {@inheritdoc}
   */
  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_type): self {
    $instance = parent::createInstance($container, $entity_type);

    $instance->entityTypeManager = $container->get(EntityTypeManagerInterface::class);
    $instance->routeMatch = $container->get(RouteMatchInterface::class);
    $instance->autoSaveManager = $container->get(AutoSaveManager::class);
    $original_view_builder = $instance->entityTypeManager
      ->getHandler($entity_type->id(), self::DECORATED_HANDLER_KEY);
    \assert($original_view_builder instanceof EntityViewBuilderInterface);
    $instance->decorated = $original_view_builder;

    return $instance;
  }

  /**
   * Checks if we're on a preview route (e.g., inside the Canvas editor).
   *
   * @return bool
   *   TRUE if on a preview route, FALSE otherwise.
   */
  private function isPreview(): bool {
    $route = $this->routeMatch->getRouteObject();
    return $route?->getOption('_canvas_use_template_draft') === TRUE;
  }

  /**
   * Loads the appropriate content template, using auto-save version in preview.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The entity to load a content template for.
   * @param string $view_mode
   *   The view mode.
   *
   * @return \Drupal\canvas\Entity\ContentTemplate|null
   *   The content template, or NULL if none exists.
   */
  private function loadTemplate(FieldableEntityInterface $entity, string $view_mode): ?ContentTemplate {
    $template = ContentTemplate::loadForEntity($entity, $view_mode);
    if ($template === NULL) {
      return NULL;
    }

    if ($this->isPreview()) {
      // Use the auto-saved version of the template if available.
      $autoSaveData = $this->autoSaveManager->getAutoSaveEntity($template);
      if (!$autoSaveData->isEmpty()) {
        \assert($autoSaveData->entity instanceof ContentTemplate);
        // If we are using the auto-save template it might not have been
        // published yet, but we still want to use it in preview.
        $autoSaveData->entity->setStatus(TRUE);
        return $autoSaveData->entity;
      }
    }

    return $template;
  }

  /**
   * {@inheritdoc}
   */
  protected function getBuildDefaults(EntityInterface $entity, $view_mode) {
    $defaults = parent::getBuildDefaults($entity, $view_mode);
    \assert($entity instanceof FieldableEntityInterface);
    $template = $this->loadTemplate($entity, $view_mode);

    // The rendered output varies based on whether we're in the Canvas editor UI
    // (preview mode). Use a specialized cache context that's narrower than
    // `route.name` to avoid creating separate cache entries per route, which
    // would hurt cache hit ratios for teasers rendered on multiple pages.
    // @see \Drupal\canvas\Cache\CanvasEditorUiCacheContext
    $defaults['#cache']['contexts'][] = 'route.name.is_canvas_editor_ui';
    // Only add the auto-save cache tag on preview routes to avoid invalidating
    // all rendered nodes on the live site when auto-saves change. This cache
    // tag ensures preview mode shows the latest auto-saved content.
    if ($this->isPreview()) {
      $defaults['#cache']['tags'][] = AutoSaveManager::CACHE_TAG;
    }

    // If a template exists, no matter if disabled, this render array depends
    // on it changing.
    if ($template) {
      CacheableMetadata::createFromObject($template)->applyTo($defaults);
    }
    // We need to ensure that as soon as a content template is added, we are
    // using it.
    else {
      CacheableMetadata::createFromRenderArray($defaults)
        ->addCacheTags(
          $this->entityTypeManager->getStorage(ContentTemplate::ENTITY_TYPE_ID)->getEntityType()->getListCacheTags()
        )->applyTo($defaults);
    }

    $keys = NestedArray::getValue($defaults, ['#cache', 'keys']);
    if ($keys !== NULL) {
      if ($template && $template->status()) {
        // This entity has render caching, so add a cache key indicating whether
        // or not it's opted into Canvas.
        $keys[] = 'with-canvas';
        // We don't want to use the default theme template (such as
        // `node.html.twig`) because any content entity type that uses Canvas'
        // ContentTemplates is opting in to full control via Canvas.
        unset($defaults['#theme']);
      }
      else {
        $keys[] = 'without-canvas';
      }
      NestedArray::setValue($defaults, ['#cache', 'keys'], $keys);
    }
    return $defaults;
  }

  /**
   * {@inheritdoc}
   */
  public function buildComponents(array &$build, array $entities, array $displays, $view_mode): void {
    foreach ($entities as $entity) {
      $bundle = $entity->bundle();

      // We already have a template which will render this entity.
      if ($displays[$bundle] instanceof ContentTemplate) {
        continue;
      }

      // See if we can find a template for this entity, in the requested view
      // mode. If we do, use that template to render the entity only if the
      // status is set to true.
      \assert($entity instanceof FieldableEntityInterface);
      $template = $this->loadTemplate($entity, $view_mode);
      if ($template && $template->status()) {
        $displays[$bundle] = $template;
      }
    }
    // Call the decorated buildComponents() method, just like our parent method
    // would do, to stay as close as possible to the original execution flow.
    // This means `hook_entity_prepare_view()` will still be invoked. Then,
    // `ContentTemplate::buildMultiple()` will be called for the entities that
    // are being rendered by Canvas, which in turn will call
    // `ComponentTreeHydrated::toRenderable()`.
    // @see \Drupal\Core\Entity\EntityViewBuilder::buildComponents()
    // @see \Drupal\canvas\Entity\ContentTemplate::buildMultiple()
    // @see \Drupal\canvas\Plugin\DataType\ComponentTreeHydrated::toRenderable()
    $this->decorated->buildComponents($build, $entities, $displays, $view_mode);

    $is_preview = $this->isPreview();
    foreach ($entities as $id => $entity) {
      // Vary cache between preview and live representations.
      $build[$id]['#cache']['contexts'][] = 'route.name.is_canvas_editor_ui';
      // Only add the auto-save cache tag on preview routes to avoid
      // invalidating all rendered nodes on the live site.
      if ($is_preview) {
        $build[$id]['#cache']['tags'][] = AutoSaveManager::CACHE_TAG;
      }
    }
  }

}
