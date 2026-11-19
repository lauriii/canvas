<?php

declare(strict_types=1);

namespace Drupal\canvas\Config\Schema;

use Drupal\Core\Config\Schema\Mapping;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\MapDataDefinition;
use Drupal\Core\TypedData\TypedDataInterface;

/**
 * Generates the config schema for a `canvas_object` field item value.
 *
 * Its keys are the object properties in the sibling `field_storage_settings`
 * of a Component config entity's `prop_field_definitions`.
 *
 * @see \Drupal\canvas\Plugin\Field\FieldType\CanvasObjectItem
 * @see `type: field.value.canvas_object`
 *
 * @internal
 */
final class CanvasObjectFieldValue extends Mapping {

  /**
   * {@inheritdoc}
   */
  public function __construct(DataDefinitionInterface $definition, $name = NULL, ?TypedDataInterface $parent = NULL) {
    \assert($definition instanceof MapDataDefinition);
    // Parent chain: field item value -> `default_value` -> prop field
    // definition.
    $properties = $parent?->getParent()?->getValue()['field_storage_settings']['properties'] ?? [];
    foreach ($properties as $property_name => $property_schema) {
      $definition['mapping'][$property_name] = [
        'type' => $property_schema['type'] === 'number' ? 'float' : $property_schema['type'],
        'requiredKey' => FALSE,
        'nullable' => TRUE,
      ];
    }
    parent::__construct($definition, $name, $parent);
  }

}
