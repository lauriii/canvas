<?php

declare(strict_types=1);

namespace Drupal\canvas\ComponentSource;

use Drupal\canvas\Entity\Component;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Config\Schema\Mapping;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Plugin\ContextAwarePluginAssignmentTrait;
use Drupal\Core\Plugin\ContextAwarePluginTrait;
use Drupal\Core\Plugin\PluginBase;
use Drupal\Core\TypedData\PrimitiveInterface;
use Drupal\Core\TypedData\TraversableTypedDataInterface;
use Drupal\Core\TypedData\TypedDataInterface;

/**
 * @internal
 *
 * Defines a base class for component source plugins.
 *
 * @see \Drupal\canvas\Attribute\ComponentSource
 * @see \Drupal\canvas\ComponentSource\ComponentSourceInterface
 * @see \Drupal\canvas\ComponentSource\ComponentSourceManager
 */
abstract class ComponentSourceBase extends PluginBase implements ComponentSourceInterface {

  use ContextAwarePluginAssignmentTrait;
  use ContextAwarePluginTrait;

  public function determineDefaultFolder(): string {
    return 'Other';
  }

  public function getSourceSpecificComponentId(): string {
    return $this->getConfiguration()['local_source_id'];
  }

  public function generateVersionHash(): string {
    // @phpstan-ignore-next-line
    $typed_source_specific_settings = \Drupal::service(TypedConfigManagerInterface::class)->createFromNameAndData(
      'canvas.component_source_settings.' . $this->getPluginId(),
      // TRICKY: the ComponentSource plugin instance always receives the local
      // source ID that identifies the component within that source. But that
      // plugin ID is not part of the config schema.
      // @see `type: canvas.component_source_settings.*`
      array_diff_key($this->configuration, array_flip(['local_source_id'])),
    );
    \assert($typed_source_specific_settings instanceof Mapping);
    $normalized_data = [
      // ⚠️ TRICKY: Use config-schema-*casted* values, NOT the raw
      // `Mapping::toArray()`. A setting generated in PHP (e.g. an SDC
      // `examples` default of int `2`) hashes differently from its
      // config-schema-cast form (string `"2"`, since core types
      // `field.value.list_float.value` as `string`). That cast only kicks in
      // once config is read back through validation — config import, recipes,
      // config sync — and NOT on a clean install, which skips config
      // validation. That asymmetry is why config-export-driven setups (e.g.
      // Drupal CMS recipes) hit this and few others did. Casting here makes
      // generation and validation agree.
      // @see \Drupal\canvas\Entity\Component::validateActiveVersion()
      'settings' => self::castRawTypedConfigToPhpTypes($typed_source_specific_settings),
      'slot_definitions' => $this instanceof ComponentSourceWithSlotsInterface
        ? self::normalizeSlotDefinitions($this->getSlotDefinitions())
        : [],
      'schema' => $this->getExplicitInputDefinitions(),
    ];
    // Intuitively, we'd want to rely on:
    // - config export order (https://www.drupal.org/node/3230199)
    // - slot definition order
    // - explicit input schema order
    // But that would lead to unnecessary new versions: the order of slots and
    // explicit inputs (SDC: "props") does not impact existing component
    // instances, other than their corresponding component instance form perhaps
    // showing a different order. New versions of Component config entities are
    // only warranted if there is a change in the data needing to be stored for
    // a component instance.
    self::recursiveKsort($normalized_data);
    $hash = \hash('xxh64', \json_encode($normalized_data, JSON_THROW_ON_ERROR));
    // 💡 If you are debugging why a version hash does not match, put a
    // conditional breakpoint here.
    return $hash;
  }

  protected static function recursiveKsort(array &$array): void {
    ksort($array);
    foreach ($array as &$value) {
      if (\is_array($value)) {
        self::recursiveKsort($value);
      }
    }
  }

  /**
   * Recursively calls PrimitiveInterface::getCastedValue()
   *
   * Unlike `Mapping::toArray()` (raw, un-cast values, possibly strings), this
   * casts every primitive leaf via `PrimitiveInterface::getCastedValue()`, to
   * use the corresponding native PHP type.
   *
   * @param \Drupal\Core\TypedData\TypedDataInterface $element
   *   The typed config element to extract from.
   *
   * @return mixed
   *   Scalar/NULL for primitives; an array (keys preserved) for mappings and
   *   sequences.
   */
  private static function castRawTypedConfigToPhpTypes(TypedDataInterface $element): mixed {
    if ($element instanceof PrimitiveInterface) {
      return $element->getCastedValue();
    }
    if ($element instanceof TraversableTypedDataInterface) {
      // Preserve `toArray()`'s NULL-vs-array distinction: an optional, unset
      // sequence/mapping stays NULL rather than becoming an empty array, which
      // would needlessly change the hash of components that have such a key.
      if ($element->getValue() === NULL) {
        return NULL;
      }
      $casted = [];
      foreach ($element as $name => $child) {
        $casted[$name] = self::castRawTypedConfigToPhpTypes($child);
      }
      return $casted;
    }
    // Anything that is neither a primitive nor traversable (e.g. an `ignore`
    // element) has no canonical config-schema type; use its value verbatim.
    return $element->getValue();
  }

  private static function normalizeSlotDefinitions(array $slot_definitions): array {
    \array_walk($slot_definitions, function (&$slot_definition) {
      \reset($slot_definition);
    });
    return \array_reduce(
      \array_keys(\array_filter($slot_definitions, \is_array(...))),
      static fn(array $carry, string $slot_name) => $carry + [
        $slot_name => [
          'title' => $slot_definitions[$slot_name]['title'],
          'example' => \current($slot_definitions[$slot_name]['examples'] ?? []) ?: '',
        ],
      ],
      []
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getConfiguration(): array {
    return $this->configuration;
  }

  /**
   * {@inheritdoc}
   */
  public function setConfiguration(array $configuration): void {
    $this->configuration = NestedArray::mergeDeep($this->defaultConfiguration(), $configuration);
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function getPluginDefinition(): array {
    $definition = parent::getPluginDefinition();
    \assert(\is_array($definition));
    return $definition;
  }

  /**
   * Gets information about the explicit inputs.
   *
   * @return array<string, mixed>
   *   Keys are names of explicit inputs. Values are some normalized schema
   *   representation, for example:
   *   - JSON Schema (SDCs, code components)
   *   - config schema (Block plugins)
   *   - …
   */
  abstract protected function getExplicitInputDefinitions(): array;

  /**
   * {@inheritdoc}
   */
  public function getResolvedExplicitInput(string $uuid, ComponentTreeItem $item, ?FieldableEntityInterface $host_entity = NULL): array {
    $explicit_input = $this->getExplicitInput($uuid, $item, $host_entity);
    $component = $item->getComponent();
    \assert($component instanceof Component);
    $required_props_with_default_values_in_current_implementation = $component
      ->loadVersion($component->getActiveVersion())
      ->getComponentSource()
      ->getDefaultExplicitInput(only_required: TRUE);
    // Avoid side effects.
    $component->loadVersion($item->getComponentVersion());

    return $this->hydrateComponent(
      explicit_input: $explicit_input,
      slot_definitions: [],
      // Return the stored explicit input, populating values for required
      // explicit inputs in the active version of the Component (i.e. the live
      // implementation).
      active_required_explicit_inputs: $required_props_with_default_values_in_current_implementation,
    );
  }

  /**
   * {@inheritdoc}
   */
  public function optimizeExplicitInput(array $values): array {
    // Nil-op.
    return $values;
  }

  /**
   * @todo Remove in clean-up follow-up; minimize non-essential changes.
   */
  public function checkRequirements(): void {
    $discovery_class = $this->getPluginDefinition()['discovery'];
    // @phpstan-ignore globalDrupalDependencyInjection.useDependencyInjection
    $discovery = \Drupal::classResolver($discovery_class);
    \assert($discovery instanceof ComponentCandidatesDiscoveryInterface);

    $discovery->checkRequirements($this->getSourceSpecificComponentId());
  }

}
