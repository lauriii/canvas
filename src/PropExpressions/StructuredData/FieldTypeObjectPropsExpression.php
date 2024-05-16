<?php

declare(strict_types=1);

namespace Drupal\experience_builder\PropExpressions\StructuredData;

use Drupal\Component\Assertion\Inspector;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Field\FieldItemInterface;

/**
 * For pointing to a prop in a field type (not considering any delta).
 */
class FieldTypeObjectPropsExpression implements StructuredDataPropExpressionInterface {

  public function __construct(
    public readonly string $fieldType,
    public readonly array $objectPropsToFieldTypeProps,
  ) {
    assert(Inspector::assertAllStrings(array_keys($this->objectPropsToFieldTypeProps)));
    assert(Inspector::assertAll(function ($expr) {
      return $expr instanceof FieldTypePropExpression || $expr instanceof ReferenceFieldTypePropExpression;
    }, $this->objectPropsToFieldTypeProps));
  }

  public function __toString(): string {
    return sprintf(static::PREFIX . "%s␟{%s}", $this->fieldType, implode(', ', array_map(
      fn (string $obj_prop_name, FieldTypePropExpression|ReferenceFieldTypePropExpression $expr) => sprintf('%s%s%s',
        $obj_prop_name,
        $expr instanceof ReferenceFieldTypePropExpression ? '↝' : '↠',
        $expr instanceof ReferenceFieldTypePropExpression ? $expr->propName . '␜' . (string) $expr->referenced : $expr->propName,
      ),
      array_keys($this->objectPropsToFieldTypeProps),
      array_values($this->objectPropsToFieldTypeProps),
    )));
  }

  public static function fromString(string $representation): static {
    [$field_type, $object_mapping] = explode('␟', mb_substr($representation, 2));
    // Strip the surrounding curly braces.
    $object_mapping = mb_substr($object_mapping, 1, -1);

    $objectPropsToFieldTypeProps = [];
    foreach (explode(',', $object_mapping) as $obj_prop_mapping) {
      if (str_contains($obj_prop_mapping, '↠')) {
        [$sdc_obj_prop_name, $field_type_prop_name] = explode('↠', $obj_prop_mapping);
        $objectPropsToFieldTypeProps[$sdc_obj_prop_name] = new FieldTypePropExpression($field_type, $field_type_prop_name);
      }
      else {
        throw new \LogicException('not yet implemented');
      }
    }

    return new static($field_type, $objectPropsToFieldTypeProps);
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
