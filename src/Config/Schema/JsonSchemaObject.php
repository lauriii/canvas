<?php

declare(strict_types=1);

namespace Drupal\canvas\Config\Schema;

use Drupal\canvas\JsonSchemaInterpreter\JsonSchemaStringFormat;
use Drupal\canvas\Plugin\Validation\Constraint\UriConstraint;
use Drupal\canvas\Plugin\Validation\Constraint\UriSchemeConstraint;
use Drupal\Core\Config\Schema\Mapping;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\MapDataDefinition;
use Drupal\Core\TypedData\TypedDataInterface;

/**
 * Generates config schema definition for `type: object, $ref: …` prop example.
 *
 * @internal
 */
final class JsonSchemaObject extends Mapping {

  /**
   * {@inheritdoc}
   */
  public function __construct(DataDefinitionInterface $definition, $name = NULL, ?TypedDataInterface $parent = NULL) {
    \assert($definition instanceof MapDataDefinition);
    $ref = $this->findContainingSingleCardinalityProperty($parent);
    if ($ref === NULL) {
      // This will be caught by the parent constraint that requires a $ref key.
      parent::__construct($definition, $name, $parent);
      return;
    }
    $schema = \json_decode(\file_get_contents($ref) ?: '{}', TRUE, \JSON_THROW_ON_ERROR);
    if ($schema['type'] !== 'object') {
      throw new \LogicException(\sprintf("The schema definition at `%s` is invalid: the parent '\$ref' property should resolve to an object definition.", $parent?->getPropertyPath() ?? $name));
    }
    $supported_property_types = [
      'boolean',
      'integer',
      'number',
      'string',
    ];
    foreach ($schema['properties'] as $property_name => $detail) {
      if (\array_key_exists('$ref', $detail)) {
        $prop_schema = \json_decode(\file_get_contents($detail['$ref']) ?: '{}', TRUE, \JSON_THROW_ON_ERROR);
        if (!\in_array($prop_schema['type'] ?? NULL, $supported_property_types, TRUE)) {
          throw new \LogicException(\sprintf("The schema definition at `%s` is invalid: the parent '\$ref' property contains a '%s' property that uses an unsupported config schema type '%s'. This is not supported.", $parent?->getPropertyPath() ?? $name, $property_name, $prop_schema['type'] ?? 'unknown'));
        }
        // Resolve the $ref.
        $detail += $prop_schema;
      }
      if (!\in_array($detail['type'], $supported_property_types, TRUE)) {
        throw new \LogicException(\sprintf("The schema definition at `%s` is invalid: the parent '\$ref' property contains a '%s' property that uses an unsupported config schema type '%s'. This is not supported.", $parent?->getPropertyPath() ?? $name, $property_name, $detail['type']));
      }
      $definition['mapping'][$property_name] = [
        'type' => $detail['type'] ?? 'unknown',
        'label' => $detail['title'] ?? '',
      ];
      if (!\in_array($property_name, $schema['required'] ?? [], TRUE)) {
        $definition['mapping'][$property_name]['requiredKey'] = FALSE;
      }
      if (\array_key_exists('pattern', $detail)) {
        $definition['mapping'][$property_name]['constraints']['Regex'] = [
          'pattern' => \sprintf('@%s@', $detail['pattern']),
          'message' => '%value does not match the pattern %pattern.',
        ];
      }
      if ($detail['type'] === 'string' && \array_key_exists('format', $detail)) {
        // @see \Drupal\canvas\JsonSchemaInterpreter\JsonSchemaStringFormat::toDataTypeShapeRequirements()
        if (\in_array($detail['format'], [JsonSchemaStringFormat::Iri->value, JsonSchemaStringFormat::IriReference->value, JsonSchemaStringFormat::Uri->value, JsonSchemaStringFormat::UriReference->value], TRUE)) {
          $definition['mapping'][$property_name]['constraints'][UriConstraint::PLUGIN_ID] = [
            'allowReferences' => \in_array($detail['format'], [JsonSchemaStringFormat::IriReference->value, JsonSchemaStringFormat::UriReference->value], TRUE),
          ];
          if (\array_key_exists('x-allowed-schemes', $detail)) {
            $definition['mapping'][$property_name]['constraints'][UriSchemeConstraint::PLUGIN_ID] = [
              'allowedSchemes' => $detail['x-allowed-schemes'],
            ];
          }
        }
      }
      if (\array_key_exists('enum', $detail)) {
        $definition['mapping'][$property_name]['constraints']['Choice'] = ['choices' => $detail['enum']];
      }
    }
    parent::__construct($definition, $name, $parent);
  }

  /**
   * Finds the $ref value from the parent context.
   *
   * Handles two cases:
   * 1. Regular object prop: $ref is at parent->parent (the prop definition)
   * 2. Array example item: $ref is at items.$ref in the prop definition
   *
   * @param \Drupal\Core\TypedData\TypedDataInterface|null $parent
   *   The parent typed data object.
   *
   * @return string|null
   *   The $ref URI, or NULL if not found.
   */
  private function findContainingSingleCardinalityProperty(?TypedDataInterface $parent): ?string {
    // Case 1: Regular object prop example - $ref is sibling of examples.
    // Structure: props.some_prop.$ref, props.some_prop.examples.0
    // Parent chain: example item -> examples sequence -> prop definition
    $ref = $parent?->getParent()?->getValue()['$ref'] ?? NULL;
    if ($ref !== NULL) {
      return $ref;
    }

    // Case 2: Array example item - $ref is in items.
    // Structure: props.array_prop.items.$ref, props.array_prop.examples.0.0
    // Parent chain: item -> inner sequence (examples.0) -> outer sequence
    // (examples) -> prop definition.
    $propDefinition = $parent->getParent()?->getParent()?->getValue();
    if (\is_array($propDefinition) && ($propDefinition['type'] ?? NULL) === 'array') {
      return $propDefinition['items']['$ref'] ?? NULL;
    }

    return NULL;
  }

}
