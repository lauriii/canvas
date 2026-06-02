<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Canvas\ComponentSource;

use Drupal\canvas\PropExpressions\Component\ComponentPropExpression;
use Drupal\canvas\PropShape\PropShapeRepositoryInterface;
use Drupal\canvas\PropShape\StorablePropShape;
use Drupal\Core\Plugin\Component as ComponentPlugin;
use Drupal\Core\Theme\Component\ComponentMetadata;

/**
 * Provides the logic for computing prop field definitions from SDC metadata.
 *
 * Used by JsonSchemaPropsComponentSourceBase subclasses' discovery classes.
 *
 * @internal
 */
abstract class JsonSchemaPropsComponentDiscoveryBase {

  public function __construct(
    private readonly PropShapeRepositoryInterface $propShapeRepository,
  ) {}

  /**
   * Computes the prop field definitions for an SDC component plugin.
   *
   * @param \Drupal\Core\Plugin\Component $component_plugin
   *   The component plugin.
   *
   * @return array<string, array<string, mixed>>
   *   The prop field definitions, keyed by prop name.
   */
  protected function getPropsForComponentPlugin(ComponentPlugin $component_plugin): array {
    $props = [];
    foreach (JsonSchemaPropsComponentSourceBase::getComponentInputsForMetadata($component_plugin->getPluginId(), $component_plugin->metadata) as $cpe_string => $prop_shape) {
      $cpe = ComponentPropExpression::fromString($cpe_string);

      $storable_prop_shape = $this->propShapeRepository->getStorablePropShape($prop_shape);
      if (\is_null($storable_prop_shape)) {
        continue;
      }

      $schema = $component_plugin->metadata->schema ?? [];
      $props[$cpe->propName] = [
        'required' => isset($schema['required']) && \in_array($cpe->propName, $schema['required'], TRUE),
        'field_type' => $storable_prop_shape->fieldTypeProp->getFieldType(),
        'field_widget' => $storable_prop_shape->fieldWidget,
        'expression' => (string) $storable_prop_shape->fieldTypeProp,
        'default_value' => self::computeDefaultFieldValue($storable_prop_shape, $component_plugin->metadata, $cpe->propName),
        'field_storage_settings' => $storable_prop_shape->fieldStorageSettings ?? [],
        'field_instance_settings' => $storable_prop_shape->fieldInstanceSettings ?? [],
      ];
      if ($storable_prop_shape->cardinality !== NULL) {
        $props[$cpe->propName]['cardinality'] = $storable_prop_shape->cardinality;
      }
    }

    return $props;
  }

  /**
   * Computes the default field value for a storable prop shape.
   */
  private static function computeDefaultFieldValue(StorablePropShape $storable_prop_shape, ComponentMetadata $sdc_metadata, string $sdc_prop_name): mixed {
    // Special case.
    // TRICKY: Do not store a default value for field types that reference
    // entities, because that would require those entities to be created.
    // @see ::getClientSideInfo()
    if (JsonSchemaPropsComponentSourceBase::exampleValueRequiresEntity($storable_prop_shape)) {
      return [];
    }

    \assert(\is_array($sdc_metadata->schema));
    // @see https://json-schema.org/understanding-json-schema/reference/object#required
    // @see https://json-schema.org/learn/getting-started-step-by-step#required
    $is_required = \in_array($sdc_prop_name, $sdc_metadata->schema['required'] ?? [], TRUE);

    // @see `type: canvas.component.*`
    \assert(\array_key_exists('properties', $sdc_metadata->schema));

    // TRICKY: need to transform to the array structure that depends on the
    // field type.
    // @see `type: field.storage_settings.*`
    $static_prop_source = $storable_prop_shape->toStaticPropSource();
    $example_assigned_to_field_item_list = $static_prop_source->withValue(
      $is_required
        // Example guaranteed to exist if a required prop.
        ? $sdc_metadata->schema['properties'][$sdc_prop_name]['examples'][0]
        // Example may exist if an optional prop.
        : (
          \array_key_exists('examples', $sdc_metadata->schema['properties'][$sdc_prop_name]) && \array_key_exists(0, $sdc_metadata->schema['properties'][$sdc_prop_name]['examples'])
            ? $sdc_metadata->schema['properties'][$sdc_prop_name]['examples'][0]
            : NULL
        )
    )->fieldItemList;

    return !$example_assigned_to_field_item_list->isEmpty()
      // The actual value in the field if there is one.
      ? $example_assigned_to_field_item_list->getValue()
      // If empty: do not store anything in the Component config entity.
      : NULL;
  }

}
