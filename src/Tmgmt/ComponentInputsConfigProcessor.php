<?php

namespace Drupal\canvas\Tmgmt;

use Drupal\canvas\Config\Schema\ComponentInputsMapping;
use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\ComponentInterface;
use Drupal\canvas\Plugin\Canvas\ComponentSource\JsonSchemaPropsComponentInstanceInputsConfigSchemaGenerator;
use Drupal\canvas\Plugin\Canvas\ComponentSource\JsonSchemaPropsComponentSourceBase;
use Drupal\Core\Config\Schema\Mapping;
use Drupal\Core\Config\Schema\Sequence;
use Drupal\Core\Config\Schema\TypedConfigInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\tmgmt_config\DefaultConfigProcessor;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Extracts translatables from Canvas component instances' inputs in config.
 *
 * Note that the config schema generated for each component instance's `inputs`
 * is limited to the level of granularity that each component source's
 * `inputs_config_schema_generator` implementation chose.
 *
 * @see \Drupal\canvas\ComponentSource\ComponentInstanceInputsConfigSchemaGeneratorInterface
 * @see \Drupal\canvas\Config\Schema\ComponentInputsMapping
 *
 * For example, for the `block` ComponentSource plugin, a full config schema is
 * provided, potentially for deeply nested values. By contrast, for the `js`
 * ComponentSource plugin, each input has `type: ignore`, but may still be
 * marked translatable, and the details are delegated to a custom
 * `form_element_class`. This means it's that custom class that decides what
 * values should be generated and stored; this could mean it ends up storing
 * more than only a single translatable string: it could store an array of
 * translatable strings, or some key-value pairs of which only some contain
 * translatable strings.
 * This is how Drupal core's `config_translation` system
 * (and its UI) are designed to work.
 *
 * However, TMGMT requires the actual translatable strings to be extracted. This
 * is completely different. It limits the usefulness of a custom
 * `form_element_class`: it forces a custom `tmgmt_config_processor` to be
 * defined to extract the translatable strings that otherwise would have been
 * handled as needed by the `form_element_class`.
 *
 * @see \Drupal\tmgmt_config\Plugin\tmgmt\Source\ConfigSource::getConfigProcessor()
 *
 * The surprising final piece to this is that the `form_element_class` *is* used
 * by TMGMT to actually save the config: its `::setConfig()` method is called.
 *
 * @internal
 */
final class ComponentInputsConfigProcessor extends DefaultConfigProcessor implements ContainerInjectionInterface {

  public function __construct(
    private readonly TypedConfigManagerInterface $typedConfigManager,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get(TypedConfigManagerInterface::class),
      $container->get(EntityTypeManagerInterface::class),
    );
  }

  /**
   * Build a concrete config schema from a static prop source.
   *
   * @param string $prop_name
   *   Prop name.
   * @param \Drupal\canvas\Plugin\Canvas\ComponentSource\JsonSchemaPropsComponentSourceBase $component_source
   *   Component source.
   *
   * @return array
   *   Concrete schema definition for the prop.
   */
  private function buildConcreteSchemaFromPropSource(string $prop_name, JsonSchemaPropsComponentSourceBase $component_source): array {
    $concrete_schema = [
      'type' => 'ignore',
      'label' => $component_source->getMetadata()->schema['properties'][$prop_name]['title'] ?? $prop_name,
    ];
    try {
      $static_prop_source = $component_source->getDefaultStaticPropSource($prop_name, FALSE);
    }
    catch (\OutOfRangeException) {
      return $concrete_schema;
    }
    $field_type = $static_prop_source->fieldItemList->getFieldDefinition()->getType();
    $cardinality = $static_prop_source->getCardinality();
    $field_value_schema = \sprintf('field.value.%s', $field_type);
    if (!$this->typedConfigManager->hasDefinition($field_value_schema)) {
      return $concrete_schema;
    }
    $prop_field_definition = $this->typedConfigManager->getDefinition($field_value_schema);
    \assert(\array_key_exists('mapping', $prop_field_definition));
    // If the field item only defines a single property, and the component is
    // single cardinality, we expect this to be collapsed to a simple value.
    if ($cardinality === 1 && \count($prop_field_definition['mapping']) === 1) {
      $prop_definition = \reset($prop_field_definition['mapping']);
      \assert(\array_key_exists('type', $prop_definition));
      $concrete_schema['type'] = $prop_definition['type'];
      return $concrete_schema;
    }
    // Multi-cardinality but single property.
    if (($cardinality > 1 || $cardinality === FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED) && \count($prop_field_definition['mapping']) === 1) {
      $concrete_schema['type'] = 'sequence';
      $prop_definition = \reset($prop_field_definition['mapping']);
      \assert(\array_key_exists('type', $prop_definition));
      $concrete_schema['sequence']['type'] = $prop_definition['type'];
      return $concrete_schema;
    }
    // Single-cardinality but multiple properties.
    if ($cardinality === 1 && \count($prop_field_definition['mapping']) > 1) {
      $concrete_schema['type'] = 'mapping';
      $concrete_schema['mapping'] = $prop_field_definition['mapping'];
      return $concrete_schema;
    }
    // Multi-cardinality AND multiple properties.
    $concrete_schema['type'] = 'sequence';
    $concrete_schema['sequence']['type'] = 'mapping';
    $concrete_schema['sequence']['mapping'] = $prop_field_definition['mapping'];
    return $concrete_schema;
  }

  /**
   * {@inheritdoc}
   */
  public function extractTranslatables($schema, $config_data, $base_key = '') {
    if (!$schema instanceof ComponentInputsMapping) {
      return parent::extractTranslatables($schema, $config_data, $base_key);
    }

    // Per `type: canvas.component_tree_node`, some keys must definitely exist,
    // assert the ones that this class needs.
    $parent = $schema->getParent();
    $component_instance = $parent?->getValue();
    \assert(\is_array($component_instance)
      && \array_key_exists('component_id', $component_instance)
      && \array_key_exists('component_version', $component_instance)
      && \array_key_exists('inputs', $component_instance));
    $component_id = $component_instance['component_id'];
    $component_version = $component_instance['component_version'];

    $component = $this->entityTypeManager->getStorage(Component::ENTITY_TYPE_ID)->load($component_id);
    if (!$component instanceof ComponentInterface) {
      return parent::extractTranslatables($schema, $config_data, $base_key);
    }
    $component_source = $component->loadVersion($component_version)->getComponentSource();
    if (!$component_source instanceof JsonSchemaPropsComponentSourceBase) {
      return parent::extractTranslatables($schema, $config_data, $base_key);
    }

    // TRICKY: note that $schema is a ComponentInputsMapping, which this then
    // essentially ignores. This generates a different `type: mapping`
    // definition: one that corresponds to the concrete `field.value.*`s used
    // by the props populated by StaticPropSources in the default translation
    // (and `type: ignore`s for any props populated by any other kind of prop
    // source).
    $concrete_schema = [
      'type' => 'mapping',
    ];
    foreach (\array_keys($component_source->getMetadata()->schema['properties'] ?? []) as $prop_name) {
      $value = $config_data[$prop_name] ?? NULL;
      if (!JsonSchemaPropsComponentInstanceInputsConfigSchemaGenerator::isStaticPropSource($value)) {
        // No need to translate non-static prop sources.
        $concrete_schema['mapping'][$prop_name] = [
          'type' => 'ignore',
          'label' => $component_source->getMetadata()->schema['properties'][$prop_name]['title'] ?? $prop_name,
        ];
        continue;
      }
      $concrete_schema['mapping'][$prop_name] = $this->buildConcreteSchemaFromPropSource($prop_name, $component_source);
    }

    // Ensure optional component inputs not populated in the default translation
    // are still present with a NULL value in $config_data. This is necessary
    // because TypedConfig's ArrayElement::getAllKeys() only returns keys
    // present in the data, silently skipping schema-defined keys with no
    // corresponding data.
    // @see \Drupal\canvas\ConfigTranslation\CanvasComponentTreeItemInputsMappingFormElement::ensureOmittedOptionalInputsAreTranslatable()
    // @see \Drupal\Core\Config\Schema\ArrayElement::getAllKeys()
    foreach ($concrete_schema['mapping'] ?? [] as $prop_name => $prop_schema) {
      if (\array_key_exists($prop_name, $config_data)) {
        continue;
      }
      // Pretend this config property path is populated by NULL, to allow
      // generating a TMGMT translatable.
      $config_data[$prop_name] = NULL;
    }

    $name = $schema->getName();
    if (\is_int($name)) {
      $name = (string) $name;
    }
    $concrete_element = $this->typedConfigManager->create($this->typedConfigManager->buildDataDefinition($concrete_schema, $config_data, $name, $parent), $config_data, $name, $parent);
    \assert($concrete_element instanceof TypedConfigInterface);
    return $this->extractTranslatablesIncludingEmpty($concrete_element, $config_data, $base_key);
  }

  private static function isComponentInstanceInputs(TypedConfigInterface $data): bool {
    // Per the "TRICKY" comment in the calling function, this can never be a
    // ComponentInputsMapping instance.
    \assert(!$data instanceof ComponentInputsMapping);
    return $data->getParent()?->getDataDefinition()->getDataType() === 'canvas.component_tree_node';
  }

  /**
   * Extracts translatables, including unpopulated optional component inputs.
   *
   * Unlike DefaultConfigProcessor::extractTranslatables(), this does NOT skip
   * values where empty($config_data[$key]) is TRUE. This allows optional props
   * that are not populated in the default translation to still be presented to
   * the translator — because a translation may legitimately want to provide a
   * value even when the source language leaves it empty.
   *
   * This ensures optional props that are not populated in the default
   * translation (e.g. English) can still receive a translation (e.g. French).
   *
   * @param \Drupal\Core\Config\Schema\TypedConfigInterface|\Drupal\Core\Config\Schema\Mapping|\Drupal\Core\Config\Schema\Sequence $schema
   *   The schema element.
   * @param array $config_data
   *   The configuration data.
   * @param string $base_key
   *   The base key.
   *
   * @return array
   *   The extracted translatable data structure.
   */
  private function extractTranslatablesIncludingEmpty(TypedConfigInterface|Mapping|Sequence $schema, array $config_data, string $base_key = ''): array {
    $data = [];
    foreach ($schema as $key => $element) {
      // @phpstan-ignore-next-line isset.variable
      $element_key = isset($base_key) ? "$base_key.$key" : $key;
      $definition = $element->getDataDefinition();
      if ($element instanceof Mapping || $element instanceof Sequence) {
        $sub_data = $this->extractTranslatablesIncludingEmpty($element, $config_data[$key] ?? [], $element_key);
        if ($sub_data) {
          $data[$key] = $sub_data;
          $data[$key]['#label'] = $definition->getLabel();
        }
      }
      else {
        // TRICKY: Forcing TMGMT to allow translating an unpopulated optional
        // Canvas component input in the default translation's component
        // instance is a complex undertaking.
        // 1. Falling back to the empty string for an unpopulated component
        //    input does correctly generate a TMGMT translatable.
        // 2. But then the TMGMT UI omits it anyway, due to how TMGMT's
        //    flattening strips empty strings.
        // Only viable solution: generate a TMGMT translatable that is NOT the
        // empty string. This code opts for `∅`, which is the Unicode character
        // for "empty set".
        // @see \Drupal\tmgmt_config\DefaultConfigProcessor::extractTranslatables()
        // @see \Drupal\tmgmt_config\Plugin\tmgmt\Source\ConfigSource::getData()
        // @see \Drupal\tmgmt\Data::flatten()
        // @see \Drupal\tmgmt_config\DefaultConfigProcessor::extractTranslatables()
        // @phpstan-ignore-next-line offsetAccess.nonOffsetAccessible
        if (!isset($definition['translatable']) || !isset($definition['type'])) {
          continue;
        }
        if (empty($config_data[$key])) {
          if (self::isComponentInstanceInputs($schema)) {
            // Customized behavior, to allow translating optional component
            // props not populated in the default translation.
            $config_data[$key] = '∅';
          }
          else {
            // Same behavior as the parent implementation.
            continue;
          }
        }
        $data[$key] = [
          '#label' => $definition['label'],
          '#text' => $config_data[$key],
          '#translate' => TRUE,
        ];
      }
    }
    return $data;
  }

}
