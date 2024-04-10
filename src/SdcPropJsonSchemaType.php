<?php

declare(strict_types=1);

namespace Drupal\experience_builder;

// @todo Question for Ben/Jesse/Harumi: does react also use JSON schema for restricting/defining its props? I.e.: identical set of primitives or not?
// @todo Use `justinrainbow/json-schema`'s \JsonSchema\Constraints\FormatConstraint to ensure data flowing from Drupal entity is guaranteed to match with JSON schema constraint; log errors in production, throw errors in dev?
use Drupal\Core\TypedData\Type\UriInterface;
use Drupal\experience_builder\JsonSchemaInterpreter\JsonSchemaStringFormat;

enum SdcPropJsonSchemaType : string {
  case STRING = 'string';
  case NUMBER = 'number';
  case INTEGER = 'integer';
  case OBJECT = 'object';
  case ARRAY = 'array';
  case BOOLEAN = 'boolean';

  public function toDataTypeShapeRequirements(array $schema): DataTypeShapeRequirements|false {
    // Generic restrictions: https://json-schema.org/understanding-json-schema/reference/enum
    // TRICKY: generic restrictions also allow mixing different types, this is not supported on the Drupal side.
    return match ($this) {
      // There cannot possibly be any additional validation for booleans.
      SdcPropJsonSchemaType::BOOLEAN => FALSE,

      // The `string` JSON schema type
      // - `minLength` and `maxLength`: https://json-schema.org/understanding-json-schema/reference/string#length
      // - `pattern`: https://json-schema.org/understanding-json-schema/reference/string#regexp
      // - `format`: https://json-schema.org/understanding-json-schema/reference/string#format and https://json-schema.org/understanding-json-schema/reference/string#built-in-formats
      // For example, `format: uri` in JSON schema maps 1:1 to
      // \Drupal\Core\TypedData\Type\UriInterface, because both use RFC3986.
      // @see https://json-schema.org/understanding-json-schema/reference/string#resource-identifiers
      // @see \Drupal\Core\Validation\Plugin\Validation\Constraint\PrimitiveTypeConstraintValidator
      SdcPropJsonSchemaType::STRING => match (TRUE) {
        array_key_exists('enum', $schema) => new DataTypeShapeRequirements('Choice', [
          'choices' => $schema['enum'],
        ], NULL),
        // @todo `minLength` and `maxLength`
        // @todo Do we need to support both `format` *and* `pattern` simultaneously; for example to only allow URLs from a certain domain?
        array_key_exists('pattern', $schema) => new DataTypeShapeRequirements('Regex', ['pattern' => $schema['pattern']]),
        array_key_exists('format', $schema) => JsonSchemaStringFormat::tryFrom($schema['format'])->toDataTypeShapeRequirements(),
        // Otherwise, it's an unrestricted string.
        TRUE => FALSE,
      },

      SdcPropJsonSchemaType::INTEGER, SdcPropJsonSchemaType::NUMBER => match (TRUE) {
        array_key_exists('enum', $schema) => new DataTypeShapeRequirements('Choice', [
          'choices' => $schema['enum'],
        ], NULL),

        // @todo 'minimum', 'maximum', etc.
        array_key_exists('minimum', $schema) => new DataTypeShapeRequirements('Range', ['min' => $schema['minimum']], NULL),
        // @todo https://json-schema.org/understanding-json-schema/reference/numeric#multiples
        // @todo https://json-schema.org/understanding-json-schema/reference/numeric#range
        // Otherwise, it's an unrestricted integer.
        TRUE => FALSE,
      },
      // @todo
      SdcPropJsonSchemaType::OBJECT, SdcPropJsonSchemaType::ARRAY => new DataTypeShapeRequirements('NOT YET SUPPORTED', [], NULL),
    };
  }
}
