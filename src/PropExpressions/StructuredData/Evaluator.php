<?php

declare(strict_types=1);

namespace Drupal\experience_builder\PropExpressions\StructuredData;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Field\FieldItemInterface;

final class Evaluator {

  public static function evaluate(null|EntityInterface|FieldItemInterface $entity_or_field, StructuredDataPropExpressionInterface $expr): mixed {
    if ($entity_or_field === NULL) {
      // Entity is optional for reference fields: the reference may point to
      // something or not.
      if ($expr instanceof ReferenceFieldPropExpression) {
        return NULL;
      }
      throw new \LogicException('No data provided to evaluate expression ' . (string) $expr);
    }

    // Assert that the received entity or field meets the needs of the
    // expression.
    try {
      $expr->isSupported($entity_or_field);
    }
    catch (\DomainException $e) {
      throw $e;
    }

    return match (get_class($expr)) {
      FieldPropExpression::class => $entity_or_field->get($expr->fieldName)[$expr->delta ?? 0]?->get($expr->propName)->getValue(),
      ReferenceFieldPropExpression::class => self::evaluate(
        self::evaluate($entity_or_field, $expr->referencer),
        $expr->referenced
      ),
      FieldTypePropExpression::class => $entity_or_field->get($expr->propName)->getValue(),
      FieldTypeObjectPropsExpression::class => array_combine(
        array_keys($expr->objectPropsToFieldTypeProps),
        array_map(
          fn (FieldTypePropExpression $sub_expr) => self::evaluate($entity_or_field, $sub_expr),
          $expr->objectPropsToFieldTypeProps
        )
      ),
      FieldObjectPropsExpression::class => array_combine(
        array_keys($expr->objectPropsToFieldProps),
        array_map(
          fn (FieldPropExpression|ReferenceFieldPropExpression $sub_expr) => self::evaluate($entity_or_field, $sub_expr),
          $expr->objectPropsToFieldProps
        )
      ),
    };
  }

}
