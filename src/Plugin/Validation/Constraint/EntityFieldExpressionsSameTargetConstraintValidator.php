<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Validation\Constraint;

use Drupal\canvas\PropExpressions\StructuredData\EntityFieldBasedPropExpressionInterface;
use Drupal\canvas\PropExpressions\StructuredData\StructuredDataPropExpression;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

/**
 * Validates the EntityFieldExpressionsSameTarget constraint.
 */
final class EntityFieldExpressionsSameTargetConstraintValidator extends ConstraintValidator {

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $value, Constraint $constraint): void {
    if (!$constraint instanceof EntityFieldExpressionsSameTargetConstraint) {
      throw new UnexpectedTypeException($constraint, EntityFieldExpressionsSameTargetConstraint::class);
    }

    if (!\is_array($value) || \count($value) < 2) {
      // Nothing to compare if there are fewer than 2 expressions.
      return;
    }

    $data_types = [];
    foreach ($value as $expression_string) {
      if (!\is_string($expression_string)) {
        continue;
      }
      try {
        $parsed = StructuredDataPropExpression::fromString($expression_string);
        if ($parsed instanceof EntityFieldBasedPropExpressionInterface) {
          $data_types[] = $parsed->getHostEntityDataDefinition()->getDataType();
        }
      }
      catch (\Throwable) {
        // Invalid expressions are handled by ValidStructuredDataPropExpression.
        continue;
      }
    }

    $unique_data_types = \array_unique($data_types);
    if (\count($unique_data_types) > 1) {
      $this->context->addViolation($constraint->message, [
        '@types' => \implode(', ', $unique_data_types),
      ]);
    }
  }

}
