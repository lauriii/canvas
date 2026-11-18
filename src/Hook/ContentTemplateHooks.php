<?php

declare(strict_types=1);

namespace Drupal\canvas\Hook;

use Drupal\block_content\Plugin\Block\BlockContentBlock;
use Drupal\canvas\Block\ContentTemplateAwareBlockContentBlock;
use Drupal\canvas\Block\ContentTemplateAwareInlineBlock;
use Drupal\canvas\ContentTemplateRepository;
use Drupal\canvas\Entity\ComponentTreeEntityInterface;
use Drupal\canvas\Entity\ContentTemplate;
use Drupal\canvas\EntityHandlers\ContentTemplateAwareViewBuilder;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\canvas\Plugin\Validation\Constraint\ExposedSlotContentIsMergeableConstraint;
use Drupal\canvas\Storage\ComponentTreeLoader;
use Drupal\Core\Cache\RefinableCacheableDependencyInterface;
use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\Core\Entity\ContentEntityFormInterface;
use Drupal\Core\Entity\Display\EntityFormDisplayInterface;
use Drupal\Core\Entity\EntityDisplayRepositoryInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityPublishedInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Hook\Order\Order;
use Drupal\Core\Render\Element;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\layout_builder\Plugin\Block\InlineBlock;

/**
 * @see \Drupal\canvas\Entity\ContentTemplate
 * @see \Drupal\canvas\EntityHandlers\ContentTemplateAwareViewBuilder
 */
final class ContentTemplateHooks {

  public function __construct(
    private readonly RouteMatchInterface $routeMatch,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntityFieldManagerInterface $entityFieldManager,
    private readonly ComponentTreeLoader $componentTreeLoader,
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
   * Implements hook_form_alter().
   *
   * For templated entities, reduces the entity form that Canvas serves to the
   * label and the sidebar elements (URL alias, menu, authoring, promotion,
   * comment settings). Content fields are edited in Drupal's own edit form.
   */
  #[Hook('form_alter')]
  public function formAlter(array &$form, FormStateInterface $form_state): void {
    if (!\str_starts_with((string) $this->routeMatch->getRouteName(), 'canvas.api.')) {
      return;
    }
    $form_object = $form_state->getFormObject();
    if (!$form_object instanceof ContentEntityFormInterface) {
      return;
    }
    $entity = $form_object->getEntity();
    if ($entity instanceof ComponentTreeEntityInterface
      || !$entity instanceof FieldableEntityInterface
      || !$this->componentTreeLoader->hasContentTemplateWithExposedSlots($entity)) {
      return;
    }
    $label_key = $entity->getEntityType()->getKey('label');
    foreach (\array_keys($form_object->getFormDisplay($form_state)->getComponents()) as $name) {
      if ($name === $label_key || !isset($form[$name]) || !\is_array($form[$name])) {
        continue;
      }
      if (!self::isPageDataElement($form[$name], $form)) {
        $form[$name]['#access'] = FALSE;
      }
    }
    // The editor has its own publish state UI. unset(), not `#access`: the
    // grouped element would still render under `advanced`.
    // @see \Drupal\node\Form\NodeForm::form()
    unset($form['meta']);
    // Show the URL alias below the title as a plain field, as on the
    // canvas_page form: strip the details wrapper PathWidget adds.
    // @see \Drupal\path\Plugin\Field\FieldWidget\PathWidget::formElement()
    if (isset($form['path']) && \is_array($form['path'])) {
      if (\is_array($form['path']['widget'] ?? NULL)) {
        foreach (Element::children($form['path']['widget']) as $delta) {
          $path_element = &$form['path']['widget'][$delta];
          if (\is_array($path_element) && ($path_element['#type'] ?? NULL) === 'details') {
            unset($path_element['#type'], $path_element['#title'], $path_element['#open'], $path_element['#group'], $path_element['#attributes'], $path_element['#attached'], $path_element['#weight']);
          }
          unset($path_element);
        }
      }
      $label_weight = $form[$label_key]['#weight'] ?? NULL;
      $form['path']['#weight'] = (\is_numeric($label_weight) ? $label_weight : 0) + 1;
    }
  }

  /**
   * Whether a widget element belongs in the form's sidebar.
   *
   * TRUE when the element or a descendant is in the `advanced` (sidebar) or
   * `footer` group, directly or through nested groups. For example `uid` is
   * in `author`, which is in `advanced`.
   *
   * @param array<string, mixed> $element
   *   The widget element to check.
   * @param array<string, mixed> $complete_form
   *   The complete form, used to resolve chains of group containers.
   *
   * @return bool
   *   TRUE if the element belongs in the sidebar.
   */
  private static function isPageDataElement(array $element, array $complete_form): bool {
    $group = $element['#group'] ?? NULL;
    $visited = [];
    while (\is_string($group) && !isset($visited[$group])) {
      if ($group === 'advanced' || $group === 'footer') {
        return TRUE;
      }
      $visited[$group] = TRUE;
      $group = $complete_form[$group]['#group'] ?? NULL;
    }
    foreach (Element::children($element) as $key) {
      if (\is_array($element[$key]) && self::isPageDataElement($element[$key], $complete_form)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Implements hook_entity_type_alter.
   *
   * Runs last, so that it sees the `field_ui_base_route` that other modules'
   * implementations may set, like the rest of Canvas does.
   *
   * @see \Drupal\canvas\ContentTemplateRepository::isSupportedEntityType()
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
        $entity_type->addConstraint(ExposedSlotContentIsMergeableConstraint::PLUGIN_ID);
      }
    }
  }

  /**
   * Implements hook_block_alter().
   *
   * Replaces the class of the block plugins that show a content block, so
   * they build it immediately when a content template renders it.
   *
   * @see \Drupal\canvas\Block\BuildsContentBlockImmediatelyTrait
   *
   * @todo Remove when core builds content blocks in its block plugins: https://www.drupal.org/project/drupal/issues/3638853
   */
  #[Hook('block_alter')]
  public static function blockAlter(array &$definitions): void {
    foreach ($definitions as &$definition) {
      $definition['class'] = match ($definition['class']) {
        BlockContentBlock::class => ContentTemplateAwareBlockContentBlock::class,
        InlineBlock::class => ContentTemplateAwareInlineBlock::class,
        default => $definition['class'],
      };
    }
  }

  /**
   * Implements hook_ENTITY_TYPE_insert(), _update() and _delete().
   *
   * Content templates decide which bundles have exposed slots; the loader
   * memoizes that per request.
   */
  #[Hook('content_template_insert')]
  #[Hook('content_template_update')]
  #[Hook('content_template_delete')]
  public function resetExposedSlotBundles(): void {
    $this->componentTreeLoader->resetExposedSlotBundles();
  }

  /**
   * Implements hook_entity_operation().
   *
   * Offers the "Edit in Canvas" local task in entity listings.
   *
   * @see \Drupal\canvas\Plugin\Menu\ContentTemplateLayoutTask
   */
  #[Hook('entity_operation')]
  public static function entityOperation(EntityInterface $entity): array {
    if (!$entity instanceof FieldableEntityInterface || $entity instanceof ComponentTreeEntityInterface || $entity->isNew()) {
      return [];
    }
    $url = Url::fromRoute('canvas.boot.entity', [
      'entity_type' => $entity->getEntityTypeId(),
      'entity' => $entity->id(),
    ]);
    if (!$url->access()) {
      return [];
    }
    return [
      'canvas_layout' => [
        'title' => new TranslatableMarkup('Edit in Canvas'),
        'url' => $url,
        'weight' => 15,
      ],
    ];
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
