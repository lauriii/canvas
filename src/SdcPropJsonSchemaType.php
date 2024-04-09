<?php

declare(strict_types=1);

namespace Drupal\experience_builder;

// @todo Question for Ben/Jesse/Harumi: does react also use JSON schema for restricting/defining its props? I.e.: identical set of primitives or not?
use Drupal\Core\TypedData\Type\UriInterface;

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

        // @todo Do we need to support both `format` *and* `pattern` simultaneously; for example to only allow URLs from a certain domain?
        array_key_exists('pattern', $schema) => new DataTypeShapeRequirements('Regex', ['pattern' => $schema['pattern']]),

        array_key_exists('format', $schema) => match ($schema['format']) {
          // @see https://json-schema.org/understanding-json-schema/reference/string#dates-and-times
          //              'date-time', 'time', 'date', 'duration'

          // @see https://json-schema.org/understanding-json-schema/reference/string#resource-identifiers
          'uuid' => new DataTypeShapeRequirements('Uuid', []),
          // TRICKY: Drupal core does not support RFC3987 aka IRIs, but it's a superset of RFC3986.
          'uri', 'iri' => new DataTypeShapeRequirements('PrimitiveType', [], UriInterface::class),
          // Specify an invalid constraint name because neither of these
          // formats are supported by Drupal core.
          // @todo Add missing validation constraint and then use it for \Drupal\path\Plugin\Field\FieldType\PathItem's `alias` property.
          'uri-reference', 'iri-reference' => new DataTypeShapeRequirements('NOT YET SUPPORTED', []),

          // @see https://json-schema.org/understanding-json-schema/reference/string#uri-template
          'uri-template' => new DataTypeShapeRequirements('NOT YET SUPPORTED', []),

          // @see https://json-schema.org/understanding-json-schema/reference/string#json-pointer
          'json-pointer', 'relative-json-pointer' => new DataTypeShapeRequirements('NOT YET SUPPORTED', []),

          // @see https://json-schema.org/understanding-json-schema/reference/string#regular-expressions
          'regex' => new DataTypeShapeRequirements('NOT YET SUPPORTED', []),
        },
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
