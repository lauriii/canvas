<?php

declare(strict_types=1);

namespace Drupal\experience_builder\PropExpressions\StructuredData;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Field\FieldItemInterface;

/**
 * For pointing to a prop in a field type (not considering any delta).
 */
final class ReferenceFieldTypePropExpression implements StructuredDataPropExpressionInterface {

  use CompoundExpressionTrait;

  public function __construct(
    public readonly FieldTypePropExpression $referencer,
    public readonly FieldPropExpression|ReferenceFieldPropExpression|FieldObjectPropsExpression $referenced,
  ) {}

  public function __toString(): string {
    return static::PREFIX
      . self::withoutPrefix((string) $this->referencer)
      . self::PREFIX_ENTITY_LEVEL
      . self::withoutPrefix((string) $this->referenced);
  }

  public static function fromString(string $representation): static {
    $parts = explode(self::PREFIX_ENTITY_LEVEL . self::PREFIX_ENTITY_LEVEL, $representation);
    $referencer = FieldTypePropExpression::fromString($parts[0]);
    $referenced = StructuredDataPropExpression::fromString(static::PREFIX . static::PREFIX_ENTITY_LEVEL . $parts[1]);
    assert($referenced instanceof FieldPropExpression || $referenced instanceof FieldObjectPropsExpression);
    return new static($referencer, $referenced);
  }

  public function isSupported(EntityInterface|FieldItemInterface $field_item): bool {
    assert($field_item instanceof FieldItemInterface);
    $actual_field_type = $field_item->getFieldDefinition()->getType();
    if ($actual_field_type !== $this->referencer->fieldType) {
      throw new \DomainException(sprintf("`%s` is an expression for field type `%s`, but the provided field item is of type `%s`.", (string) $this, $this->referencer->fieldType, $actual_field_type));
    }
    return TRUE;
  }

}
