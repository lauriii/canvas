<?php

declare(strict_types=1);

namespace Drupal\experience_builder\JsonSchemaInterpreter;

use Drupal\Core\TypedData\Type\DateTimeInterface;
use Drupal\Core\TypedData\Type\UriInterface;
use Drupal\datetime\Plugin\Field\FieldType\DateTimeItem;
use Drupal\experience_builder\Plugin\Validation\Constraint\UriTemplateWithVariablesConstraint;
use Drupal\experience_builder\PropExpressions\StructuredData\FieldTypePropExpression;
use Drupal\experience_builder\PropShape\PropShape;
use Drupal\experience_builder\PropShape\StorablePropShape;
use Drupal\experience_builder\ShapeMatcher\DataTypeShapeRequirement;
use Symfony\Component\Validator\Constraints\Ip;

// phpcs:disable Drupal.Files.LineLength.TooLong
// phpcs:disable Drupal.Commenting.PostStatementComment.Found

/**
 * @see https://json-schema.org/understanding-json-schema/reference/string#format
 * @see https://json-schema.org/understanding-json-schema/reference/string#built-in-formats
 *
 * @phpstan-type JsonSchema array<string, mixed>
 * @internal
 */
enum JsonSchemaStringFormat: string {
  // Dates and times.
  // @see https://json-schema.org/understanding-json-schema/reference/string#dates-and-times
  case DATE_TIME = 'date-time'; // RFC3339 section 5.6 — subset of ISO8601.
  case TIME = 'time'; // Since draft 7.
  case DATE = 'date'; // Since draft 7.
  case DURATION = 'duration'; // Since draft 2019-09.

  // Email addresses.
  case EMAIL = 'email'; // RFC5321 section 4.1.2.
  case IDN_EMAIL = 'idn-email'; // Since draft 7, RFC6531.

  // Hostnames.
  case HOSTNAME = 'hostname'; // RFC1123, section 2.1.
  case IDN_HOSTNAME = 'idn-hostname'; // Since draft 7, RFC5890 section 2.3.2.3.

  // IP Addresses.
  case IPV4 = 'ipv4'; // RFC2673 section 3.2.
  case IPV6 = 'ipv6'; // RFC2373 section 2.2.

  // Resource identifiers.
  case UUID = 'uuid'; // Since draft 2019-09. RFC4122.
  case URI = 'uri'; // RFC3986.
  // Because FILTER_VALIDATE_URL does not conform to RFC-3986, and cannot handle
  // relative URLs, to support the relative URLs the 'uri-reference' format must
  // be used.
  // @see \JsonSchema\Constraints\FormatConstraint::check()
  // @see \Drupal\Core\Validation\Plugin\Validation\Constraint\PrimitiveTypeConstraintValidator::validate()
  case URI_REFERENCE = 'uri-reference'; // Since draft 6, RFC3986 section 4.1.
  case IRI = 'iri'; // Since draft 7, RFC3987.
  case IRI_REFERENCE = 'iri-reference'; // Since draft 7, RFC3987.

  // URI template.
  case URI_TEMPLATE = 'uri-template'; // Since draft 7, RFC6570.

  // JSON Pointer.
  case JSON_POINTER = 'json-pointer'; // Since draft 6, RFC6901.
  case RELATIVE_JSON_POINTER = 'relative-json-pointer'; // Since draft 7.

  // Regular expressions.
  case REGEX = 'regex'; // Since draft 7, ECMA262.

  /**
   * @param JsonSchema $schema
   * @see \Drupal\experience_builder\JsonSchemaInterpreter\JsonSchemaType::toDataTypeShapeRequirements()
   */
  public function toDataTypeShapeRequirements(array $schema): DataTypeShapeRequirement {
    return match($this) {
      // Built-in formats: dates and times.
      // @see https://json-schema.org/understanding-json-schema/reference/string#dates-and-times
      // @todo Restrict to only fields with the storage setting set to \Drupal\datetime\Plugin\Field\FieldType\DateTimeItem::DATETIME_TYPE_DATETIME
      // @todo Somehow allow \Drupal\Core\Field\Plugin\Field\FieldType\TimestampItem too, even though it is int-based, thanks to the use of an adapter? Infer this from \Drupal\Core\Field\FieldTypePluginManager::getGroupedDefinitions(), specifically `category = "date_time"`?
      static::DATE_TIME => new DataTypeShapeRequirement('PrimitiveType', [], DateTimeInterface::class),
      // @todo Restrict to only fields with the storage setting set to \Drupal\datetime\Plugin\Field\FieldType\DateTimeItem::DATETIME_TYPE_DATE
      // @todo Somehow allow \Drupal\Core\Field\Plugin\Field\FieldType\TimestampItem too, even though it is int-based, thanks to the use of an adapter? Infer this from \Drupal\Core\Field\FieldTypePluginManager::getGroupedDefinitions(), specifically `category = "date_time"`?
      static::DATE => new DataTypeShapeRequirement('PrimitiveType', [], DateTimeInterface::class),
      // @todo Somehow allow \Drupal\Core\Field\Plugin\Field\FieldType\TimestampItem too, even though it is int-based, thanks to the use of an adapter? Infer this from \Drupal\Core\Field\FieldTypePluginManager::getGroupedDefinitions(), specifically `category = "date_time"`?
      static::TIME => new DataTypeShapeRequirement('NOT YET SUPPORTED', []),
      static::DURATION => new DataTypeShapeRequirement('NOT YET SUPPORTED', []),

      // Built-in formats: email addresses.
      // @see https://json-schema.org/understanding-json-schema/reference/string#email-addresses
      static::EMAIL, static::IDN_EMAIL => new DataTypeShapeRequirement('Email', []),

      // Built-in formats: hostnames.
      // @see https://json-schema.org/understanding-json-schema/reference/string#hostnames
      static::HOSTNAME, static::IDN_HOSTNAME => new DataTypeShapeRequirement('Hostname', []),

      // Built-in formats: IP addresses.
      // @see https://json-schema.org/understanding-json-schema/reference/string#ip-addresses
      static::IPV4 => new DataTypeShapeRequirement('Ip', ['version' => Ip::V4]),
      static::IPV6 => new DataTypeShapeRequirement('Ip', ['version' => Ip::V6]),

      // Built-in formats: resource identifiers.
      // @see https://json-schema.org/understanding-json-schema/reference/string#resource-identifiers
      static::UUID => new DataTypeShapeRequirement('Uuid', []),
      // TRICKY: Drupal core does not support RFC3987 aka IRIs, but it's a superset of RFC3986.
      static::URI_REFERENCE, static::URI, static::IRI, static::IRI_REFERENCE => new DataTypeShapeRequirement('PrimitiveType', [], UriInterface::class),

      // Built-in formats: URI template.
      // @see https://json-schema.org/understanding-json-schema/reference/string#uri-template
      static::URI_TEMPLATE => match(array_key_exists('x-required-variables', $schema)) {
        TRUE => new DataTypeShapeRequirement(UriTemplateWithVariablesConstraint::PLUGIN_ID, ['requiredVariables' => $schema['x-required-variables']]),
        default => new DataTypeShapeRequirement('NOT YET SUPPORTED', []),
      },

      // Built-in formats: JSON Pointer.
      // @see https://json-schema.org/understanding-json-schema/reference/string#json-pointer
      static::JSON_POINTER, static::RELATIVE_JSON_POINTER => new DataTypeShapeRequirement('NOT YET SUPPORTED', []),

      // Built-in formats: Regular expressions.
      // @see https://json-schema.org/understanding-json-schema/reference/string#regular-expressions
      static::REGEX => new DataTypeShapeRequirement('NOT YET SUPPORTED', []),
    };
  }

  /**
   * Finds the recommended UX (storage + widget) for a prop shape.
   *
   * Used for generating a StaticPropSource, for storing a value that fits in
   * this prop shape.
   *
   * @param \Drupal\experience_builder\PropShape\PropShape $shape
   *   The prop shape to find the recommended UX (storage + widget) for.
   *
   * @return \Drupal\experience_builder\PropShape\StorablePropShape|null
   *   NULL is returned to indicate that Experience Builder + Drupal core do not
   *   support a field type that provides a good UX for entering a value of this
   *   shape. Otherwise, a StorablePropShape is returned that specifies that UX.
   *
   * @see \Drupal\experience_builder\PropSource\StaticPropSource
   */
  public function computeStorablePropShape(PropShape $shape): ?StorablePropShape {
    return match($this) {
      // Built-in formats: dates and times.
      // @see https://json-schema.org/understanding-json-schema/reference/string#dates-and-times
      // @see \Drupal\datetime\Plugin\Field\FieldType\DateTimeItem
      static::DATE_TIME => new StorablePropShape(shape: $shape, fieldTypeProp: new FieldTypePropExpression('datetime', 'value'), fieldStorageSettings: ['datetime_type' => DateTimeItem::DATETIME_TYPE_DATETIME], fieldWidget: 'datetime_default'),
      // @see \Drupal\datetime\Plugin\Field\FieldType\DateTimeItem
      static::DATE => new StorablePropShape(shape: $shape, fieldTypeProp: new FieldTypePropExpression('datetime', 'value'), fieldStorageSettings: ['datetime_type' => DateTimeItem::DATETIME_TYPE_DATE], fieldWidget: 'datetime_default'),
      // @todo A new subclass of DateTimeItem, to allow storing only time?
      static::TIME => NULL,
      // @todo A new field type powered by \Drupal\Core\TypedData\Plugin\DataType\DurationIso8601, to allow storing a duration?
      // @see \Drupal\Core\TypedData\Plugin\DataType\DurationIso8601
      static::DURATION => NULL,

      // Built-in formats: email addresses.
      // @see https://json-schema.org/understanding-json-schema/reference/string#email-addresses
      // @see \Drupal\Core\Field\Plugin\Field\FieldType\EmailItem
      static::EMAIL, static::IDN_EMAIL => new StorablePropShape(shape: $shape, fieldTypeProp: new FieldTypePropExpression('email', 'value'), fieldWidget: 'email_default'),

      // Built-in formats: hostnames.
      // @see https://json-schema.org/understanding-json-schema/reference/string#hostnames
      static::HOSTNAME, static::IDN_HOSTNAME => NULL,

      // Built-in formats: IP addresses.
      // @see https://json-schema.org/understanding-json-schema/reference/string#ip-addresses
      static::IPV4 => NULL,
      static::IPV6 => NULL,

      // Built-in formats: resource identifiers.
      // @see https://json-schema.org/understanding-json-schema/reference/string#resource-identifiers
      // ⚠️ This field type has no widget in Drupal core, otherwise it'd be
      // possible to support! But … would allowing the Content Creator to
      // enter a UUID really make sense?
      // @see \Drupal\Core\Field\Plugin\Field\FieldType\UuidItem
      static::UUID => NULL,
      // TRICKY: Drupal core does not support RFC3987 aka IRIs, but it's a superset of RFC3986.
      // TRICKY: the `uri` and `iri` prop types will only pass validation with absolute paths, so we
      // instead use the link widget which is more permissive about the URI/IRI content.
      // @see \Drupal\Core\Field\Plugin\Field\FieldType\UriItem
      // @see \Drupal\link\Plugin\Field\FieldType\LinkItem::defaultFieldSettings()
      static::URI_REFERENCE, static::URI, static::IRI_REFERENCE, static::IRI => new StorablePropShape(
        shape: $shape,
        fieldTypeProp: new FieldTypePropExpression('link', 'url'),
        // @see \Drupal\link\Plugin\Field\FieldType\LinkItem::defaultFieldSettings()
        fieldInstanceSettings: [
          // This shape only needs the URI, not a title.
          'title' => 0,
        ],
        fieldWidget: 'link_default',
      ),

      // Built-in formats: URI template.
      // @see https://json-schema.org/understanding-json-schema/reference/string#uri-template
      static::URI_TEMPLATE => NULL,

      // Built-in formats: JSON Pointer.
      // @see https://json-schema.org/understanding-json-schema/reference/string#json-pointer
      static::JSON_POINTER, static::RELATIVE_JSON_POINTER => NULL,

      // Built-in formats: Regular expressions.
      // @see https://json-schema.org/understanding-json-schema/reference/string#regular-expressions
      static::REGEX => NULL,
    };
  }

}
