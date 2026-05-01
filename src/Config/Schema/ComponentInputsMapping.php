<?php

declare(strict_types=1);

namespace Drupal\canvas\Config\Schema;

use Drupal\canvas\Plugin\DataType\ComponentInputs;
use Drupal\Core\Config\Schema\Mapping;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\MapDataDefinition;
use Drupal\Core\TypedData\TypedDataInterface;

/**
 * Generates a mapping definition for a component instance's inputs.
 *
 * Delegates to ComponentInputs::resolveConfigSchemaMapping() for the
 * actual resolution logic: this ensures a single source of truth for per-key
 * config schema metadata for both config translation (this class) and content
 * translation (ComponentInputs::getTranslatableInputKeys()).
 *
 * @internal
 *
 * @see \Drupal\canvas\Plugin\DataType\ComponentInputs::resolveConfigSchemaMapping()
 */
final class ComponentInputsMapping extends Mapping {

  public function __construct(DataDefinitionInterface $definition, $name = NULL, ?TypedDataInterface $parent = NULL) {
    \assert($definition instanceof MapDataDefinition);

    if ($parent === NULL) {
      throw new \LogicException('$parent cannot be NULL; this can only be used for the `inputs` key in `type: canvas.component_tree_node`.');
    }
    // `field.value.component_tree` is a subtype of the
    // `canvas.component_tree_node` config schema type.
    if (!\in_array($parent->getDataDefinition()->getDataType(), [
      'canvas.component_tree_node',
      'field.value.component_tree',
    ], TRUE)) {
      throw new \LogicException(\sprintf('$parent must be of type `canvas.component_tree_node`, `%s` given.', $parent->getDataDefinition()->getDataType()));
    }

    // Per `type: canvas.component_tree_node`, some keys must definitely exist,
    // assert the ones that this class needs.
    $component_instance = $parent->getValue();
    \assert(\array_key_exists('component_id', $component_instance));
    \assert(\array_key_exists('component_version', $component_instance));
    \assert(\array_key_exists('inputs', $component_instance));
    $component_id = $component_instance['component_id'];
    $component_version = $component_instance['component_version'];
    $actual_inputs = $component_instance['inputs'];

    // Delegate to ComponentInputs for the actual resolution logic.
    // @see \Drupal\canvas\Plugin\DataType\ComponentInputs::resolveConfigSchemaMapping()
    $definition['mapping'] = ComponentInputs::resolveConfigSchemaMapping($component_id, $component_version, $actual_inputs ?? []);

    parent::__construct($definition, $name, $parent);
  }

}
