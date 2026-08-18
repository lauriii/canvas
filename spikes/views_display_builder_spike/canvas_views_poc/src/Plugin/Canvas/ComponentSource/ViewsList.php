<?php

declare(strict_types=1);

namespace Drupal\canvas_views_poc\Plugin\Canvas\ComponentSource;

use Drupal\canvas\Attribute\ComponentSource;
use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\ComponentDoesNotMeetRequirementsException;
use Drupal\canvas\ComponentSource\ComponentSourceBase;
use Drupal\canvas\ComponentSource\ComponentSourceWithDeferredSlotsInterface;
use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\ComponentTreeEntityInterface;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItemListInstantiatorTrait;
use Drupal\canvas_views_poc\ViewsListDiscovery;
use Drupal\Component\Serialization\Json;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\TypedDataManagerInterface;
use Drupal\views\ViewExecutable;
use Drupal\views\Views;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\ConstraintViolationListInterface;

/**
 * Exposes a Views display as a component with a designable item template.
 *
 * The component's `item_template` slot is a deferred slot: the user designs it
 * in the Canvas editor per placement, and this source renders it once per
 * result row, through a dangling component tree parented to that row's entity.
 * Prop bindings map a template component's prop to one of the display's Views
 * fields; the bound value replaces the prop's stored static value per row.
 *
 * Proof of concept quality throughout. Known ceilings, in line with the plan:
 * string-shaped props only (bound values are flattened rendered field output),
 * no pager or exposed filter rendering, and per-row output is not render
 * cached.
 *
 * @phpstan-ignore classExtendsInternalClass.classExtendsInternalClass
 */
#[ComponentSource(
  id: self::SOURCE_PLUGIN_ID,
  label: new TranslatableMarkup('Views list'),
  supportsImplicitInputs: TRUE,
  discovery: ViewsListDiscovery::class,
)]
final class ViewsList extends ComponentSourceBase implements ComponentSourceWithDeferredSlotsInterface, ContainerFactoryPluginInterface {

  use ComponentTreeItemListInstantiatorTrait;

  public const string SOURCE_PLUGIN_ID = 'views_list';

  public const string SLOT_NAME = 'item_template';

  /**
   * How many result rows the editor preview renders.
   */
  private const int PREVIEW_ITEMS = 3;

  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    TypedDataManagerInterface $typed_data_manager,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->setTypedDataManager($typed_data_manager);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    return new self(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get(EntityTypeManagerInterface::class),
      $container->get(TypedDataManagerInterface::class),
    );
  }

  private function getViewId(): string {
    return $this->configuration['view_id'] ?? '';
  }

  private function getDisplayId(): string {
    return $this->configuration['display_id'] ?? '';
  }

  /**
   * Loads the view executable with the configured display set.
   */
  private function getViewExecutable(): ?ViewExecutable {
    $view = Views::getView($this->getViewId());
    if ($view === NULL || !$view->setDisplay($this->getDisplayId())) {
      return NULL;
    }
    return $view;
  }

  /**
   * {@inheritdoc}
   */
  public function isBroken(): bool {
    return $this->getViewExecutable() === NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getReferencedPluginClass(): ?string {
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getComponentDescription(): TranslatableMarkup {
    return new TranslatableMarkup('View: @view (@display)', [
      '@view' => $this->getViewId(),
      '@display' => $this->getDisplayId(),
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function checkRequirements(): void {
    $view = $this->getViewExecutable();
    if ($view === NULL) {
      throw new ComponentDoesNotMeetRequirementsException([
        \sprintf('View "%s" display "%s" does not exist.', $this->getViewId(), $this->getDisplayId()),
      ]);
    }
    if ($view->getBaseEntityType() === FALSE) {
      throw new ComponentDoesNotMeetRequirementsException([
        \sprintf('View "%s" is not based on an entity type.', $this->getViewId()),
      ]);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function calculateDependencies(): array {
    $dependencies = ['module' => ['views']];
    $view = Views::getView($this->getViewId());
    if ($view !== NULL) {
      $dependencies['config'][] = $view->storage->getConfigDependencyName();
    }
    return $dependencies;
  }

  /**
   * {@inheritdoc}
   */
  protected function getExplicitInputDefinitions(): array {
    // The only explicit input is the prop bindings map, which does not affect
    // the component's version identity beyond the settings already hashed.
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function requiresExplicitInput(): bool {
    // The client only receives a model entry for instances whose source
    // requires explicit input, and it cannot select an instance without one.
    // @see \Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItemList::buildLayoutAndModel()
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function getDefaultExplicitInput(bool $only_required = FALSE): array {
    return ['bindings' => []];
  }

  /**
   * {@inheritdoc}
   */
  public function getExplicitInput(string $uuid, ComponentTreeItem $item, ?FieldableEntityInterface $host_entity = NULL): array {
    return ($item->getInputs() ?? []) + ['bindings' => []];
  }

  /**
   * {@inheritdoc}
   */
  public function hydrateComponent(array $explicit_input, array $slot_definitions, array $active_required_explicit_inputs): array {
    return $explicit_input;
  }

  /**
   * {@inheritdoc}
   */
  public function inputToClientModel(array $explicit_input): array {
    return ['resolved' => $explicit_input];
  }

  /**
   * {@inheritdoc}
   */
  public function clientModelToInput(string $component_instance_uuid, Component $component, array $client_model, ?FieldableEntityInterface $host_entity, ?ConstraintViolationListInterface $violations = NULL): array {
    return $client_model['resolved'] ?? [];
  }

  /**
   * {@inheritdoc}
   */
  public function validateComponentInput(array $inputValues, string $component_instance_uuid, ?FieldableEntityInterface $entity): ConstraintViolationListInterface {
    return new ConstraintViolationList();
  }

  /**
   * {@inheritdoc}
   */
  public function getSlotDefinitions(): array {
    return [
      self::SLOT_NAME => [
        'title' => 'Item template',
        'description' => 'Rendered once for every result row of the view.',
        'examples' => [],
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function setSlots(array &$build, array $slots): void {
    // Deferred slots never reach this method: the tree excludes this source's
    // descendants from regular slot rendering. Implemented defensively.
    $build['#slots'] = $slots;
  }

  /**
   * {@inheritdoc}
   */
  public function getClientSideInfo(Component $component): array {
    return [
      'build' => [
        '#markup' => Markup::create('<div style="padding:1rem;border:1px dashed #999;text-align:center">' . htmlspecialchars((string) $component->label()) . '</div>'),
      ],
      'metadata' => [
        'slots' => $this->getSlotDefinitions(),
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildComponentInstanceForm(
    array $form,
    FormStateInterface $form_state,
    Component $component,
    string $component_instance_uuid = '',
    array $inputValues = [],
    ?EntityInterface $entity = NULL,
    array $settings = [],
  ): array {
    $form['#tree'] = TRUE;
    $view = $this->getViewExecutable();
    if ($view === NULL) {
      return $form;
    }
    $view->initHandlers();
    $field_options = [];
    foreach ($view->field as $field_id => $handler) {
      $field_options[$field_id] = $handler->adminLabel();
    }
    $children = $entity === NULL ? [] : $this->getItemTemplateChildren($entity, $component_instance_uuid);
    if ($field_options === [] || $children === []) {
      $form['empty'] = [
        '#markup' => '<p>' . new TranslatableMarkup("Drag components into the item template, then reopen this panel to bind their props to the view's fields.") . '</p>',
      ];
      return $form;
    }
    // Typed wide on purpose: the render array mixes '#type' with per-child
    // subarrays.
    /** @var array<string, mixed> $bindings */
    $bindings = [
      '#type' => 'container',
    ];
    foreach ($children as $child_uuid => $child) {
      foreach ($child['props'] as $prop_name) {
        $child_element = \is_array($bindings[$child_uuid] ?? NULL) ? $bindings[$child_uuid] : [];
        $child_element[$prop_name] = [
          '#type' => 'select',
          '#title' => new TranslatableMarkup('@component: @prop', [
            '@component' => $child['label'],
            '@prop' => $prop_name,
          ]),
          '#options' => $field_options,
          '#empty_option' => new TranslatableMarkup('- Keep the static value -'),
          '#default_value' => $inputValues['bindings'][$child_uuid][$prop_name] ?? '',
        ];
        $bindings[$child_uuid] = $child_element;
      }
    }
    $form['bindings'] = $bindings;
    return $form;
  }

  /**
   * Finds the current item template children on the host entity.
   *
   * Reads the host's stored tree; the caller is editing, so an auto-saved
   * draft may be ahead of this. ponytail: saved-tree only, read the auto-save
   * draft too if stale binding options become a real complaint.
   *
   * @return array<string, array{label: string, props: string[]}>
   *   Child component instances keyed by UUID.
   */
  private function getItemTemplateChildren(EntityInterface $entity, string $instance_uuid): array {
    if (!$entity instanceof ComponentTreeEntityInterface && !$entity instanceof FieldableEntityInterface) {
      return [];
    }
    // ponytail: static service call, inject if this leaves POC status.
    // The user is editing, so the auto-saved draft is ahead of the saved
    // entity; a component just dragged into the slot only exists there.
    // @phpstan-ignore globalDrupalDependencyInjection.useDependencyInjection
    $auto_save = \Drupal::service(AutoSaveManager::class)->getAutoSaveEntity($entity);
    if (!$auto_save->isEmpty() && ($auto_save->entity instanceof ComponentTreeEntityInterface || $auto_save->entity instanceof FieldableEntityInterface)) {
      $entity = $auto_save->entity;
    }
    $items = $entity instanceof ComponentTreeEntityInterface
      ? $entity->getComponentTree()->getValue()
      : [];
    if ($items === [] && $entity instanceof FieldableEntityInterface) {
      foreach ($entity->getFieldDefinitions() as $field_name => $definition) {
        if ($definition->getType() === 'component_tree') {
          $items = $entity->get($field_name)->getValue();
          break;
        }
      }
    }
    $children = [];
    foreach ($items as $item_value) {
      if (($item_value['parent_uuid'] ?? NULL) !== $instance_uuid) {
        continue;
      }
      $child_component = Component::load($item_value['component_id']);
      if ($child_component === NULL) {
        continue;
      }
      $inputs = $item_value['inputs'] ?? [];
      if (\is_string($inputs)) {
        $inputs = Json::decode($inputs) ?? [];
      }
      $children[$item_value['uuid']] = [
        'label' => (string) $child_component->label(),
        'props' => \array_map('strval', \array_keys($inputs)),
      ];
    }
    return $children;
  }

  /**
   * {@inheritdoc}
   */
  public function renderComponent(array $inputs, array $slot_definitions, string $componentUuid, bool $isPreview = FALSE): array {
    $view = $this->getViewExecutable();
    if ($view === NULL) {
      return [];
    }
    if ($isPreview) {
      $view->setItemsPerPage(self::PREVIEW_ITEMS);
    }
    $view->preExecute();
    $view->execute();

    $raw_items = $inputs[ComponentSourceWithDeferredSlotsInterface::DEFERRED_ITEMS_KEY] ?? [];
    $bindings = $inputs['bindings'] ?? [];

    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['canvas-views-list']],
    ];
    $cacheability = CacheableMetadata::createFromObject($view->storage);
    $cacheability->addCacheTags($view->getCacheTags());

    if ($raw_items === []) {
      if ($isPreview) {
        // An empty deferred slot must still be a drop target in the editor.
        $build['slot'] = [
          '#prefix' => Markup::create(\sprintf('<!-- canvas-slot-start-%s/%s -->', $componentUuid, self::SLOT_NAME)),
          '#suffix' => Markup::create(\sprintf('<!-- canvas-slot-end-%s/%s -->', $componentUuid, self::SLOT_NAME)),
          '#markup' => Markup::create('<div class="canvas--slot-empty-placeholder"></div>'),
        ];
      }
      $cacheability->applyTo($build);
      return $build;
    }

    foreach ($view->result as $index => $row) {
      $row_entity = $row->_entity;
      if (!$row_entity instanceof FieldableEntityInterface) {
        continue;
      }
      $items = $this->prepareRowItems($raw_items, $componentUuid, $bindings, $view, $index);
      $item_list = $this->createDanglingComponentTreeItemList($row_entity);
      $item_list->setValue($items);
      // Only the first repetition renders with editing annotations, so each
      // template component appears exactly once in the editor.
      $row_is_preview = $isPreview && $index === 0;
      $row_build = $item_list->toRenderable($row_entity, $row_is_preview);
      if ($row_is_preview) {
        $row_build['#prefix'] = Markup::create(\sprintf('<!-- canvas-slot-start-%s/%s -->', $componentUuid, self::SLOT_NAME));
        $row_build['#suffix'] = Markup::create(\sprintf('<!-- canvas-slot-end-%s/%s -->', $componentUuid, self::SLOT_NAME));
      }
      $build['rows'][$index] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['canvas-views-list__row']],
        'content' => $row_build,
      ];
    }

    $cacheability->applyTo($build);
    return $build;
  }

  /**
   * Prepares the deferred subtree for one result row.
   *
   * Direct children of this component instance become roots of the dangling
   * per-row tree, and bound props get this row's rendered field value in
   * place of their stored static value.
   *
   * @return array<int, array<string, mixed>>
   *   Stored-item values ready for ComponentTreeItemList::setValue().
   */
  private function prepareRowItems(array $raw_items, string $instance_uuid, array $bindings, ViewExecutable $view, int $row_index): array {
    $items = [];
    foreach ($raw_items as $item_value) {
      if (($item_value['parent_uuid'] ?? NULL) === $instance_uuid) {
        $item_value['parent_uuid'] = NULL;
        $item_value['slot'] = NULL;
      }
      $item_bindings = $bindings[$item_value['uuid']] ?? [];
      $item_bindings = \array_filter(
        \is_array($item_bindings) ? $item_bindings : [],
        static fn (mixed $field_id): bool => \is_string($field_id) && $field_id !== '',
      );
      if ($item_bindings !== []) {
        $inputs = $item_value['inputs'] ?? [];
        $was_json = \is_string($inputs);
        if ($was_json) {
          $inputs = Json::decode($inputs) ?? [];
        }
        foreach ($item_bindings as $prop_name => $field_id) {
          if (!isset($inputs[$prop_name]) || !isset($view->field[$field_id])) {
            continue;
          }
          // Render-context-safe rendered field output; string props only.
          // @see \Drupal\views\Plugin\views\style\StylePluginBase::renderFields()
          $rendered = \trim(\strip_tags((string) $view->style_plugin?->getField($row_index, $field_id)));
          // A static prop is stored either expanded (an array with a `value`
          // key) or collapsed to the bare value.
          if (\is_array($inputs[$prop_name])) {
            $inputs[$prop_name]['value'] = $rendered;
          }
          else {
            $inputs[$prop_name] = $rendered;
          }
        }
        $item_value['inputs'] = $was_json ? Json::encode($inputs) : $inputs;
      }
      // Each per-row dangling tree needs unique UUIDs is NOT true: UUIDs only
      // need to be unique within one tree, and each row is its own tree.
      $items[] = $item_value;
    }
    return $items;
  }

}
