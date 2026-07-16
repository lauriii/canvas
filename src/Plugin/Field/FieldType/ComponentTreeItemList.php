<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Field\FieldType;

use Drupal\canvas\ComponentSource\ComponentSourceInterface;
use Drupal\canvas\ComponentSource\ComponentSourceWithSlotsInterface;
use Drupal\canvas\ComponentSource\ComponentSourceWithSwitchCasesInterface;
use Drupal\canvas\Element\RenderSafeComponentContainer;
use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\ComponentTreeEntityInterface;
use Drupal\canvas\Exception\SubtreeInjectionException;
use Drupal\canvas\HydratedTree;
use Drupal\canvas\Plugin\Validation\Constraint\ComponentTreeStructureConstraint;
use Drupal\Component\Graph\Graph;
use Drupal\Component\Plugin\DependentPluginInterface;
use Drupal\Component\Utility\NestedArray;
use Drupal\Component\Utility\SortArray;
use Drupal\Core\Cache\CacheableDependencyInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Entity\Plugin\DataType\EntityAdapter;
use Drupal\Core\Field\FieldItemList;
use Drupal\Core\Form\EnforcedResponseException;
use Drupal\Core\Form\FormAjaxException;
use Drupal\Core\Render\Markup;
use Drupal\Core\Render\RenderableInterface;

/**
 * A component tree: a list item class for ComponentTreeItem.
 *
 * @phpstan-import-type OptimizedSingleComponentInputArray from \Drupal\canvas\Plugin\DataType\ComponentInputs
 * @phpstan-type ComponentTreeItemArray array{'uuid': string, 'component_id': string, 'parent_uuid'?: string, 'slot'?: string, inputs: OptimizedSingleComponentInputArray}
 * @phpstan-type ComponentTreeItemListArray array<int, ComponentTreeItemArray>
 * @phpstan-type ExposedSlotDefinitions array<string, array{'component_uuid': string, 'slot_name': string, 'label': string}>
 */
final class ComponentTreeItemList extends FieldItemList implements RenderableInterface, CacheableDependencyInterface, DependentPluginInterface {

  // @todo Remove in https://drupal.org/i/3495625
  public const string ROOT_UUID = 'a548b48d-58a8-4077-aa04-da9405a6f418';

  private const string HYDRATION_EXCEPTION_KEY = 'hydration_exception';

  /**
   * @var null|array<string, array{'edges': array<string, TRUE>}>
   */
  protected ?array $graph = NULL;

  public function first() : ?ComponentTreeItem {
    // @phpstan-ignore-next-line
    return parent::first();
  }

  public function get($index) : ?ComponentTreeItem {
    // @phpstan-ignore-next-line
    return parent::get($index);
  }

  /**
   * {@inheritdoc}
   */
  public function calculateDependencies() : array {
    $dependencies = [];
    foreach ($this as $item) {
      \assert($item instanceof ComponentTreeItem);
      $dependencies = NestedArray::mergeDeep($dependencies, $item->calculateFieldItemValueDependencies(
        $this->getParent()
          ? $this->getEntity()
          // Support dangling component trees.
          // @see \Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItemListInstantiatorTrait
          : NULL
      ));
    }
    return $dependencies;
  }

  /**
   * @param \Drupal\Core\Entity\FieldableEntityInterface|null $host_entity
   *   The host entity, used to resolve dynamic props into the client `model`.
   *
   * @todo Move this into a normalizer at https://www.drupal.org/i/3499632
   */
  public function getClientSideRepresentation(?FieldableEntityInterface $host_entity = NULL): array {
    return $this->buildLayoutAndModel($this->componentTreeItemsIterator(self::inRootLevel()), $host_entity);
  }

  /**
   * @todo Move this into a normalizer at https://www.drupal.org/i/3499632
   */
  private function buildLayoutAndModel(iterable $tree_tier, ?FieldableEntityInterface $host_entity = NULL): array {
    $built = ['layout' => [], 'model' => []];
    /** @var \Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItem $item */
    foreach ($tree_tier as $item) {
      $item_layout_node = $item->getClientSideRepresentation();
      $component_instance_uuid = $item->getUuid();

      // Use ComponentSourceInterface::inputToClientModel() to map the server-
      // stored `inputs` data to the client-side `model`.
      // @see \Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItem::propertyDefinitions()
      // @see \Drupal\canvas\Plugin\DataType\ComponentInputs
      // @see DynamicComponent type-script definition.
      // @see ComponentModel type-script definition.
      // @see PropSourceComponent type-script definition.
      // @see EvaluatedComponentModel type-script definition.
      $source = $item->getComponent()?->getComponentSource();
      \assert($source instanceof ComponentSourceInterface);
      if ($source->requiresExplicitInput()) {
        $built['model'][$component_instance_uuid] = $source->inputToClientModel($source->getExplicitInput($component_instance_uuid, $item, $host_entity));
      }

      // TRICKY: the server-side implementation (for storage efficiency) forbids
      // - empty component subtrees
      // - empty slots
      // @see \Drupal\canvas\Plugin\Validation\Constraint\ComponentTreeStructureConstraintValidator
      // But the client expects all *available* slots to get a `slot node`: in
      // every `component node` for every slot of that component, even the slot
      // is empty.
      // @see docs/data-model.md#3.4.1
      $known_slot_names_for_component = match ($source instanceof ComponentSourceWithSlotsInterface) {
        FALSE => [],
        // We explicitly load the slots from the Component and not the source,
        // preventing the preview to become unusable if the real time definition
        // is missing. So use the known slots at the time of the component
        // config entity creation.
        TRUE => $item->getComponent() ? \array_keys($item->getComponent()->getSlotDefinitions()) : [],
      };
      foreach ($known_slot_names_for_component as $slot_name) {
        $component_instance_slot = [
          'id' => $component_instance_uuid . '/' . $slot_name,
          'name' => $slot_name,
          'nodeType' => 'slot',
        ];
        $child_build = self::buildLayoutAndModel($this->componentTreeItemsIterator(self::isChildOfComponentTreeItemSlot($component_instance_uuid, (string) $slot_name)), $host_entity);
        $built['model'] += $child_build['model'];
        $component_instance_slot['components'] = $child_build['layout'];
        $item_layout_node['slots'][] = $component_instance_slot;
      }
      $built['layout'][] = $item_layout_node;
    }
    return $built;
  }

  public static function inRootLevel(): callable {
    return static fn (ComponentTreeItem $item) => $item->getParentUuid() === NULL;
  }

  public static function isChildOfComponentTreeItemSlot(string $parent_uuid, string $slot_name): callable {
    return static fn (ComponentTreeItem $item) => $item->getParentUuid() === $parent_uuid && $item->getSlot() === $slot_name;
  }

  /**
   * Collects the raw values of a set of roots plus all transitive descendants.
   *
   * @param \Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItemList $list
   *   The list to read from.
   * @param string[] $root_uuids
   *   The UUIDs of the subtree roots to collect.
   *
   * @return array<string, array<string, mixed>>
   *   The collected item values, keyed by UUID, ordered depth-first from each
   *   root (a root immediately followed by its descendants).
   */
  private static function collectSubtreeValues(ComponentTreeItemList $list, array $root_uuids): array {
    $values_by_uuid = [];
    $children_by_parent = [];
    foreach ($list->componentTreeItemsIterator() as $item) {
      \assert($item instanceof ComponentTreeItem);
      $values_by_uuid[$item->getUuid()] = $item->getValue();
      $children_by_parent[$item->getParentUuid() ?? self::ROOT_UUID][] = $item->getUuid();
    }
    $ordered = [];
    $walk = static function (string $uuid) use (&$walk, &$ordered, $values_by_uuid, $children_by_parent): void {
      if (!\array_key_exists($uuid, $values_by_uuid) || \array_key_exists($uuid, $ordered)) {
        return;
      }
      $ordered[$uuid] = $values_by_uuid[$uuid];
      foreach ($children_by_parent[$uuid] ?? [] as $child_uuid) {
        $walk($child_uuid);
      }
    };
    foreach ($root_uuids as $root_uuid) {
      $walk($root_uuid);
    }
    return $ordered;
  }

  /**
   * Partitions a tree's exposed-slot content into per-slot-field row sets.
   *
   * Each exposed slot is backed by its own `component_tree` field on the host
   * bundle. For each slot, the components placed directly in that slot (minus
   * any listed in $excluded_uuids) plus their transitive descendants are
   * collected and re-rooted as an ordinary tree: each root gets an empty
   * `parent_uuid` and empty `slot`, while descendants keep their nesting.
   * Used to extract each exposed slot's default content from a template's own
   * tree, in the exact shape an entity override would be stored in.
   *
   * @param ExposedSlotDefinitions $exposed_slot_info
   *   The exposed slot definitions, keyed by the backing field machine name.
   * @param array<string, true> $excluded_uuids
   *   Component UUIDs to skip when collecting a slot's roots, keyed by UUID.
   *
   * @return array{fields: array<string, array<int, array<string, mixed>>>, kept: array<string, true>}
   *   - `fields`: the collected rows, keyed by backing field machine name.
   *     Every exposed slot is present (empty when it has no content).
   *   - `kept`: the set of UUIDs partitioned into a slot field, keyed by UUID.
   */
  public function partitionSlotFields(array $exposed_slot_info, array $excluded_uuids): array {
    $fields = [];
    $kept = [];
    foreach ($exposed_slot_info as $field_name => $slot_detail) {
      // Present every slot (empty by default) so reverting an override clears
      // the backing field.
      $fields[$field_name] = [];
      $parent_uuid = $slot_detail['component_uuid'] ?? NULL;
      $slot = $slot_detail['slot_name'] ?? NULL;
      if ($parent_uuid === NULL || $slot === NULL) {
        continue;
      }

      // Override roots: the entity-owned components placed directly in the
      // exposed slot. Template-owned children are the inherited default and are
      // dropped (their absence means "inherit").
      $override_roots = [];
      foreach ($this->componentTreeItemsIterator(self::isChildOfComponentTreeItemSlot($parent_uuid, (string) $slot)) as $item) {
        \assert($item instanceof ComponentTreeItem);
        if (!\array_key_exists($item->getUuid(), $excluded_uuids)) {
          $override_roots[] = $item->getUuid();
        }
      }
      if (\count($override_roots) === 0) {
        continue;
      }

      $rows = [];
      foreach (self::collectSubtreeValues($this, $override_roots) as $uuid => $value) {
        $kept[$uuid] = TRUE;
        if (\in_array($uuid, $override_roots, TRUE)) {
          // Re-root as an ordinary tree root of the slot field: empty
          // `parent_uuid` and empty `slot`.
          unset($value['parent_uuid'], $value['slot']);
        }
        $rows[] = $value;
      }
      $fields[$field_name] = $rows;
    }
    return ['fields' => $fields, 'kept' => $kept];
  }

  public static function doesNotExistInOtherComponentTree(ComponentTreeItemList $other): callable {
    return static fn (ComponentTreeItem $item) => $other->getComponentTreeItemByUuid($item->getUuid()) === NULL;
  }

  public function componentTreeItemsIterator(?callable $filter = NULL): iterable {
    foreach ($this as $delta => $item) {
      \assert($item instanceof ComponentTreeItem);
      if ($filter === NULL || $filter($item)) {
        yield $delta => $item;
      }
    }
  }

  public function getComponentTreeItemByUuid(string $uuid): ?ComponentTreeItem {
    foreach ($this as $item) {
      \assert($item instanceof ComponentTreeItem);
      if ($item->getUuid() === $uuid) {
        return $item;
      }
    }
    return NULL;
  }

  public function getComponentTreeDeltaByUuid(string $uuid): ?int {
    foreach ($this as $delta => $item) {
      \assert($item instanceof ComponentTreeItem);
      if ($item->getUuid() === $uuid) {
        return $delta;
      }
    }
    return NULL;
  }

  public function getComponentIdList(): array {
    return \array_unique(\array_column($this->getValue(), 'component_id'));
  }

  /**
   * {@inheritdoc}
   */
  public function getConstraints(): array {
    $constraints = parent::getConstraints();
    $constraint_manger = $this->getTypedDataManager()
      ->getValidationConstraintManager();
    $constraints[] = $constraint_manger
      ->create(ComponentTreeStructureConstraint::PLUGIN_ID, [
        'basePropertyPath' => $this->getName() ?? '',
      ]);
    return $constraints;
  }

  /**
   * {@inheritdoc}
   */
  public function toRenderable(ComponentTreeEntityInterface|FieldableEntityInterface|null $entity = NULL, bool $isPreview = FALSE, array $suppressAnnotationsFor = []): array {
    // We have to allow NULL for the entity argument here for co-variance with
    // the parent interface, but we don't support it.
    \assert(!\is_null($entity));
    // ⚠️ We *could* convert to a render array directly. But that should not be
    // the source of truth. So we start from a Drupal Render API-agnostic point,
    // and map that into a render array. This guarantees none of this will ever
    // rely on Render API specifics.
    $renderable_component_tree = $this->getHydratedTree();
    $hydrated = $renderable_component_tree->getTree();

    \assert(\array_keys($hydrated) === [self::ROOT_UUID]);
    $build = self::renderify(self::buildRenderingContext($this, $entity), $hydrated, $isPreview, $suppressAnnotationsFor);

    // @see \Drupal\Core\Entity\EntityViewBuilder::getBuildDefaults()
    CacheableMetadata::createFromObject($renderable_component_tree)
      ->addCacheableDependency($entity)
      ->applyTo($build);

    return $build;
  }

  /**
   * Recursively converts an array generated by ::getValue() to a render array.
   *
   * @param string $componentRenderingContext
   *   A rendering context for adding debugging context when rendering fails.
   * @param array $hydrated
   *   An array generated by ::getValue().
   * @param bool $isPreview
   *   TRUE if is preview.
   *
   * @return array
   *   The corresponding render array.
   */
  private static function renderify(string $componentRenderingContext, array $hydrated, bool $isPreview = FALSE, array $suppressAnnotationsFor = []) {
    $build = [];
    foreach ($hydrated as $component_subtree_uuid => $component_instances) {
      foreach ($component_instances as $component_instance_uuid => $component_instance) {
        try {
          // If an exception occurred during hydration, re-throw it. (Such an
          // exception results in explicit input not being available, and hence
          // rendering the component instance being impossible.) This allows it
          // to be presented in the same way as exceptions during rendering.
          // @see ::getHydratedValue()
          if (\array_key_exists(self::HYDRATION_EXCEPTION_KEY, $component_instance)) {
            throw $component_instance[self::HYDRATION_EXCEPTION_KEY];
          }

          $component = Component::load($component_instance['component']);
          \assert($component instanceof Component);
          $source = $component->getComponentSource();
          $element = $source->renderComponent($component_instance, $component->getSlotDefinitions(), $component_instance_uuid, $isPreview);

          // A component instance provided by a
          // ComponentSourceWithSwitchCasesInterface is guaranteed to either be
          // a `switch` or a `case`. The `switch` component instance is the
          // inevitable container (that may render nothing at all on the live
          // site) that contains the different possible `case`s. On the live
          // site, only ONE `case` will ever be rendered: the negotiated one.
          if ($source instanceof ComponentSourceWithSwitchCasesInterface && !$isPreview) {
            if ($source->isCase() && !$source->isNegotiatedCase($component_instance)) {
              unset($component_instance['slots']);
            }
          }

          // Wrap each rendered component instance in HTML comments that allow
          // the client side to identify it. Suppressed for components the
          // consumer declared non-addressable (per-content template chrome):
          // they render as inert markup, while still rendering in preview
          // mode so the slot markers their Twig emits keep working.
          if ($isPreview && !\array_key_exists($component_instance_uuid, $suppressAnnotationsFor)) {
            $element['#prefix'] = Markup::create("<!-- canvas-start-$component_instance_uuid -->");
            $element['#suffix'] = Markup::create("<!-- canvas-end-$component_instance_uuid -->");
          }

          // Associate the `Component` config entity cache tag with every
          // rendered component instance — remove the need for each
          // `ComponentSource` plugin to do this in an awkward way in their
          // `::renderComponent()`.
          // This also associates any cache contexts and max-age; both may be
          // used for dynamic config overrides.
          // @todo Ensure this does not appear anymore for components omitted due to field/entity access in https://www.drupal.org/i/3559820
          CacheableMetadata::createFromRenderArray($element)
            ->addCacheableDependency($component)
            ->applyTo($element);

          // Figure out the slots, if there are any.
          if ($source instanceof ComponentSourceWithSlotsInterface && !empty($component_instance['slots'])) {
            $slots = [];
            foreach ($component_instance['slots'] as $slot => $slot_value) {
              // Handle default slot value: convert to renderable using either
              // #plain_text or `#markup`.
              if (!$isPreview && \is_string($slot_value)) {
                $slots[$slot] = !str_starts_with($slot_value, '<')
                  // Match how Drupal core handles string values for components.
                  // @see https://www.drupal.org/node/3398039
                  ? ['#plain_text' => $slot_value]
                  // TRICKY: this goes beyond what Drupal core appears to allow
                  // in https://www.drupal.org/node/3398039, but the SDC plugin
                  // manager accepts this as a valid SDC definition, so Canvas
                  // has no choice but to support it.
                  : ['#markup' => $slot_value];
              }
              // When previewing and the slot value is a default: omit the
              // default in favor of a placeholder div.
              elseif ($isPreview && \is_string($slot_value)) {
                $slots[$slot] = ['#markup' => Markup::create('<div class="canvas--slot-empty-placeholder"></div>')];
              }
              // Explicit slot value: renderify, just like the rest of the
              // component tree.
              else {
                $slots += self::renderify($componentRenderingContext, [$slot => $slot_value], $isPreview, $suppressAnnotationsFor);
              }
            }

            $source->setSlots($element, $slots);
          }

          // Wrap in a render-safe container.
          // @todo Remove all the wrapping-in-RenderSafeComponentContainer complexity and make ComponentSourceInterface::renderComponent() for that instead in https://www.drupal.org/i/3521041
          $build[$component_subtree_uuid][$component_instance_uuid] = [
            '#type' => RenderSafeComponentContainer::PLUGIN_ID,
            '#component' => $element,
            '#component_context' => $componentRenderingContext,
            '#component_uuid' => $component_instance_uuid,
            '#is_preview' => $isPreview,
          ];
        }
        // @todo Remove when https://www.drupal.org/i/2367555 is fixed.
        catch (EnforcedResponseException | FormAjaxException $e) {
          throw $e;
        }
        catch (\Throwable $e) {
          // @todo Remove all the wrapping-in-RenderSafeComponentContainer complexity and make ComponentSourceInterface::renderComponent() for that instead in https://www.drupal.org/i/3521041
          $build[$component_subtree_uuid][$component_instance_uuid] = RenderSafeComponentContainer::handleComponentException(
            $e,
            $componentRenderingContext,
            $isPreview,
            $component_instance_uuid,
            CacheableMetadata::createFromObject($component ?? NULL),
          );
        }
      }
    }
    return $build;
  }

  /**
   * Computes the cacheability of this computed property.
   *
   * @return \Drupal\Core\Cache\CacheableMetadata
   *   The cacheability of the computed value.
   */
  private function getCacheability(): CacheableMetadata {
    // @todo Once bundle-level defaults for `tree` + `inputs` are supported, this should also include cacheability of whatever config that is stored in.
    // @see \Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItem::preSave()

    $root = $this->getRoot();
    if ($root instanceof EntityAdapter) {
      return CacheableMetadata::createFromObject($root->getEntity());
    }

    // This appears to be an ephemeral component tree, hence it is uncacheable.
    return (new CacheableMetadata())->setCacheMaxAge(0);
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheTags() {
    return $this->getCacheability()->getCacheTags();
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheContexts() {
    return $this->getCacheability()->getCacheContexts();
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheMaxAge() {
    return $this->getCacheability()->getCacheMaxAge();
  }

  /**
   * Gets the tree as a hydrated tree value object.
   */
  private function getHydratedTree(): HydratedTree {
    return new HydratedTree(
      $this->getHydratedValue(),
      $this->getCacheability(),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function onChange($delta): void {
    parent::onChange($delta);
    $this->graph = NULL;
  }

  /**
   * Constructs a depth-first graph based on the given tree.
   *
   * @param \Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItemList $tree
   *   Tree to construct a graph for.
   *
   * @return array<string, array{'edges': array<string, TRUE>}>
   *
   * @see \Drupal\Component\Graph\Graph
   */
  private static function constructDepthFirstGraph(ComponentTreeItemList $tree): array {
    // Transform the tree to the input expected by Drupal's Graph utility.
    $graph = [];
    foreach ($tree as $value) {
      \assert($value instanceof ComponentTreeItem);
      $parent_uuid = $value->getParentUuid() ?? self::ROOT_UUID;
      $component_instance_uuid = $value->getUuid();
      $graph[$parent_uuid]['edges'][$component_instance_uuid] = TRUE;
    }

    // Use Drupal's battle-hardened Graph utility.
    $sorted_graph = (new Graph($graph))->searchAndSort();

    // Sort by weight, then reverse: this results in a depth-first sorted graph.
    \uasort($sorted_graph, SortArray::sortByWeightElement(...));
    $reverse_sorted_graph = array_reverse($sorted_graph);

    return $reverse_sorted_graph;
  }

  /**
   * @return \Generator<string, array{'slot': string, 'uuid': string}>
   */
  private function getSlotChildrenDepthFirst(): \Generator {
    if ($this->graph === NULL) {
      $this->graph = self::constructDepthFirstGraph($this);
    }
    $child_items = \array_filter($this->getValue(), static fn (array $item): bool => ($item['slot'] ?? NULL) !== NULL);
    $slot_map = \array_combine(\array_column($child_items, 'uuid'), \array_column($child_items, 'slot'));
    foreach ($this->graph as $vertex_key => $vertex) {
      $parent_uuid = $vertex_key;
      if ($parent_uuid === self::ROOT_UUID) {
        // Skip items at the root.
        continue;
      }
      // For each vertex (after the filtering above), all edges represent
      // child component instances placed in this slot.
      foreach (\array_keys($vertex['edges']) as $component_instance_uuid) {
        \assert(\is_string($component_instance_uuid));
        yield $parent_uuid => [
          'slot' => $slot_map[$component_instance_uuid],
          'uuid' => $component_instance_uuid,
        ];
      }
    }
  }

  private function getHydratedValue(): array {
    $hydrated = [];

    // Load and bulk set all unique Components used in this tree in a single
    // ::loadMultiple call.
    $components = Component::loadMultiple($this->getComponentIdList());

    // Hydrate all component instances, but only considering props. This
    // essentially means getting the values for each component instance, while
    // ignoring their slots. The result: a flat list of hydrated components, but
    // with all slots empty.
    foreach ($this as $item) {
      \assert($item instanceof ComponentTreeItem);
      $component_id = $item->getComponentId();
      $uuid = $item->getUuid();
      $component = $components[$component_id];
      \assert($component instanceof Component);
      $component->loadVersion($item->getComponentVersion());

      // Rendering always happens using the live implementation of a component,
      // so load the active version to determine the required props.
      $required_props_with_default_values_in_current_implementation = $component
        ->loadVersion($component->getActiveVersion())
        ->getComponentSource()
        ->getDefaultExplicitInput(only_required: TRUE);
      // Avoid side effects.
      $component->loadVersion($item->getComponentVersion());

      $source = $component->getComponentSource();
      try {
        $explicit_input = $source->getExplicitInput($uuid, $item);
      }
      catch (\Throwable $e) {
        $hydrated[$uuid] = [
          'component' => $component_id,
          // Store the exception to be re-thrown during rendering, so that it
          // can be presented in the same way as exceptions during rendering:
          // - without breaking the rendering of the entire component tree
          // - with the relevant details to a privileged user
          // - without exposing sensitive details to an unprivileged user
          self::HYDRATION_EXCEPTION_KEY => $e,
        ];
        // Continue hydrating the next component instance. (Without trying to
        // hydrate the slots of this component instance, since this component
        // instance won't be renderable anyway.)
        continue;
      }

      $hydrated[$uuid] = [
        'component' => $component_id,
      ] + $source->hydrateComponent(
        $explicit_input,
        $component->getSlotDefinitions(),
        $required_props_with_default_values_in_current_implementation,
      );
      \assert(!\array_key_exists('slots', $hydrated[$uuid]) || \is_array($hydrated[$uuid]['slots']));
    }

    // Transform the flat list of hydrated components into a hydrated component
    // tree, by assigning child components to their parent component's slot. If
    // this happens depth-first, then the tree will gradually be built, with the
    // last iteration assigning the last component to the component tree's root.
    foreach ($this->getSlotChildrenDepthFirst() as $parent_uuid => ['slot' => $slot, 'uuid' => $uuid]) {
      if ($parent_uuid === self::ROOT_UUID) {
        continue;
      }
      \assert(\array_key_exists('slots', $hydrated[$parent_uuid]) && \is_array($hydrated[$parent_uuid]['slots']));

      // Remove default slot value: this slot is populated.
      if (\array_key_exists($slot, $hydrated[$parent_uuid]['slots']) && \is_string($hydrated[$parent_uuid]['slots'][$slot])) {
        $hydrated[$parent_uuid]['slots'][$slot] = [];
      }

      // @phpstan-ignore-next-line
      \assert(!\array_key_exists($uuid, $hydrated[$parent_uuid]['slots'][$slot]));
      // @phpstan-ignore-next-line
      $hydrated[$parent_uuid]['slots'][$slot][$uuid] = $hydrated[$uuid];
      unset($hydrated[$uuid]);
    }
    return [self::ROOT_UUID => $hydrated];
  }

  private static function buildRenderingContext(ComponentTreeItemList $itemList, ComponentTreeEntityInterface|FieldableEntityInterface $entity): string {
    $entityId = $entity->isNew() ? '-' : $entity->id();
    if ($itemList->getName() !== NULL) {
      return \sprintf('%s %s (%s), field %s', $entity->getEntityType()->getLabel(), $entity->label(), $entityId, $itemList->getName());
    }
    return \sprintf('%s %s (%s)', $entity->getEntityType()->getLabel(), $entity->label(), $entityId);
  }

  /**
   * Retrieves the list of unique types of prop sources used.
   *
   * @return string[]
   *   A list of all unique prop source types in this list of component input
   *   values, for this component tree.
   */
  public function getPropSourceTypes(): array {
    $source_type_prefixes = [];
    foreach ($this as $item) {
      \assert($item instanceof ComponentTreeItem);
      /** @var \Drupal\canvas\Plugin\DataType\ComponentInputs $inputs */
      $inputs = $item->get('inputs');
      $source_type_prefixes = \array_merge($source_type_prefixes, $inputs->getPropSourceTypes());
    }
    return \array_unique($source_type_prefixes);
  }

  /**
   * Merges one exposed slot's per-entity field content into this tree.
   *
   * Each exposed slot is backed by its own `component_tree` field on the host
   * entity, holding an ordinary component tree. Its roots (empty `parent_uuid`,
   * empty `slot`) are the entity's override for the slot. When the field is
   * empty the entity has not overridden the slot, so the template's default
   * content stays in place. Otherwise the override replaces the default subtree
   * under the target (component_uuid, slot_name) entirely.
   *
   * @param string $parent_uuid
   *   The UUID of the template component instance that owns the slot.
   * @param string $slot_name
   *   The name of the slot on that component instance.
   * @param \Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItemList $slotField
   *   The entity's backing field for this slot.
   *
   * @return $this
   */
  public function injectSlotContent(string $parent_uuid, string $slot_name, ComponentTreeItemList $slotField): self {
    $override_roots = [];
    foreach ($slotField->componentTreeItemsIterator(self::inRootLevel()) as $item) {
      \assert($item instanceof ComponentTreeItem);
      $override_roots[] = $item->getUuid();
    }
    if (\count($override_roots) === 0) {
      return $this;
    }

    // The entity overrides this slot: remove the template's default subtree
    // under the target (component_uuid, slot_name) before injecting.
    $default_roots = [];
    foreach ($this->componentTreeItemsIterator(self::isChildOfComponentTreeItemSlot($parent_uuid, $slot_name)) as $item) {
      \assert($item instanceof ComponentTreeItem);
      $default_roots[] = $item->getUuid();
    }
    if (\count($default_roots) > 0) {
      $remove_uuids = \array_keys(self::collectSubtreeValues($this, $default_roots));
      // Remove by descending delta so earlier indices stay valid, and so the
      // surviving items keep their stored form (removeItem does not re-set
      // them, unlike a full setValue round-trip).
      $deltas_to_remove = [];
      foreach ($this->componentTreeItemsIterator() as $delta => $item) {
        \assert($item instanceof ComponentTreeItem);
        if (\in_array($item->getUuid(), $remove_uuids, TRUE)) {
          $deltas_to_remove[] = $delta;
        }
      }
      \rsort($deltas_to_remove);
      foreach ($deltas_to_remove as $delta) {
        $this->removeItem($delta);
      }
    }

    // Inject the entity's subtree, nesting each root under the template's real
    // target slot. Descendants keep their parent_uuid and slot, so the merged
    // tree hydrates correctly.
    foreach (self::collectSubtreeValues($slotField, $override_roots) as $uuid => $value) {
      if ($this->getComponentTreeItemByUuid($uuid) !== NULL) {
        throw new SubtreeInjectionException("Cannot inject subtree because some of its components are already in the final tree.");
      }
      if (\in_array($uuid, $override_roots, TRUE)) {
        // Re-root under the template's real slot, inserting parent_uuid + slot
        // in the canonical column order (right after component_id) so the row
        // matches the shape of any other nested row.
        $rerooted = [];
        foreach ($value as $key => $item_value) {
          if ($key === 'parent_uuid' || $key === 'slot') {
            continue;
          }
          $rerooted[$key] = $item_value;
          if ($key === 'component_id') {
            $rerooted['parent_uuid'] = $parent_uuid;
            $rerooted['slot'] = $slot_name;
          }
        }
        $value = $rerooted;
      }
      $this->appendItem($value);
    }
    return $this;
  }

}
