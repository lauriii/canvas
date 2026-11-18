<?php

declare(strict_types=1);

namespace Drupal\canvas\EntityHandlers;

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\Entity\ContentTemplate;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\Display\EntityViewDisplayInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityViewBuilder;
use Drupal\Core\Entity\EntityViewBuilderInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Field\FieldItemInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Decorates a view builder so it can take advantage of content templates.
 *
 * Without an enabled content template, the decorated (original) view builder
 * renders the entity, and this class only adds the cacheability needed to
 * start using a template as soon as one is created or enabled. With an enabled
 * template, this class renders the entity using EntityViewBuilder's flow and
 * the template replaces the entity view display.
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
      // A newly created template is stored disabled before it has its first
      // auto-save, but it still needs to render while it is being edited.
      if (!$template->status()) {
        $template = clone $template;
        $template->setStatus(TRUE);
      }
    }

    return $template;
  }

  /**
   * Whether an enabled content template renders any of the given entities.
   *
   * @param \Drupal\Core\Entity\EntityInterface[] $entities
   *   The entities to render.
   * @param string $view_mode
   *   The requested view mode, before `hook_entity_view_mode_alter()`.
   */
  private function hasEnabledTemplate(array $entities, string $view_mode): bool {
    foreach ($entities as $entity) {
      \assert($entity instanceof FieldableEntityInterface);
      // Let modules change the view mode, like ::getBuildDefaults() does.
      $entity_view_mode = $view_mode;
      $this->moduleHandler()->alter([$this->entityTypeId . '_view_mode', 'entity_view_mode'], $entity_view_mode, $entity);
      if ($this->loadTemplate($entity, $entity_view_mode)?->status()) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * {@inheritdoc}
   *
   * Without an enabled content template, the decorated view builder renders the
   * entity, including its own ::view() override, if it has one.
   */
  public function view(EntityInterface $entity, $view_mode = 'full', $langcode = NULL) {
    if ($this->hasEnabledTemplate([$entity], $view_mode)) {
      return parent::view($entity, $view_mode, $langcode);
    }
    $build = $this->decorated->view($entity, $view_mode, $langcode);
    \assert($entity instanceof FieldableEntityInterface);
    $this->addCacheability($build, $entity, $this->loadTemplate($entity, $build['#view_mode'] ?? $view_mode));
    return $build;
  }

  /**
   * {@inheritdoc}
   *
   * Which view builder renders a list depends on its entities:
   * 1. No entity has an enabled content template: the decorated view builder
   *    renders the list, as it would without Canvas.
   * 2. At least one entity has an enabled content template: this view builder
   *    renders the whole list, in one batch. This happens when a list has
   *    entities of several bundles and only some bundles have a template. For
   *    the entities without a template it calls the decorated view builder's
   *    ::getBuildDefaults(), ::buildComponents() and ::alterBuild(), so they
   *    get the same defaults, fields and alterations.
   *
   * In case 2, the decorated view builder's ::view() and ::viewMultiple() are
   * not called: they would render the whole list without templates.
   */
  public function viewMultiple(array $entities = [], $view_mode = 'full', $langcode = NULL) {
    if ($this->hasEnabledTemplate($entities, $view_mode)) {
      return parent::viewMultiple($entities, $view_mode, $langcode);
    }
    $build_list = $this->decorated->viewMultiple($entities, $view_mode, $langcode);
    foreach ($entities as $key => $entity) {
      \assert($entity instanceof FieldableEntityInterface);
      $this->addCacheability($build_list[$key], $entity, $this->loadTemplate($entity, $build_list[$key]['#view_mode'] ?? $view_mode));
    }
    return $build_list;
  }

  /**
   * Builds a render array returned by ::view() now, if it is not built yet.
   *
   * For a caller that needs a built render array because the decorated view
   * builder would have returned one.
   *
   * @param array $build
   *   A render array returned by ::view().
   *
   * @return array
   *   The built render array.
   *
   * @see \Drupal\canvas\Block\BuildsContentBlockImmediatelyTrait
   *
   * @todo Remove when core builds content blocks in its block plugins: https://www.drupal.org/project/drupal/issues/3638853
   *
   * @internal
   */
  public function buildImmediately(array $build): array {
    $key = \array_search([$this, 'build'], $build['#pre_render'] ?? [], TRUE);
    if ($key === FALSE) {
      return $build;
    }
    unset($build['#pre_render'][$key]);
    return $this->build($build);
  }

  /**
   * {@inheritdoc}
   */
  protected function getBuildDefaults(EntityInterface $entity, $view_mode) {
    // PHP allows calling the decorated view builder's protected method, because
    // it is declared in EntityViewBuilder, which both classes extend.
    $defaults = $this->decorated instanceof EntityViewBuilder
      ? $this->decorated->getBuildDefaults($entity, $view_mode)
      : parent::getBuildDefaults($entity, $view_mode);
    \assert($entity instanceof FieldableEntityInterface);
    $template = $this->loadTemplate($entity, $defaults['#view_mode']);
    $this->addCacheability($defaults, $entity, $template);

    // Content templates own the complete output, including new entities and
    // non-default revisions, which do not have render cache keys.
    if ($template && $template->status()) {
      unset($defaults['#theme']);
    }
    return $defaults;
  }

  /**
   * Adds the cacheability for the content template (or its absence).
   *
   * @param array $build
   *   The render array of a single entity.
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The entity being rendered.
   * @param \Drupal\canvas\Entity\ContentTemplate|null $template
   *   The content template for the entity and view mode, if any.
   */
  private function addCacheability(array &$build, FieldableEntityInterface $entity, ?ContentTemplate $template): void {
    // The rendered output varies based on whether we're in the Canvas editor UI
    // (preview mode). Use a specialized cache context that's narrower than
    // `route.name` to avoid creating separate cache entries per route, which
    // would hurt cache hit ratios for teasers rendered on multiple pages.
    // @see \Drupal\canvas\Cache\CanvasEditorUiCacheContext
    $build['#cache']['contexts'][] = 'route.name.is_canvas_editor_ui';
    // Only add the auto-save cache tag on preview routes to avoid invalidating
    // all rendered nodes on the live site when auto-saves change. This cache
    // tag ensures preview mode shows the latest auto-saved content.
    if ($this->isPreview()) {
      $build['#cache']['tags'][] = AutoSaveManager::CACHE_TAG;
    }

    // If a template exists, no matter if disabled, this render array depends
    // on it changing.
    if ($template) {
      $cacheability = CacheableMetadata::createFromRenderArray($build)
        ->addCacheableDependency($template);
      // Exposed slots render the entity's own slot fields, so saving the
      // entity must invalidate the output.
      if ($template->getExposedSlots() !== []) {
        $cacheability->addCacheableDependency($entity);
      }
      $cacheability->applyTo($build);
    }
    // We need to ensure that as soon as a content template is added, we are
    // using it.
    else {
      CacheableMetadata::createFromRenderArray($build)
        ->addCacheTags(
          $this->entityTypeManager->getStorage(ContentTemplate::ENTITY_TYPE_ID)->getEntityType()->getListCacheTags()
        )->applyTo($build);
    }

    $keys = NestedArray::getValue($build, ['#cache', 'keys']);
    if ($keys !== NULL) {
      if ($template && $template->status()) {
        // This entity has render caching, so add a cache key indicating whether
        // or not it's opted into Canvas.
        $keys[] = 'with-canvas';
      }
      else {
        $keys[] = 'without-canvas';
      }
      NestedArray::setValue($build, ['#cache', 'keys'], $keys);
    }
  }

  /**
   * {@inheritdoc}
   *
   * Only calls the decorated view builder for an entity without an enabled
   * content template. With one, the render array is the template's component
   * tree, not what an existing ::alterBuild() expects.
   */
  protected function alterBuild(array &$build, EntityInterface $entity, EntityViewDisplayInterface $display, $view_mode): void {
    \assert($entity instanceof FieldableEntityInterface);
    if ($this->decorated instanceof EntityViewBuilder && !$this->loadTemplate($entity, $view_mode)?->status()) {
      $this->decorated->alterBuild($build, $entity, $display, $view_mode);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function viewField(FieldItemListInterface $items, $display_options = []) {
    return $this->decorated->viewField($items, $display_options);
  }

  /**
   * {@inheritdoc}
   */
  public function viewFieldItem(FieldItemInterface $item, $display = []) {
    return $this->decorated->viewFieldItem($item, $display);
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
