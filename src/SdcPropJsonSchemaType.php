<?php

declare(strict_types=1);

namespace Drupal\experience_builder;

use Drupal\Core\Entity\TypedData\EntityDataDefinition;
use Drupal\datetime\Plugin\Field\FieldType\DateTimeItem;
use Drupal\experience_builder\JsonSchemaInterpreter\JsonSchemaStringFormat;
use Drupal\experience_builder\Plugin\Validation\Constraint\StringSemanticsConstraint;
use Drupal\experience_builder\PropExpressions\StructuredData\FieldPropExpression;
use Drupal\experience_builder\PropExpressions\StructuredData\FieldTypeObjectPropsExpression;
use Drupal\experience_builder\PropExpressions\StructuredData\FieldTypePropExpression;
use Drupal\experience_builder\PropExpressions\StructuredData\ReferenceFieldTypePropExpression;

// phpcs:disable Drupal.Files.LineLength.TooLong

/**
 * KNOWN UNKNOWNS.
 *
 * ⚠️ CONFIDENCE UNDERMINING, HIGHEST IMPACT FIRST ⚠️
 *
 * @todo Question: Does React also use JSON schema for restricting/defining its props? I.e.: identical set of primitives or not?
 * @todo expand test coverage for testing each known type as being REQUIRED too
 * @todo adapters for transforming @FieldType=timestamp -> `type:string,format=time`, @FieldType=datetime -> `type:string,format=time`, a StringSemanticsConstraint::MARKUP string could be adapted to StringSemanticsConstraint::PROSE, UnixTimestampToDateAdapter was a test-only start
 * @todo the `array` type — in particular arrays of tuples/objects, for example an array of "(image uri, alt)" pairs for an image gallery component, see https://stackoverflow.com/questions/40750340/how-to-define-json-schema-for-mapstring-integer
 * @todo `exclusiveMinimum` and `exclusiveMaximum` work differently in JSON schema draft 4 (which SDC uses) than other versions. This is a future BC nightmare.
 * @todo for `string` + `format=duration`, Drupal core has \Drupal\Core\TypedData\Plugin\DataType\DurationIso8601, but nothing uses it!
 * @todo strings with the StringSemanticsConstraint::MARKUP semantic should be usable in slots.
 *
 * KNOWN KNOWNS
 *
 * Upstream changes needed, but high confidence that it is possible:
 * @see \Drupal\experience_builder\Plugin\Field\FieldType\PathItemOverride
 * @see \Drupal\experience_builder\Plugin\Field\FieldType\TextItemOverride
 * @see \Drupal\experience_builder\Plugin\Field\FieldType\UuidItemOverride
 * @todo Disallow JSON schema string formats that do not make sense/are obscure enough — these should be disallowed in \Drupal\sdc\Component\ComponentValidator::validateProps()
 *
 * Will have to fix eventually, but high confidence that it will work:
 * @todo `minLength` and `maxLength` for `string`
 * @todo `multipleOf`, `minimum`, `exclusiveMinimum`, `maximum` and `exclusiveMaximum` support for `integer` and `number`.
 * @todo Question: do we need to support both `format` *and* `pattern` simultaneously; for example to only allow URLs from a certain domain?
 * @todo Question: can we reuse \JsonSchema\Constraints\FormatConstraint to validate just prior to passing information from fields to components, only when developing?
 * @todo Use `justinrainbow/json-schema`'s \JsonSchema\Constraints\FormatConstraint to ensure data flowing from Drupal entity is guaranteed to match with JSON schema constraint; log errors in production, throw errors in dev?
 *
 * @phpstan-type JsonSchema array<string, mixed>
 */
enum SdcPropJsonSchemaType : string {
  case STRING = 'string';
  case NUMBER = 'number';
  case INTEGER = 'integer';
  case OBJECT = 'object';
  case ARRAY = 'array';
  case BOOLEAN = 'boolean';

  public function isScalar(): bool {
    return match ($this) {
      // A subset of the "primitive types" in JSON schema are:
      // - "scalar values" in PHP terminology
      // - "primitives" in Drupal Typed data terminology.
      // @see https://www.php.net/manual/en/function.is-scalar.php
      // @see \Drupal\Core\TypedData\PrimitiveInterface
      self::STRING, self::NUMBER, self::INTEGER, self::BOOLEAN => TRUE,
      // Another subset of the "primitive types" in JSON schema are:
      // - "non-scalar values" in PHP terminology, specifically "iterable"
      // - "traversable" in Drupal Typed Data terminology, specifically "lists"
      //   ("sequences" in config schema) or "complex data" ("mappings" in
      //   config schema)
      // @see https://www.php.net/manual/en/function.is-iterable.php
      // @see \Drupal\Core\TypedData\ListInterface
      // @see \Drupal\Core\TypedData\ComplexDataInterface
      // @see \Drupal\Core\TypedData\TraversableTypedDataInterface
      self::ARRAY, self::OBJECT => FALSE,
    };
  }

  public function isIterable(): bool {
    return !$this->isScalar();
  }

  public function isTraversable(): bool {
    return !$this->isScalar();
  }

  /**
   * Maps the given schema to data type shape requirements.
   *
   * Used for matching against existing field instances, to find candidate
   * dynamic prop source expressions that return a value that fits in this prop
   * shape.
   *
   * @see \Drupal\experience_builder\PropSource\DynamicPropSource
   * @see \Drupal\experience_builder\SdcPropToFieldTypePropMatcher
   *
   * @param JsonSchema $schema
   */
  public function toDataTypeShapeRequirements(array $schema): DataTypeShapeRequirement|DataTypeShapeRequirements|false {
    return match ($this) {
      // There cannot possibly be any additional validation for booleans.
      SdcPropJsonSchemaType::BOOLEAN => FALSE,

      // The `string` JSON schema type
      // - `enum`: https://json-schema.org/understanding-json-schema/reference/enum
      // - `minLength` and `maxLength`: https://json-schema.org/understanding-json-schema/reference/string#length
      // - `pattern`: https://json-schema.org/understanding-json-schema/reference/string#regexp
      // - `format`: https://json-schema.org/understanding-json-schema/reference/string#format and https://json-schema.org/understanding-json-schema/reference/string#built-in-formats
      SdcPropJsonSchemaType::STRING => match (TRUE) {
        array_key_exists('enum', $schema) => new DataTypeShapeRequirement('Choice', [
          'choices' => $schema['enum'],
        ], NULL),
        array_key_exists('pattern', $schema) && array_key_exists('format', $schema) => new DataTypeShapeRequirements([
          JsonSchemaStringFormat::from($schema['format'])->toDataTypeShapeRequirements(),
          new DataTypeShapeRequirement('Regex', ['pattern' => $schema['pattern']]),
        ]),
        array_key_exists('pattern', $schema) => new DataTypeShapeRequirement('Regex', ['pattern' => $schema['pattern']]),
        array_key_exists('format', $schema) => JsonSchemaStringFormat::from($schema['format'])->toDataTypeShapeRequirements(),
        // Otherwise, it's an unrestricted string. Simply surfacing all
        // structured data containing strings would be meaningless though. To
        // ensure a good UX, Drupal interprets this as meaning "prose".
        // @see \Drupal\experience_builder\Plugin\Validation\Constraint\StringSemanticsConstraint::PROSE
        TRUE => new DataTypeShapeRequirement('StringSemantics', ['semantic' => StringSemanticsConstraint::PROSE]),
      },

      // phpcs:disable Drupal.Files.LineLength.TooLong
      // The `integer` and `number` JSON schema types.
      // - `enum`: https://json-schema.org/understanding-json-schema/reference/enum
      // - `multipleOf`: https://json-schema.org/understanding-json-schema/reference/numeric#multiples
      // - `minimum`, `exclusiveMinimum`, `maximum` and `exclusiveMaximum`: https://json-schema.org/understanding-json-schema/reference/numeric#range
      // phpcs:enable
      SdcPropJsonSchemaType::INTEGER, SdcPropJsonSchemaType::NUMBER => match (TRUE) {
        array_key_exists('enum', $schema) => new DataTypeShapeRequirement('Choice', [
          'choices' => $schema['enum'],
        ], NULL),
        // Both min & max.
        array_key_exists('minimum', $schema) && array_key_exists('maximum', $schema) => new DataTypeShapeRequirement('Range', [
          'min' => $schema['minimum'],
          'max' => $schema['maximum'],
        ], NULL),
        // Either min or max.
        array_key_exists('minimum', $schema) => new DataTypeShapeRequirement('Range', ['min' => $schema['minimum']], NULL),
        array_key_exists('maximum', $schema) => new DataTypeShapeRequirement('Range', ['min' => $schema['minimum']], NULL),
        !empty(array_intersect(['multipleOf', 'maximum', 'exclusiveMinimum', 'exclusiveMaximum'], array_keys($schema))) => new DataTypeShapeRequirement('NOT YET SUPPORTED', []),
        // Otherwise, it's an unrestricted integer or number.
        TRUE => FALSE,
      },

      SdcPropJsonSchemaType::OBJECT, SdcPropJsonSchemaType::ARRAY => (function () {
        throw new \LogicException('@see ::findFieldTypeProps() and ::recurseJsonSchema()');
      })(),
    };
  }

  /**
   * Maps the given schema to a StorablePropShape, if possible.
   *
   * Used for generating a StaticPropSource, for storing a value that fits in
   * this prop shape.
   *
   * @see \Drupal\experience_builder\PropSource\StaticPropSource
   */

  /**
   * Finds the recommended UX (storage + widget) for a prop shape.
   *
   * Used for generating a StaticPropSource, for storing a value that fits in
   * this prop shape.
   *
   * @param \Drupal\experience_builder\PropShape $shape
   *   The prop shape to find the recommended UX (storage + widget) for.
   *
   * @return \Drupal\experience_builder\StorablePropShape|null
   *   NULL is returned to indicate that Experience Builder + Drupal core do not
   *   support a field type that provides a good UX for entering a value of this
   *   shape. Otherwise, a StorablePropShape is returned that specifies that UX.
   *
   * @see \Drupal\experience_builder\PropSource\StaticPropSource
   */
  public function findFieldTypeStorage(PropShape $shape): ?StorablePropShape {
    $schema = $shape->schema;
    return match ($this) {
      // @see \Drupal\Core\Field\Plugin\Field\FieldType\BooleanItem
      SdcPropJsonSchemaType::BOOLEAN => new StorablePropShape(shape: $shape, fieldTypeProp: new FieldTypePropExpression('boolean', 'value'), fieldWidget: 'boolean_checkbox'),

      // The `string` JSON schema type
      // - `enum`: https://json-schema.org/understanding-json-schema/reference/enum
      // - `minLength` and `maxLength`: https://json-schema.org/understanding-json-schema/reference/string#length
      // - `pattern`: https://json-schema.org/understanding-json-schema/reference/string#regexp
      // - `format`: https://json-schema.org/understanding-json-schema/reference/string#format and https://json-schema.org/understanding-json-schema/reference/string#built-in-formats
      SdcPropJsonSchemaType::STRING => match (TRUE) {
        array_key_exists('$ref', $schema) => NULL,
        array_key_exists('enum', $schema) => new StorablePropShape(shape: $shape, fieldWidget: 'options_select', fieldTypeProp: new FieldTypePropExpression('list_string', 'value'), fieldStorageSettings: [
          'allowed_values' => array_combine($schema['enum'], $schema['enum']),
        ]),
        // @todo subclass \Drupal\Core\Field\Plugin\Field\FieldType\StringItem to allow for a "pattern" setting + create subclass of \Drupal\Core\Field\Plugin\Field\FieldWidget\StringTextfieldWidget to pass on that pattern setting  ⚠️
        array_key_exists('pattern', $schema) => NULL,
        array_key_exists('format', $schema) => JsonSchemaStringFormat::from($schema['format'])->findFieldTypeStorage($shape),
        // @see \Drupal\Core\Field\Plugin\Field\FieldType\StringItem
        // @todo Support `minLength`.  ⚠️
        array_key_exists('maxLength', $schema) => new StorablePropShape(shape: $shape, fieldTypeProp: new FieldTypePropExpression('string', 'value'), fieldWidget: 'string_textfield', fieldStorageSettings: [
          'max_length' => $schema['maxLength'],
        ]),
        TRUE => new StorablePropShape(shape: $shape, fieldTypeProp: new FieldTypePropExpression('string', 'value'), fieldWidget: 'string_textfield'),
      },

      // The `integer` JSON schema types.
      // - `enum`: https://json-schema.org/understanding-json-schema/reference/enum
      // - `multipleOf`: https://json-schema.org/understanding-json-schema/reference/numeric#multiples
      // - `minimum`, `exclusiveMinimum`, `maximum` and `exclusiveMaximum`: https://json-schema.org/understanding-json-schema/reference/numeric#range
      SdcPropJsonSchemaType::INTEGER => match (TRUE) {
        array_key_exists('$ref', $schema) => NULL,
        array_key_exists('enum', $schema) => new StorablePropShape(shape: $shape, fieldWidget: 'options_select', fieldTypeProp: new FieldTypePropExpression('list_integer', 'value'), fieldStorageSettings: [
          'allowed_values' => array_combine($schema['enum'], $schema['enum']),
        ]),
        // `min` and/or `max`
        array_key_exists('minimum', $schema) || array_key_exists('maximum', $schema) => new StorablePropShape(shape: $shape, fieldTypeProp: new FieldTypePropExpression('integer', 'value'), fieldWidget: 'number', fieldStorageSettings: [
          'min' => $schema['minimum'] ?? (array_key_exists('exclusiveMinimum', $schema) ? $schema['exclusiveMinimum'] + 1 : ''),
          'max' => $schema['maximum'] ?? (array_key_exists('exclusiveMaximum', $schema) ? $schema['exclusiveMaximum'] - 1 : ''),
        ]),
        // Otherwise, it's an unrestricted integer.
        // @todo `multipleOf` ⚠️
        TRUE => new StorablePropShape(shape: $shape, fieldTypeProp: new FieldTypePropExpression('integer', 'value'), fieldWidget: 'number'),
      },

      // The `number` JSON schema types.
      // - `enum`: https://json-schema.org/understanding-json-schema/reference/enum
      // - `multipleOf`: https://json-schema.org/understanding-json-schema/reference/numeric#multiples
      // - `minimum`, `exclusiveMinimum`, `maximum` and `exclusiveMaximum`: https://json-schema.org/understanding-json-schema/reference/numeric#range
      SdcPropJsonSchemaType::NUMBER => match (TRUE) {
        array_key_exists('$ref', $schema) => NULL,
        array_key_exists('enum', $schema) => new StorablePropShape(shape: $shape, fieldWidget: 'options_select', fieldTypeProp: new FieldTypePropExpression('list_float', 'value'), fieldStorageSettings: [
          'allowed_values' => array_combine($schema['enum'], $schema['enum']),
        ]),
        // `min` and/or `max`
        array_key_exists('minimum', $schema) || array_key_exists('maximum', $schema) => new StorablePropShape(shape: $shape, fieldTypeProp: new FieldTypePropExpression('float', 'value'), fieldWidget: 'number', fieldStorageSettings: [
          'min' => $schema['minimum'] ?? (array_key_exists('exclusiveMinimum', $schema) ? $schema['exclusiveMinimum'] + 0.000001 : ''),
          'max' => $schema['maximum'] ?? (array_key_exists('exclusiveMaximum', $schema) ? $schema['exclusiveMaximum'] - 0.000001 : ''),
        ]),
        // Otherwise, it's an unrestricted integer.
        // @todo `multipleOf` ⚠️
        TRUE => new StorablePropShape(shape: $shape, fieldTypeProp: new FieldTypePropExpression('float', 'value'), fieldWidget: 'number'),
      },

      SdcPropJsonSchemaType::OBJECT => match (TRUE) {
        array_key_exists('$ref', $schema) => match ($schema['$ref']) {
          // @todo make configurable in https://www.drupal.org/project/experience_builder/issues/3464031
          // @see \Drupal\image\Plugin\Field\FieldType\ImageItem
          'json-schema-definitions://experience_builder.module/image' => new StorablePropShape(shape: $shape, fieldWidget: 'image_image', fieldTypeProp: new FieldTypeObjectPropsExpression('image', [
            'src' => new ReferenceFieldTypePropExpression(
              new FieldTypePropExpression('image', 'entity'),
              new FieldPropExpression(EntityDataDefinition::create('file'), 'uri', NULL, 'value'),
            ),
            'alt' => new FieldTypePropExpression('image', 'alt'),
            'width' => new FieldTypePropExpression('image', 'width'),
            'height' => new FieldTypePropExpression('image', 'height'),
          ])),
          default => NULL,
        },
        default => NULL,
      },

      // @todo Support this! ⚠️
      SdcPropJsonSchemaType::ARRAY => NULL,
    };
  }

}
