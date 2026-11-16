<?php

declare(strict_types=1);

namespace Drupal\canvas\Hook;

use Drupal\canvas\ContentTemplateRepository;
use Drupal\canvas\Entity\ContentTemplate;
use Drupal\canvas\EntityHandlers\ContentTemplateAwareViewBuilder;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\Core\Cache\RefinableCacheableDependencyInterface;
use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\Core\Entity\Display\EntityFormDisplayInterface;
use Drupal\Core\Entity\EntityDisplayRepositoryInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityPublishedInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Hook\Order\Order;
use Drupal\Core\Routing\RouteMatchInterface;
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
   *
   * Runs last, so that it sees the `field_ui_base_route` and link templates
   * that other modules' implementations may set, like the rest of Canvas does.
   * For example, Media's implementation sets the `canonical` link template
   * that makes `media` eligible.
   *
   * @see \Drupal\canvas\ContentTemplateRepository::isSupportedEntityType()
   * @see \Drupal\media\Hook\MediaHooks::entityTypeAlter()
   */
  #[Hook('entity_type_alter', order: Order::Last)]
  public static function entityTypeAlter(array $definitions): void {
    /** @var \Drupal\Core\Entity\EntityTypeInterface $entity_type */
    foreach ($definitions as $entity_type) {
      // Note: as soon as a ContentTemplate exists for a certain content entity
      // type + view mode, the original template will NOT be used anymore:
      // - not the view mode-specific one, such as `node--teaser.html.twig`
      // - not the generic one, such as `node.html.twig`
      if (ContentTemplateRepository::isSupportedEntityType($entity_type)) {
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
    // Add content template cacheability on Field UI's entity view display
    // routes. Field UI only generates these routes for entity types with
    // field_ui_base_route, which is a superset of the entity types Canvas
    // supports, so this may add cacheability on a route Canvas has no template
    // for. This covers both the "Manage display" page
    // (`entity.entity_view_display.*`) on Drupal < 11.4, and the display-mode
    // overview (`entity.entity_view_display_overview.*`) as of Drupal 11.4.
    // @see https://www.drupal.org/node/3574383
    if (\str_starts_with($route_name, 'entity.entity_view_display.') || \str_starts_with($route_name, 'entity.entity_view_display_overview.')) {
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

    // Only proceed if this is a routed URL on a view mode display route. The
    // route name is matched by convention, so nothing guarantees the route
    // has the parameters that Field UI's routes have: bail out rather than
    // fail on any route that merely shares the naming pattern.
    if (!$url instanceof Url || !$url->isRouted()) {
      return;
    }
    $entity_type_id = $this->routeMatch->getParameter('entity_type_id');
    if (!\is_string($entity_type_id) || !$this->entityTypeManager->hasDefinition($entity_type_id)) {
      return;
    }
    if ($url->getRouteName() !== ContentTemplateRepository::getViewModeDisplayRouteName($entity_type_id)) {
      return;
    }
    $bundle_entity_type = $this->entityTypeManager
      ->getDefinition($entity_type_id)->getBundleEntityType();

    $route_parameters = $url->getRouteParameters();
    // Field UI names the bundle route parameter after the bundle entity type,
    // or `bundle` for entity types with plugin-based bundles (for example,
    // commerce_license). Entity types without bundles (for example, user) have
    // a single implicit bundle equal to the entity type ID, and their Field UI
    // routes have no bundle route parameter.
    // @see \Drupal\field_ui\Routing\RouteSubscriber::alterRoutes()
    $bundle = $route_parameters[$bundle_entity_type ?? 'bundle'] ?? $entity_type_id;
    if (!\is_string($bundle)) {
      return;
    }
    $view_mode_id = $route_parameters['view_mode_name'] ?? EntityDisplayRepositoryInterface::DEFAULT_DISPLAY_MODE;

    // Check if a Canvas template exists.
    $template = ContentTemplate::loadFor($entity_type_id, $bundle, $view_mode_id);

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
