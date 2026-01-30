<?php

declare(strict_types=1);

namespace Drupal\canvas\Hook;

use Drupal\canvas\ContentTemplateRoutes;
use Drupal\canvas\Entity\ContentTemplate;
use Drupal\Core\Cache\RefinableCacheableDependencyInterface;
use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\Core\Entity\Display\EntityFormDisplayInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityPublishedInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\canvas\Entity\Page;
use Drupal\canvas\EntityHandlers\ContentTemplateAwareViewBuilder;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\Core\Url;

/**
 * @see \Drupal\canvas\Entity\ContentTemplate
 * @see \Drupal\canvas\EntityHandlers\ContentTemplateAwareViewBuilder
 */
final class ContentTemplateHooks {

  public function __construct(
    private readonly RouteMatchInterface $routeMatch,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntityFieldManagerInterface $entityFieldManager,
  ) {
  }

  /**
   * Implements hook_entity_form_display_alter().
   */
  #[Hook('entity_form_display_alter')]
  public function entityFormDisplayAlter(EntityFormDisplayInterface $form_display, array $context): void {
    // @todo Remove this route match check, and instead use
    //   `$context['form_mode']`. This will require refactoring
    //   `\Drupal\canvas\Controller\EntityFormController` to pass in a
    //   dynamically generated `canvas` form mode.
    if (!\str_starts_with((string) $this->routeMatch->getRouteName(), 'canvas.api.')) {
      return;
    }
    $target_entity_type_id = $form_display->getTargetEntityTypeId();
    $entity_type = $this->entityTypeManager->getDefinition($target_entity_type_id);
    \assert($entity_type instanceof EntityTypeInterface);
    if (\is_subclass_of($entity_type->getClass(), EntityPublishedInterface::class) && ($published_key = $entity_type->getKey('published'))) {
      $field_definitions = $this->entityFieldManager->getFieldDefinitions($target_entity_type_id, $form_display->getTargetBundle());
      // @see \Drupal\canvas\InternalCanvasFieldNameResolver::getCanvasFieldName()
      $canvas_fields = \array_filter($field_definitions, fn(FieldDefinitionInterface $field_definition) => \is_a($field_definition->getItemDefinition()
        ->getClass(), ComponentTreeItem::class, \TRUE));
      if (empty($canvas_fields)) {
        return;
      }
      // Publishable entities are automatically published when publishing
      // auto-saved changes.
      // @see \Drupal\canvas\Controller\ApiAutoSaveController::post()
      $form_display->removeComponent($published_key);
    }
  }

  /**
   * Implements hook_entity_type_alter.
   */
  #[Hook('entity_type_alter')]
  public function entityTypeAlter(array $definitions): void {
    /** @var \Drupal\Core\Entity\EntityTypeInterface $entity_type */
    foreach ($definitions as $entity_type) {
      // Canvas pages don't have any structured data, and therefore don't
      // support content templates (which require structured data anyway – that
      // is, they need to be using at least one dynamic prop source).
      // @see docs/adr/0004-page-entity-type.md
      if ($entity_type->id() === Page::ENTITY_TYPE_ID) {
        continue;
      }
      // Canvas can only render fieldable content entities. Any content entity
      // types with structured data (all of them except Canvas' own `Page`)
      // must be assumed to use ContentTemplates, and hence should use that view
      // builder.
      // Note: as soon as a ContentTemplate exists for a certain content entity
      // type + view mode, the original template will NOT be used anymore:
      // - not the view mode-specific one, such as `node--teaser.html.twig`
      // - not the generic one, such as `node.html.twig`
      // @todo Remove the restriction that this only works with nodes, after
      //   https://www.drupal.org/project/canvas/issues/3498525.
      if ($entity_type->entityClassImplements(FieldableEntityInterface::class) && $entity_type->id() === 'node') {
        // @see \Drupal\canvas\EntityHandlers\ContentTemplateAwareViewBuilder::createInstance()
        $entity_type->setHandlerClass(ContentTemplateAwareViewBuilder::DECORATED_HANDLER_KEY, $entity_type->getViewBuilderClass())
          ->setViewBuilderClass(ContentTemplateAwareViewBuilder::class);
      }
    }
  }

  /**
   * Alters menu local tasks to add cache invalidation for content templates.
   *
   * This ensures that when a ContentTemplate entity is created or deleted,
   * the menu local tasks cache is invalidated and rebuilt. This is necessary
   * because menu local tasks conditionally link to different routes
   * depending on whether a template exists.
   *
   * @param array $data
   *   The local tasks data structure.
   * @param string $route_name
   *   The route name of the current page.
   * @param \Drupal\Core\Cache\RefinableCacheableDependencyInterface $cacheability
   *   The cacheability metadata for the local tasks.
   */
  #[Hook('menu_local_tasks_alter')]
  public function menuLocalTasksAlter(array &$data, string $route_name, RefinableCacheableDependencyInterface &$cacheability): void {
    // Add content template cacheability for routes where content templates
    // apply, otherwise the content template links in the local tasks will not
    // update when templates are created or deleted.
    // Also apply to the main "Manage display" page
    // (entity.entity_view_display.node.default) so tabs update correctly when
    // templates are created/deleted.
    // @todo Remove the hardcoded node "entity_view_display" route check after
    //   https://www.drupal.org/project/canvas/issues/3498525 is resolved.
    if (ContentTemplateRoutes::applies($route_name) || $route_name === 'entity.entity_view_display.node.default') {
      // Add list cache tags to invalidate when entity ContentTemplate is
      // created/deleted. This ensures menu local tasks rebuild when template
      // availability changes.
      $storage = $this->entityTypeManager->getStorage(ContentTemplate::ENTITY_TYPE_ID);
      $cacheability->addCacheableDependency($storage);
    }
  }

  /**
   * Preprocesses menu local task variables to modify links conditionally.
   *
   * When a ContentTemplate exists for a specific entity type and view mode,
   * this changes the local task link to point to the Canvas template editor
   * instead of the standard Drupal view mode configuration page.
   *
   * @param array $variables
   *   The variables array for the menu local task template.
   */
  #[Hook('preprocess_menu_local_task')]
  public function preprocessMenuLocalTask(array &$variables): void {
    $url = $variables['element']['#link']['url'] ?? NULL;

    // Only proceed if this is a routed URL and content template logic applies.
    if (!$url instanceof Url || !$url->isRouted() || !ContentTemplateRoutes::applies($url->getRouteName())) {
      return;
    }

    $entity_type_id = $this->routeMatch->getParameter('entity_type_id');
    \assert(is_string($entity_type_id));
    $bundle_entity_type = $this->entityTypeManager
      ->getDefinition($entity_type_id)->getBundleEntityType();

    $route_parameters = $url->getRouteParameters();
    $bundle = $route_parameters[$bundle_entity_type];
    $view_mode_id = $route_parameters['view_mode_name'] ?? 'default';
    $template_id = "$entity_type_id.$bundle.$view_mode_id";

    // Check if a Canvas template exists.
    $template = $this->entityTypeManager
      ->getStorage(ContentTemplate::ENTITY_TYPE_ID)
      ->load($template_id);

    // Only modify the link if the template exists and is enabled.
    if (!$template instanceof ConfigEntityInterface || !$template->status()) {
      return;
    }

    // Redirect to Canvas template editor instead of standard view mode page.
    $variables['link']['#url'] = Url::fromUri("base:canvas/template/$entity_type_id/$bundle/$view_mode_id");

    // Add visual indicators that this link opens in a new window.
    $variables['link']['#options']['attributes']['class'][] = 'menu-icon';
    $variables['link']['#options']['attributes']['class'][] = 'external-link';

    // Attach library for menu icon styling.
    $variables['link']['#attached']['library'][] = 'canvas/menu-icons';
  }

}
