<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\DataType;

use Drupal\canvas\ComponentSource\ComponentInstanceInputsConfigSchemaGeneratorInterface;
use Drupal\canvas\Entity\Component;
use Drupal\canvas\MissingComponentInputsException;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\canvas\PropExpressions\StructuredData\ContentAwareDependentInterface;
use Drupal\canvas\PropExpressions\StructuredData\StructuredDataPropExpression;
use Drupal\canvas\PropSource\AdaptedPropSource;
use Drupal\canvas\PropSource\PropSource;
use Drupal\canvas\PropSource\StaticPropSource;
use Drupal\Component\Serialization\Json;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Config\Schema\Mapping;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\DependencyInjection\ClassResolverInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\Attribute\DataType;
use Drupal\Core\TypedData\TypedData;

/**
 * @phpstan-import-type PropSourceArray from \Drupal\canvas\PropSource\PropSourceBase
 * @phpstan-import-type AdaptedPropSourceArray from \Drupal\canvas\PropSource\PropSourceBase
 * @phpstan-import-type DefaultRelativeUrlPropSourceArray from \Drupal\canvas\PropSource\PropSourceBase
 * @phpstan-type SingleComponentInputArray array<string, PropSourceArray|AdaptedPropSourceArray|DefaultRelativeUrlPropSourceArray>
 * @see \Drupal\canvas\ComponentSource\ComponentSourceInterface::optimizeExplicitInput()
 * @see \Drupal\canvas\Plugin\Canvas\ComponentSource\JsonSchemaPropsComponentSourceBase::optimizeExplicitInput()
 * @see \Drupal\canvas\Plugin\Canvas\ComponentSource\JsonSchemaPropsComponentSourceBase::collapse()
 * @see \Drupal\canvas\Plugin\Canvas\ComponentSource\JsonSchemaPropsComponentSourceBase::uncollapse()
 * @phpstan-type OptimizedExplicitInput bool|int|float|string|bool[]|int[]|float[]|string[]
 * @phpstan-type OptimizedSingleComponentInputArray array<string, PropSourceArray|AdaptedPropSourceArray|DefaultRelativeUrlPropSourceArray|OptimizedExplicitInput>
 */
#[DataType(
  id: "component_inputs",
  label: new TranslatableMarkup("Component inputs"),
  description: new TranslatableMarkup("The input values for the components in a component tree: without structure"),
  // TRICKY: this does not provide validation constraints, because this is
  // validated at the component instance level. Component (instance) inputs can
  // only be validated by the ComponentSource plugin providing this component
  // that powers this component instance (and is referenced using the Component
  // ID and version).
  // @see \Drupal\canvas\ComponentSource\ComponentSourceInterface::validateComponentInput()
  // @see \Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItem
  // @see \Drupal\canvas\Plugin\Validation\Constraint\ValidComponentTreeItemConstraintValidator
)]
final class ComponentInputs extends TypedData implements ContentAwareDependentInterface {

  /**
   * The data value.
   *
   * @var string
   *
   * @todo Delete this property after https://www.drupal.org/project/drupal/issues/2232427
   */
  protected string $value;

  /**
   * The parsed data value.
   *
   * @var OptimizedSingleComponentInputArray
   */
  protected array $inputs = [];

  /**
   * {@inheritdoc}
   */
  public function calculateDependencies(FieldableEntityInterface|FieldItemListInterface|null $host_entity = NULL): array {
    $dependencies = [];
    foreach ($this->getPropSources() as $prop_source) {
      $dependencies = NestedArray::mergeDeep($dependencies, $prop_source->calculateDependencies($host_entity));
    }
    return $dependencies;
  }

  /**
   * {@inheritdoc}
   */
  public function getValue() {
    // @todo Uncomment next line and delete last line after https://www.drupal.org/project/drupal/issues/2232427
    // return $this->inputs;
    // Fall back to NULL if not yet initialized, to allow validation.
    // @see \Drupal\canvas\Plugin\Validation\Constraint\ValidComponentTreeItemConstraintValidator
    return $this->value ?? NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function applyDefaultValue($notify = TRUE) {
    // Default to the empty JSON object.
    $this->setValue('{}', $notify);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function setValue($value, $notify = TRUE): void {
    if (\is_string($value)) {
      // If there are no inputs, an empty array is a valid value.
      \assert(str_starts_with($value, '{') || $value === '[]');
      // @todo Delete next line; update this code to ONLY do the JSON-to-PHP-object parsing after https://www.drupal.org/project/drupal/issues/2232427 lands — that will allow specifying the "json" serialization strategy rather than only PHP's serialize().
      $this->value = $value;
      $this->inputs = Json::decode($value);
    }
    else {
      \assert(\is_array($value));
      $this->inputs = $value;
      $this->value = Json::encode($value);
    }

    // Notify the parent of any changes.
    if ($notify && isset($this->parent)) {
      $this->parent->onChange($this->name);
    }
  }

  /**
   * Retrieves the list of unique types of prop sources used.
   *
   * @return string[]
   *   A list of all unique prop source types in this list of component input
   *   values, for this component tree.
   */
  public function getPropSourceTypes(): array {
    return array_unique(\array_map(
      PropSource::getTypePrefix(...),
      iterator_to_array($this->getPropSources()),
    ));
  }

  /**
   * Gets all prop sources that have a particular dependency.
   *
   * @param string $type
   *   The dependency type (e.g., `config`, `module`, `theme`, or `content`).
   * @param string $name
   *   The dependency name (for example, the full name of a config entity).
   * @param \Drupal\Core\Entity\FieldableEntityInterface|null $host_entity
   *   (optional) The host entity of this set of inputs, if applicable.
   *
   * @return iterable<string, \Drupal\canvas\PropSource\PropSourceBase>
   *   An array of prop sources that have a dependency of the given type and
   *   name. The keys will be strings in the form of "INSTANCE_UUID:PROP_NAME".
   */
  public function getPropSourcesWithDependency(string $type, string $name, ?FieldableEntityInterface $host_entity = NULL): iterable {
    foreach ($this->getPropSources() as $key => $prop_source) {
      $dependencies = $prop_source->calculateDependencies($host_entity);
      if (\in_array($name, $dependencies[$type] ?? [], TRUE)) {
        yield $key => $prop_source;
      }
    }
  }

  /**
   * Gets all prop sources that use a particular expression class.
   *
   * @param string $expression_class
   *   The expression class (e.g. `\Drupal\canvas\PropExpressions\StructuredData\ReferenceFieldTypePropExpression::class`).
   *
   * @return iterable<string, \Drupal\canvas\PropSource\PropSourceBase>
   *   An array of prop sources that use a particular expression class.
   *   The keys will be strings in the form of "INSTANCE_UUID:PROP_NAME".
   */
  public function getPropSourcesUsingExpressionClass(string $expression_class): iterable {
    \assert(is_a($expression_class, StructuredDataPropExpression::class, TRUE));
    foreach ($this->getPropSources() as $key => $prop_source) {
      if ($prop_source instanceof AdaptedPropSource) {
        throw new \LogicException('@todo as soon as adapted prop sources are actually used');
      }
      if (property_exists($prop_source, 'expression') && is_a($prop_source->expression, $expression_class)) {
        yield $key => $prop_source;
      }
    }
  }

  /**
   * @return \Generator<string, \Drupal\canvas\PropSource\PropSourceBase>
   */
  private function getPropSources(): \Generator {
    $item = $this->getParent();
    \assert($item instanceof ComponentTreeItem);
    $source = $item->getComponent()?->getComponentSource();
    $default_prop_sources = $source !== NULL
      ? $source->getDefaultExplicitInput()
      // This component instance is invalid; validation will catch that.
      : [];

    foreach ($this->inputs as $name => $raw_prop_source) {
      if (!\is_array($raw_prop_source) || !\array_key_exists('sourceType', $raw_prop_source)) {
        // This is likely a *collapsed* StaticPropSource.
        /** @var OptimizedExplicitInput $raw_prop_source */
        // @see \Drupal\canvas\Plugin\Canvas\ComponentSource\JsonSchemaPropsComponentSourceBase::collapse()
        // @see \Drupal\canvas\Plugin\Canvas\ComponentSource\JsonSchemaPropsComponentSourceBase::uncollapse()
        try {
          $parsed_default_prop_source = \array_key_exists($name, $default_prop_sources) && \is_array($default_prop_sources[$name]) && \array_key_exists('sourceType', $default_prop_sources[$name])
            ? PropSource::parse($default_prop_sources[$name])
            : NULL;
          // If it indeed was a collapsed StaticPropSource, un-collapse it.
          if ($parsed_default_prop_source instanceof StaticPropSource) {
            // When looking at stored data, we must treat user data that we once
            // accepted (or are in the process of validating) with the utmost
            // respect.
            // @see https://en.wikipedia.org/wiki/Robustness_principle
            yield "name" => $parsed_default_prop_source->withValue($raw_prop_source, allow_empty: TRUE);
          }
        }
        catch (\LogicException) {
        }

        // This isn't a component source using \Drupal\canvas\Plugin\Canvas\ComponentSource\JsonSchemaPropsComponentSourceBase.
        // @todo Move this logic into \Drupal\canvas\Plugin\Canvas\ComponentSource\JsonSchemaPropsComponentSourceBase.
        // @see https://www.drupal.org/project/canvas/issues/3467954
        continue;
      }

      // phpcs:ignore
      /** @var PropSourceArray|AdaptedPropSourceArray|DefaultRelativeUrlPropSourceArray $raw_prop_source */
      try {
        yield "$name" => PropSource::parse($raw_prop_source);
      }
      catch (\LogicException) {
        // @see https://en.wikipedia.org/wiki/Robustness_principle
        continue;
      }
    }
  }

  /**
   * Gets the values for a given component instance.
   *
   * @return OptimizedSingleComponentInputArray
   *
   * @throws \Drupal\Core\TypedData\Exception\MissingDataException
   * @throws \Drupal\canvas\MissingComponentInputsException
   */
  public function getValues(): array {
    $item = $this->getParent();
    \assert($item instanceof ComponentTreeItem);
    $component_instance_uuid = $item->getUuid();
    $source = $item->getComponent()?->getComponentSource();
    if ($source === NULL) {
      throw new \UnexpectedValueException('Missing component source');
    }
    // Still in default value state.
    // @see ::applyDefaultValue()
    if ($this->value === '{}') {
      if ($source->requiresExplicitInput()) {
        throw new MissingComponentInputsException($component_instance_uuid);
      }
      return [];
    }

    return $this->inputs;
  }

  /**
   * Resolves the config schema mapping for a component instance.
   *
   * This is the single source of truth for determining which inputs are
   * translatable. Both config entity translation (ComponentInputsMapping)
   * and content entity translation (getTranslatableInputKeys()) use this logic.
   *
   * @param string $component_id
   *   The component ID.
   * @param string $component_version
   *   The component version.
   * @param array $actual_inputs
   *   The actual input values provided for this component instance.
   *
   * @return array
   *   The config schema mapping with translatable markers.
   *
   * @see \Drupal\canvas\Config\Schema\ComponentInputsMapping
   * @see https://www.drupal.org/project/canvas/issues/3583684
   */
  public static function resolveConfigSchemaMapping(string $component_id, string $component_version, array $actual_inputs): array {
    $component = Component::load($component_id);
    // Non-existent Component. Robustness principle.
    if ($component === NULL) {
      // @see https://en.wikipedia.org/wiki/Robustness_principle
      return [];
    }

    try {
      $component_source = $component->loadVersion($component_version)
        ->getComponentSource();
    }
    // Non-existent Component version. Fall back to active version.
    // @see \Drupal\canvas\Entity\VersionedConfigEntityBase::assertVersionExists()
    catch (\OutOfRangeException) {
      try {
        $component_source = $component->loadVersion($component->getActiveVersion())
          ->getComponentSource();
      }
      catch (\OutOfRangeException) {
        // Active version also failed. Robustness.
        return [];
      }
    }

    $generator_class = $component_source->getPluginDefinition()['inputs_config_schema_generator'] ?? NULL;
    \assert(\is_string($generator_class));
    // TRICKY: this cannot use constructor injection because both callers
    // (ComponentInputsMapping — a config schema class, and ComponentTreeItem —
    // a field item) cannot use constructor injection. Using
    // \Drupal::service() is pragmatic and consistent with this class already
    // using static lookups such as Component::load().
    $generator = \Drupal::service(ClassResolverInterface::class)
      ->getInstanceFromDefinition($generator_class);
    \assert($generator instanceof ComponentInstanceInputsConfigSchemaGeneratorInterface);

    $mapping = $generator->getConfigSchemaMapping($component_source);
    $mapping = $generator->refineForInstance($mapping, $actual_inputs, $component_id, $component_version);

    return $mapping;
  }

  /**
   * Determines which input keys are translatable for this component instance.
   *
   * Uses the same ComponentInstanceInputsConfigSchemaGeneratorInterface
   * strategy that ComponentInputsMapping uses for config entity translation.
   * This provides a single source of truth for both config translation and
   * content translation.
   *
   * @return string[]
   *   The input keys that are translatable for this component instance.
   *
   * @see ::resolveConfigSchemaMapping()
   * @see \Drupal\canvas\Config\Schema\ComponentInputsMapping
   * @see https://www.drupal.org/project/canvas/issues/3583684
   */
  public function getTranslatableInputKeys(): array {
    $component_instance = $this->getParent();
    \assert($component_instance instanceof ComponentTreeItem);

    try {
      $actual_inputs = $component_instance->getInputs() ?? [];
    }
    // Ensure this works correctly even on invalid data.
    catch (\Exception) {
      $actual_inputs = [];
    }

    $mapping = self::resolveConfigSchemaMapping(
      $component_instance->getComponentId(),
      $component_instance->getComponentVersion(),
      $actual_inputs,
    );

    return \array_keys(\array_filter(
      $mapping,
      static fn ($def, $key) => self::isTranslatableInputAccordingToConfigSchema($def, $actual_inputs[$key] ?? NULL),
      ARRAY_FILTER_USE_BOTH,
    ));
  }

  /**
   * Helper to determine whether a config schema definition is translatable.
   *
   * Designed to maximally reuse core's config schema infrastructure. This
   * leverages Mapping::hasTranslatableElements(), which is during validation of
   * config entities. An ephemeral `type: mapping` config schema is used to
   * achieve reuse.
   *
   * @param array $config_schema_definition_for_input
   *   A config schema definition for a component's explicit input. This may be
   *   as simple as a single scalar value, or as complex as an entire tree.
   * @param mixed $actual_input
   *   The actual stored value for this key of the component instance's inputs.
   *
   * @return bool
   *   Whether $config_schema_definition_for_input is considered translatable:
   *   - if it has `translatable: true`
   *   - if after resolving, it has `translatable: true`
   *   - if it is not describing a scalar value but a tree structure: if either
   *     of those are true at any level
   *
   * @internal
   */
  private static function isTranslatableInputAccordingToConfigSchema(array $config_schema_definition_for_input, mixed $actual_input): bool {
    static $typed_config;
    if ($typed_config === NULL) {
      $typed_config = \Drupal::service('config.typed');
      \assert($typed_config instanceof TypedConfigManagerInterface);
    }

    // Build a data definition for a `type: mapping` containing a single key in
    // the mapping, with full config schema type resolving (e.g. `type: label`
    // is marked translatable and `type: required_label` is not, but because it
    // extends `type: label`, it inherits the translatability).
    $ephemeral_mapping = new Mapping($typed_config->buildDataDefinition(
      definition: [
        'type' => 'mapping',
        'mapping' => [
          'is_this_translatable' => $config_schema_definition_for_input,
        ],
      ],
      value: $actual_input,
    ));

    // Due to the way config schema ignores missing values (Mapping::parse()) it
    // is required to pass a NULL value for the config schema definition being
    // checked for translatability; otherwise it won't resolve at all.
    // @see \Drupal\Core\Config\Schema\ArrayElement::parse()
    $ephemeral_mapping->setValue(['is_this_translatable' => $actual_input]);
    return $ephemeral_mapping->hasTranslatableElements();
  }

}
