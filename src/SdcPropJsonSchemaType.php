<?php

declare(strict_types=1);

namespace Drupal\experience_builder;

use Drupal\experience_builder\JsonSchemaInterpreter\JsonSchemaStringFormat;
use Drupal\experience_builder\Plugin\Validation\Constraint\StringSemanticsConstraint;

// phpcs:disable Drupal.Files.LineLength.TooLong

/**
 * KNOWN UNKNOWNS.
 *
 * ⚠️ CONFIDENCE UNDERMINING, HIGHEST IMPACT FIRST ⚠️
 *
 * @todo Question: Does React also use JSON schema for restricting/defining its props? I.e.: identical set of primitives or not?
 * @todo expand test coverage for testing each known type as being REQUIRED too
 * @todo enums are widely used — auto-generating e.g. FieldConfig using @FieldType=list_string + settings would solve the 90% use case
 * @todo adapters for transforming @FieldType=timestamp -> `type:string,format=time`, @FieldType=datetime -> `type:string,format=time`, a StringSemanticsConstraint::MARKUP string could be adapted to StringSemanticsConstraint::PROSE
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

}
