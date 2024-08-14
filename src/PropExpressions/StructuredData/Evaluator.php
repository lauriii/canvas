<?php

declare(strict_types=1);

namespace Drupal\experience_builder\PropExpressions\StructuredData;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Field\FieldItemInterface;
use Drupal\Core\TypedData\PrimitiveInterface;
use Drupal\datetime\Plugin\Field\FieldType\DateTimeItem;

final class Evaluator {

  public static function evaluate(null|EntityInterface|FieldItemInterface $entity_or_field, StructuredDataPropExpressionInterface $expr): mixed {
    $result = self::doEvaluate($entity_or_field, $expr);
    // Compensate for DateTimeItemInterface::DATETIME_STORAGE_FORMAT not
    // including the trailing `Z`. In theory, this should always use an adapter.
    // But is the storage and complexity overhead of doing that worth that
    // architectural purity?
    // @see \Drupal\datetime\Plugin\Field\FieldType\DateTimeItem::DATETIME_TYPE_DATETIME
    // @see \Drupal\datetime\Plugin\Field\FieldType\DateTimeItemInterface::DATETIME_STORAGE_FORMAT
    // @see https://ijmacd.github.io/rfc3339-iso8601/
    if ($expr instanceof FieldTypePropExpression && $expr->fieldType === 'datetime' && $entity_or_field instanceof FieldItemInterface && $entity_or_field->getFieldDefinition()->getFieldStorageDefinition()->getSetting('datetime_type') === DateTimeItem::DATETIME_TYPE_DATETIME) {
      return $result . 'Z';
    }
    return $result;
  }

  private static function doEvaluate(null|EntityInterface|FieldItemInterface $entity_or_field, StructuredDataPropExpressionInterface $expr): mixed {
    if ($entity_or_field === NULL) {
      // Entity is optional for reference fields: the reference may point to
      // something or not.
      if ($expr instanceof ReferenceFieldPropExpression) {
        return NULL;
      }
      throw new \OutOfRangeException('No data provided to evaluate expression ' . (string) $expr);
    }

    // Assert that the received entity or field meets the needs of the
    // expression.
    try {
      $expr->isSupported($entity_or_field);
    }
    catch (\DomainException $e) {
      throw $e;
    }

    if ($entity_or_field instanceof FieldItemInterface) {
      $field = $entity_or_field;
      assert($field instanceof FieldItemInterface);
      return match (get_class($expr)) {
        FieldTypePropExpression::class => (function () use ($field, $expr) {
          $prop = $field->get($expr->propName);
          return $prop instanceof PrimitiveInterface
            ? $prop->getCastedValue()
            : $prop->getValue();
        })(),
        FieldTypeObjectPropsExpression::class => array_combine(
          array_keys($expr->objectPropsToFieldTypeProps),
          array_map(
            fn (FieldTypePropExpression|ReferenceFieldTypePropExpression $sub_expr) => self::evaluate($field, $sub_expr),
            $expr->objectPropsToFieldTypeProps
          )
        ),
        ReferenceFieldTypePropExpression::class => self::evaluate(
          $field->get($expr->referencer->propName)->getValue(),
          $expr->referenced
        ),
        default => throw new \LogicException('Unhandled expression type. ' . (string) $expr),
      };
    }
    else {
      $entity = $entity_or_field;
      assert($entity instanceof EntityInterface);
      // @todo support non-fieldable entities?
      assert($entity instanceof FieldableEntityInterface);
      return match (get_class($expr)) {
        FieldPropExpression::class => (function () use ($entity, $expr) {
          $prop = $entity->get($expr->fieldName)[$expr->delta ?? 0]?->get($expr->propName);
          return $prop instanceof PrimitiveInterface
            ? $prop->getCastedValue()
            : $prop?->getValue();
        })(),
        ReferenceFieldPropExpression::class => self::evaluate(
          self::evaluate($entity, $expr->referencer),
          $expr->referenced
        ),
        FieldObjectPropsExpression::class => array_combine(
          array_keys($expr->objectPropsToFieldProps),
          array_map(
            fn(FieldPropExpression|ReferenceFieldPropExpression $sub_expr) => self::evaluate($entity_or_field, $sub_expr),
            $expr->objectPropsToFieldProps
          )
        ),
        default => throw new \LogicException('Unhandled expression type.'),
      };
    }
  }

}
