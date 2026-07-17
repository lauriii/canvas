<?php

declare(strict_types=1);

namespace Drupal\canvas\ListBuilder;

/**
 * Field type families of the List element's filter and sort settings.
 *
 * Every filterable field is mapped to exactly one family, and the family
 * determines which condition operators are allowed. Unknown field types
 * degrade to the Unknown family, which only supports presence checks, so
 * custom field types never break the List element.
 *
 * @see docs/adr/0020-list-element-component-source-with-constrained-query-dsl.md
 *
 * @internal
 */
enum ListElementFieldTypeFamily: string {

  case Text = 'text';
  case Options = 'options';
  case Reference = 'reference';
  case Date = 'date';
  case Number = 'number';
  case Unknown = 'unknown';

  public const string OP_IS_SET = 'is_set';
  public const string OP_NOT_SET = 'not_set';
  public const string OP_CONTAINS = 'contains';
  public const string OP_NOT_CONTAINS = 'not_contains';
  public const string OP_STARTS_WITH = 'starts_with';
  public const string OP_ENDS_WITH = 'ends_with';
  public const string OP_EQUALS = 'equals';
  public const string OP_NOT_EQUALS = 'not_equals';
  public const string OP_BETWEEN = 'between';
  public const string OP_GREATER_THAN = 'gt';
  public const string OP_GREATER_THAN_OR_EQUAL = 'gte';
  public const string OP_LESS_THAN = 'lt';
  public const string OP_LESS_THAN_OR_EQUAL = 'lte';

  /**
   * Maps a field type to its family.
   */
  public static function fromFieldType(string $field_type): self {
    return match ($field_type) {
      'string', 'string_long', 'text', 'text_long', 'text_with_summary', 'email' => self::Text,
      'boolean', 'list_string', 'list_integer', 'list_float', 'language' => self::Options,
      'entity_reference', 'file', 'image', 'link' => self::Reference,
      'datetime', 'timestamp', 'created', 'changed' => self::Date,
      'integer', 'decimal', 'float' => self::Number,
      default => self::Unknown,
    };
  }

  /**
   * The operators allowed for this family.
   *
   * @param bool $has_target
   *   For the Reference family: whether the field references entities by
   *   target ID (equality operators are only allowed for such fields, e.g.
   *   not for link fields).
   *
   * @return list<string>
   */
  public function allowedOperators(bool $has_target = FALSE): array {
    $presence = [self::OP_IS_SET, self::OP_NOT_SET];
    return match ($this) {
      self::Text => [
        ...$presence,
        self::OP_CONTAINS,
        self::OP_NOT_CONTAINS,
        self::OP_STARTS_WITH,
        self::OP_ENDS_WITH,
        self::OP_EQUALS,
        self::OP_NOT_EQUALS,
      ],
      self::Options => [...$presence, self::OP_EQUALS, self::OP_NOT_EQUALS],
      self::Reference => $has_target
        ? [...$presence, self::OP_EQUALS, self::OP_NOT_EQUALS]
        : $presence,
      self::Date => [...$presence, self::OP_EQUALS, self::OP_NOT_EQUALS, self::OP_BETWEEN],
      self::Number => [
        ...$presence,
        self::OP_EQUALS,
        self::OP_NOT_EQUALS,
        self::OP_GREATER_THAN,
        self::OP_GREATER_THAN_OR_EQUAL,
        self::OP_LESS_THAN,
        self::OP_LESS_THAN_OR_EQUAL,
      ],
      self::Unknown => $presence,
    };
  }

  /**
   * Whether fields of this family can be used as a sort.
   */
  public function isSortable(): bool {
    return match ($this) {
      self::Text, self::Options, self::Date, self::Number => TRUE,
      self::Reference, self::Unknown => FALSE,
    };
  }

}
