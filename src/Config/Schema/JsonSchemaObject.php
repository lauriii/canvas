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
 * Generates config schema definition for a `type: object` prop example.
 *
 * Handles both:
 * - well-known object shapes (`type: object, $ref: …`), whose schema is loaded
 *   from the extension-provided JSON schema definition
 * - custom object shapes (`type: object, properties: …` — "groups"), whose
 *   schema is inline in the prop definition itself; their sub-properties may
 *   themselves be well-known `$ref` shapes or arrays
 *
 * @see docs/adr/0021-object-props-in-code-components.md
 * @internal
 */
final class JsonSchemaObject extends Mapping {

  private const SUPPORTED_SCALAR_TYPES = [
    'boolean',
    'integer',
    'number',
    'string',
  ];

  /**
   * {@inheritdoc}
   */
  public function __construct(DataDefinitionInterface $definition, $name = NULL, ?TypedDataInterface $parent = NULL) {
    \assert($definition instanceof MapDataDefinition);
    $prop_definition = $this->findContainingPropDefinition($parent);
    $ref = $prop_definition['$ref'] ?? NULL;
    if (\is_string($ref)) {
      $schema = self::loadRefSchema($ref);
      if ($schema['type'] !== 'object') {
        throw new \LogicException(\sprintf("The schema definition at `%s` is invalid: the parent '\$ref' property should resolve to an object definition.", $parent?->getPropertyPath() ?? $name));
      }
      // Well-known object shapes only support scalar sub-properties.
      $allow_object_sub_properties = FALSE;
    }
    elseif (\is_array($prop_definition['properties'] ?? NULL)) {
      // A custom object shape ("group"): the inline `properties` are the
      // schema. Sub-properties may use well-known `$ref` shapes.
      $schema = [
        'type' => 'object',
        'properties' => $prop_definition['properties'],
        'required' => $prop_definition['required'] ?? [],
      ];
      $allow_object_sub_properties = TRUE;
    }
    else {
      // Neither `$ref` nor `properties`: this will be caught by the
      // ValidCanvasObjectPropShape constraint on the prop shape.
      parent::__construct($definition, $name, $parent);
      return;
    }
    foreach ($schema['properties'] as $property_name => $detail) {
      $definition['mapping'][$property_name] = $this->buildPropertyDefinition($detail, $property_name, $allow_object_sub_properties, $parent, $name);
      if (!\in_array($property_name, $schema['required'] ?? [], TRUE)) {
        $definition['mapping'][$property_name]['requiredKey'] = FALSE;
      }
    }
    parent::__construct($definition, $name, $parent);
  }

  /**
   * Loads and decodes an extension-provided JSON schema definition.
   *
   * @return array<string, mixed>
   */
  private static function loadRefSchema(string $ref): array {
    $file_contents = \file_get_contents($ref);
    return \json_decode($file_contents !== FALSE ? $file_contents : '{}', TRUE, flags: \JSON_THROW_ON_ERROR);
  }

  /**
   * Builds the config schema definition for one object sub-property.
   *
   * @param array<string, mixed> $detail
   *   The sub-property's JSON schema (may still contain a `$ref`).
   * @param string $property_name
   *   The sub-property name (for error messages).
   * @param bool $allow_object_sub_properties
   *   Whether object-shaped sub-properties (well-known `$ref` shapes inside a
   *   custom object shape) are supported. Only 1 level of nesting is allowed.
   * @param \Drupal\Core\TypedData\TypedDataInterface|null $parent
   *   The parent typed data object (for error messages).
   * @param string|int|null $name
   *   The property name of this typed data object (for error messages).
   *
   * @return array<string, mixed>
   */
  private function buildPropertyDefinition(array $detail, string $property_name, bool $allow_object_sub_properties, ?TypedDataInterface $parent, string|int|null $name): array {
    if (\array_key_exists('$ref', $detail)) {
      $prop_schema = self::loadRefSchema($detail['$ref']);
      if (!\in_array($prop_schema['type'] ?? NULL, self::SUPPORTED_SCALAR_TYPES, TRUE) && !($allow_object_sub_properties && ($prop_schema['type'] ?? NULL) === 'object')) {
        throw new \LogicException(\sprintf("The schema definition at `%s` is invalid: the parent '\$ref' property contains a '%s' property that uses an unsupported config schema type '%s'. This is not supported.", $parent?->getPropertyPath() ?? $name, $property_name, $prop_schema['type'] ?? 'unknown'));
      }
      // Resolve the $ref.
      $detail += $prop_schema;
    }

    // Object-shaped sub-properties of a custom object shape: generate a nested
    // mapping from the resolved well-known shape. (Custom object shapes cannot
    // nest: enforced by the ValidCanvasObjectPropShape constraint.)
    if ($detail['type'] === 'object' && $allow_object_sub_properties) {
      $property_definition = [
        'type' => 'mapping',
        'label' => $detail['title'] ?? '',
        'mapping' => [],
      ];
      foreach ($detail['properties'] ?? [] as $sub_property_name => $sub_detail) {
        $property_definition['mapping'][$sub_property_name] = $this->buildPropertyDefinition($sub_detail, $sub_property_name, FALSE, $parent, $name);
        if (!\in_array($sub_property_name, $detail['required'] ?? [], TRUE)) {
          $property_definition['mapping'][$sub_property_name]['requiredKey'] = FALSE;
        }
      }
      return $property_definition;
    }

    // Array-shaped sub-properties of a custom object shape ("allow multiple
    // values" on a sub-property).
    if ($detail['type'] === 'array' && $allow_object_sub_properties && \is_array($detail['items'] ?? NULL)) {
      // The item definition is the direct value of `sequence`.
      // @see \Drupal\Core\Config\Schema\Sequence::getElementDefinition()
      return [
        'type' => 'sequence',
        'label' => $detail['title'] ?? '',
        'sequence' => $this->buildPropertyDefinition($detail['items'], $property_name, $allow_object_sub_properties, $parent, $name),
      ];
    }

    if (!\in_array($detail['type'], self::SUPPORTED_SCALAR_TYPES, TRUE)) {
      throw new \LogicException(\sprintf("The schema definition at `%s` is invalid: the parent '\$ref' property contains a '%s' property that uses an unsupported config schema type '%s'. This is not supported.", $parent?->getPropertyPath() ?? $name, $property_name, $detail['type']));
    }

    $property_definition = [
      // Config schema uses `float`, JSON Schema uses `number`.
      'type' => $detail['type'] === 'number' ? 'float' : $detail['type'],
      'label' => $detail['title'] ?? '',
    ];
    if (\array_key_exists('pattern', $detail)) {
      $property_definition['constraints']['Regex'] = [
        'pattern' => \sprintf('@%s@', $detail['pattern']),
        'message' => '%value does not match the pattern %pattern.',
      ];
    }
    if ($detail['type'] === 'string' && \array_key_exists('format', $detail)) {
      // @see \Drupal\canvas\JsonSchemaInterpreter\JsonSchemaStringFormat::toDataTypeShapeRequirements()
      $format = JsonSchemaStringFormat::tryFrom($detail['format']);
      if ($format?->isUriEsque()) {
        $property_definition['constraints'][UriConstraint::PLUGIN_ID] = [
          'allowReferences' => $format->allowsBothAbsoluteOrRelativeUri(),
        ];
        if (\array_key_exists('x-allowed-schemes', $detail)) {
          $property_definition['constraints'][UriSchemeConstraint::PLUGIN_ID] = [
            'allowedSchemes' => $detail['x-allowed-schemes'],
          ];
        }
      }
    }
    if (\array_key_exists('enum', $detail)) {
      $property_definition['constraints']['Choice'] = ['choices' => $detail['enum']];
    }
    return $property_definition;
  }

  /**
   * Finds the containing prop definition (or item shape) from parent context.
   *
   * Handles two cases:
   * 1. Regular object prop: the prop definition is at parent->parent.
   * 2. Array example item: the shape is at `items` in the prop definition.
   *
   * @param \Drupal\Core\TypedData\TypedDataInterface|null $parent
   *   The parent typed data object.
   *
   * @return array<string, mixed>|null
   *   The prop definition (carrying either `$ref` or `properties`), or NULL if
   *   not found.
   */
  private static function findContainingPropDefinition(?TypedDataInterface $parent): ?array {
    // Case 1: Regular object prop example - the prop definition is the parent
    // of the `examples` sequence.
    // Structure: props.some_prop.$ref, props.some_prop.examples.0
    // Parent chain: example item -> examples sequence -> prop definition
    $prop_definition = $parent?->getParent()?->getValue();
    if (\is_array($prop_definition) && (\array_key_exists('$ref', $prop_definition) || \array_key_exists('properties', $prop_definition))) {
      return $prop_definition;
    }

    // Case 2: Array example item - the shape is in `items`.
    // Structure: props.array_prop.items.$ref, props.array_prop.examples.0.0
    // Parent chain: item -> inner sequence (examples.0) -> outer sequence
    // (examples) -> prop definition.
    $prop_definition = $parent?->getParent()?->getParent()?->getValue();
    if (\is_array($prop_definition) && ($prop_definition['type'] ?? NULL) === 'array' && \is_array($prop_definition['items'] ?? NULL)) {
      $item_shape = $prop_definition['items'];
      if (\array_key_exists('$ref', $item_shape) || \array_key_exists('properties', $item_shape)) {
        return $item_shape;
      }
    }

    return NULL;
  }

}
