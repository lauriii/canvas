<?php

declare(strict_types=1);

namespace Drupal\experience_builder\PropExpressions\StructuredData;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Field\FieldItemInterface;

/**
 * For pointing to a prop in a field type (not considering any delta).
 */
class FieldTypePropExpression implements StructuredDataPropExpressionInterface {

  public function __construct(
    public readonly string $fieldType,
    public readonly string $propName,
  ) {}

  public function __toString(): string {
    return sprintf(static::PREFIX . "%s␟%s", $this->fieldType, $this->propName);
  }

  public static function fromString(string $representation): static {
    $parts = explode('␟', mb_substr($representation, 2));
    return new static(...$parts);
  }

  public function isSupported(EntityInterface|FieldItemInterface $field_item): bool {
    assert($field_item instanceof FieldItemInterface);
    $actual_field_type = $field_item->getFieldDefinition()->getType();
    if ($actual_field_type !== $this->fieldType) {
      throw new \DomainException(sprintf("`%s` is an expression for field type `%s`, but the provided field item is of type `%s`.", (string) $this, $this->fieldType, $actual_field_type));
    }
    return TRUE;
  }

}
