<?php

declare(strict_types=1);

namespace Drupal\canvas\ComponentSource;

use Drupal\canvas\Entity\Component;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\canvas\Style\ComponentStyles;
use Drupal\canvas\Utility\TypedDataHelper;
use Drupal\Component\Utility\NestedArray;
use Drupal\Component\Utility\SortArray;
use Drupal\Core\Config\Schema\Mapping;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Plugin\ContextAwarePluginAssignmentTrait;
use Drupal\Core\Plugin\ContextAwarePluginTrait;
use Drupal\Core\Plugin\PluginBase;
use Drupal\Core\Template\Attribute;
use Drupal\Core\Template\AttributeHelper;

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
    $settings = $this->settingsAffectingVersionHash(TypedDataHelper::castRawPhpTypes($typed_source_specific_settings));
    // Accepted styles equal to the source's default are not part of a
    // version's identity, so enabling styles creates no new versions.
    // @see \Drupal\canvas\Style\ComponentStyles::defaultSetting()
    if (\is_array($settings) && \array_key_exists('styles', $settings)) {
      if ($settings['styles'] === NULL || $settings['styles'] === ComponentStyles::defaultSetting()) {
        unset($settings['styles']);
      }
    }
    $normalized_data = [
      'settings' => $settings,
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
    SortArray::sortByKeyRecursive($normalized_data);
    $hash = \hash('xxh64', \json_encode($normalized_data, JSON_THROW_ON_ERROR));
    // 💡 If you are debugging why a version hash does not match, put a
    // conditional breakpoint here.
    return $hash;
  }

  /**
   * Filters the source-specific settings that define a version's identity.
   *
   * By default, ALL settings affect the version hash. A component source that
   * retains purely *derived* metadata in its settings — data that cannot cause
   * changes to component instances because it is fully derived from data that
   * is already hashed — must override this method to strip it, so that
   * recomputing or backfilling that metadata never changes existing component
   * version ids.
   *
   * @param mixed $settings
   *   The config-schema-casted source-specific settings.
   *
   * @return mixed
   *   The settings that affect the version hash.
   *
   * @see ::generateVersionHash()
   * @see \Drupal\canvas\Plugin\Canvas\ComponentSource\JsonSchemaPropsComponentSourceBase::settingsAffectingVersionHash()
   */
  protected function settingsAffectingVersionHash(mixed $settings): mixed {
    return $settings;
  }

  /**
   * Applies a filtered style selection to a rendered component instance.
   *
   * By default, core applies `#styles` to the root element and attaches each
   * style's library. Sources whose root is not the element they render (code
   * components render `<canvas-island>`) override this.
   *
   * @param array $build
   *   The render array returned by ::renderComponent().
   * @param array<string, string> $filtered
   *   A selection filtered by ComponentStyles::filter(): never empty.
   * @param array{className: ?string, style: ?array<string, string>, libraries: list<string>, attributes: list<array{attribute: string, value: string}>} $resolved
   *   The selection resolved by ComponentStyles::resolve().
   *
   *   Applies each option the way core's Style API applies styles that target
   *   any element: merged into `#attributes` in selection order, with the
   *   style's library attached. Canvas does this itself rather than setting
   *   core's `#styles`, so output is identical with core's Style API or the
   *   polyfill.
   *
   * @see \Drupal\Core\Theme\Style\StyleDefinition::applyOrBubbleOnArray()
   */
  public function applyStyles(array &$build, array $filtered, array $resolved): void {
    foreach ($resolved['attributes'] as ['attribute' => $attribute, 'value' => $value]) {
      // Like core: a class value is one entry, however many classes it has.
      $build['#attributes'] = AttributeHelper::mergeCollections(
        $build['#attributes'] ?? [],
        [$attribute => $attribute === 'class' ? [$value] : $value],
      );
    }
    foreach ($resolved['libraries'] as $library) {
      $build['#attached']['library'][] = $library;
    }
    // Each named part gets `{part}_attributes`, always, so templates can
    // print it without checking: `<h1{{ heading_attributes.addClass('x') }}>`.
    // @see \Drupal\canvas\Style\ComponentStyles::partsFor()
    foreach (\array_keys($this->configuration['style_parts'] ?? []) as $part) {
      $attributes = [];
      foreach ($resolved['parts'][$part]['attributes'] ?? [] as ['attribute' => $attribute, 'value' => $value]) {
        $attributes = AttributeHelper::mergeCollections($attributes, [$attribute => $attribute === 'class' ? [$value] : $value]);
      }
      $build['#props'][$part . '_attributes'] = new Attribute($attributes);
      foreach ($resolved['parts'][$part]['libraries'] ?? [] as $library) {
        $build['#attached']['library'][] = $library;
      }
    }
  }

  private static function normalizeSlotDefinitions(array $slot_definitions): array {
    \array_walk($slot_definitions, function (&$slot_definition) {
      \reset($slot_definition);
    });
    return \array_reduce(
      \array_keys(\array_filter($slot_definitions, \is_array(...))),
      static function (array $carry, string $slot_name) use ($slot_definitions): array {
        $slot_examples = $slot_definitions[$slot_name]['examples'] ?? [];
        return $carry + [
          $slot_name => [
            'title' => $slot_definitions[$slot_name]['title'],
            'example' => $slot_examples === [] ? '' : \current($slot_examples),
          ],
        ];
      },
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
