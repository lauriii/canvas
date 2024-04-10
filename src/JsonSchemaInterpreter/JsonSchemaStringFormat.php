<?php

declare(strict_types=1);

namespace Drupal\experience_builder\JsonSchemaInterpreter;

// @see https://json-schema.org/understanding-json-schema/reference/string#format
// @see https://json-schema.org/understanding-json-schema/reference/string#built-in-formats
use Drupal\Core\TypedData\Type\DateTimeInterface;
use Drupal\experience_builder\DataTypeShapeRequirements;

enum JsonSchemaStringFormat: string {
  // Dates and times.
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

  public function toDataTypeShapeRequirements(): DataTypeShapeRequirements {
    return match($this) {
      // @see https://json-schema.org/understanding-json-schema/reference/string#dates-and-times
      // @todo Restrict to only fields with the storage setting set to \Drupal\datetime\Plugin\Field\FieldType\DateTimeItem::DATETIME_TYPE_DATETIME
      // @todo Somehow allow \Drupal\Core\Field\Plugin\Field\FieldType\TimestampItem too, even though it is int-based, thanks to the use of an adapter? Infer this from \Drupal\Core\Field\FieldTypePluginManager::getGroupedDefinitions(), specifically `category = "date_time"`?
      static::DATE_TIME => new DataTypeShapeRequirements('PrimitiveType', [], DateTimeInterface::class),
      // @todo Restrict to only fields with the storage setting set to \Drupal\datetime\Plugin\Field\FieldType\DateTimeItem::DATETIME_TYPE_DATE
      // @todo Somehow allow \Drupal\Core\Field\Plugin\Field\FieldType\TimestampItem too, even though it is int-based, thanks to the use of an adapter? Infer this from \Drupal\Core\Field\FieldTypePluginManager::getGroupedDefinitions(), specifically `category = "date_time"`?
      static::DATE => new DataTypeShapeRequirements('PrimitiveType', [], DateTimeInterface::class),
      // @todo Somehow allow \Drupal\Core\Field\Plugin\Field\FieldType\TimestampItem too, even though it is int-based, thanks to the use of an adapter? Infer this from \Drupal\Core\Field\FieldTypePluginManager::getGroupedDefinitions(), specifically `category = "date_time"`?
      static::TIME => new DataTypeShapeRequirements('NOT YET SUPPORTED', []),
      static::DURATION => new DataTypeShapeRequirements('NOT YET SUPPORTED', []),

      // …
    };
  }
};
