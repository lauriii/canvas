<?php

declare(strict_types=1);

namespace Drupal\canvas\PropExpressions\StructuredData;

use Drupal\Component\Assertion\Inspector;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Field\FieldItemInterface;
use Drupal\Core\Field\FieldItemListInterface;

/**
 * For pointing to a prop in a field type (not considering any delta).
 */
final class FieldTypeObjectPropsExpression implements FieldTypeBasedPropExpressionInterface, ObjectPropExpressionInterface {

  use CompoundExpressionTrait;

  /**
   * Constructs a new FieldTypeObjectPropsExpression.
   *
   * @param string $fieldType
   *   A field type.
   * @param non-empty-array<string, \Drupal\canvas\PropExpressions\StructuredData\FieldTypePropExpression|\Drupal\canvas\PropExpressions\StructuredData\ReferenceFieldTypePropExpression> $objectPropsToFieldTypeProps
   *   A mapping of prop names to non-object field type-based expressions.
   */
  public function __construct(
    public readonly string $fieldType,
    public readonly array $objectPropsToFieldTypeProps,
  ) {
    \assert(!empty($this->objectPropsToFieldTypeProps));
    assert(Inspector::assertAllStrings(array_keys($this->objectPropsToFieldTypeProps)));
    assert(Inspector::assertAll(function ($expr) {
      return $expr instanceof FieldTypePropExpression || $expr instanceof ReferenceFieldTypePropExpression;
    }, $this->objectPropsToFieldTypeProps));
  }

  public function __toString(): string {
    return static::PREFIX_EXPRESSION_TYPE
      . $this->fieldType
      . static::PREFIX_PROPERTY_LEVEL . static::PREFIX_OBJECT
      . implode(',', array_map(
        fn (string $obj_prop_name, FieldTypePropExpression|ReferenceFieldTypePropExpression $expr) => sprintf('%s%s%s',
          $obj_prop_name,
          $expr instanceof ReferenceFieldTypePropExpression
            ? self::SYMBOL_OBJECT_MAPPED_FOLLOW_REFERENCE
            : self::SYMBOL_OBJECT_MAPPED_USE_PROP,
          $expr instanceof ReferenceFieldTypePropExpression
            ? $expr->referencer->propName . self::PREFIX_ENTITY_LEVEL . self::withoutExpressionTypePrefix((string) $expr->referenced)
            : $expr->propName,
        ),
        array_keys($this->objectPropsToFieldTypeProps),
        array_values($this->objectPropsToFieldTypeProps),
      ))
      . static::SUFFIX_OBJECT;
  }

  /**
   * {@inheritdoc}
   */
  public function calculateDependencies(FieldableEntityInterface|FieldItemListInterface|null $field_item_list = NULL): array {
    assert($field_item_list === NULL || $field_item_list instanceof FieldItemListInterface);
    $dependencies = [];
    foreach ($this->objectPropsToFieldTypeProps as $expr) {
      $dependencies = NestedArray::mergeDeep($dependencies, $expr->calculateDependencies($field_item_list));
    }
    return $dependencies;
  }

  public static function fromString(string $representation): static {
    [$field_type, $object_mapping] = explode(self::PREFIX_PROPERTY_LEVEL, mb_substr($representation, 2), 2);
    // Strip the surrounding curly braces.
    $object_mapping = mb_substr($object_mapping, 1, -1);

    $objectPropsToFieldTypeProps = [];
    foreach (explode(',', $object_mapping) as $obj_prop_mapping) {
      if (str_contains($obj_prop_mapping, self::SYMBOL_OBJECT_MAPPED_USE_PROP)) {
        [$sdc_obj_prop_name, $field_type_prop_name] = explode(self::SYMBOL_OBJECT_MAPPED_USE_PROP, $obj_prop_mapping);
        $objectPropsToFieldTypeProps[$sdc_obj_prop_name] = new FieldTypePropExpression($field_type, $field_type_prop_name);
      }
      else {
        [$sdc_obj_prop_name, $remainder] = explode(self::SYMBOL_OBJECT_MAPPED_FOLLOW_REFERENCE, $obj_prop_mapping);
        [$field_type_prop_name, $remainder] = explode(self::PREFIX_ENTITY_LEVEL, $remainder, 2);
        $referenced = StructuredDataPropExpression::fromString(static::PREFIX_EXPRESSION_TYPE . $remainder);
        assert($referenced instanceof FieldPropExpression || $referenced instanceof ReferenceFieldPropExpression);
        $objectPropsToFieldTypeProps[$sdc_obj_prop_name] = new ReferenceFieldTypePropExpression(
          new FieldTypePropExpression($field_type, $field_type_prop_name),
          $referenced
        );
      }
    }

    return new static($field_type, $objectPropsToFieldTypeProps);
  }

  public function validateSupport(EntityInterface|FieldItemInterface|FieldItemListInterface $field): void {
    assert($field instanceof FieldItemInterface || $field instanceof FieldItemListInterface);
    $actual_field_type = $field->getFieldDefinition()->getType();
    if ($actual_field_type !== $this->fieldType) {
      throw new \DomainException(sprintf("`%s` is an expression for field type `%s`, but the provided field item (list) is of type `%s`.", (string) $this, $this->fieldType, $actual_field_type));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getFieldType(): string {
    return $this->fieldType;
  }

  /**
   * {@inheritdoc}
   *
   * @return non-empty-array<string, (\Drupal\canvas\PropExpressions\StructuredData\ScalarPropExpressionInterface&\Drupal\canvas\PropExpressions\StructuredData\FieldTypeBasedPropExpressionInterface)|(\Drupal\canvas\PropExpressions\StructuredData\ReferencePropExpressionInterface&\Drupal\canvas\PropExpressions\StructuredData\FieldTypeBasedPropExpressionInterface)>
   */
  public function getObjectExpressions(): array {
    return $this->objectPropsToFieldTypeProps;
  }

}
