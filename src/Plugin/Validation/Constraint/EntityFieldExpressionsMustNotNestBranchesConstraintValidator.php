<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Validation\Constraint;

use Drupal\canvas\PropExpressions\StructuredData\Coalescer;
use Drupal\canvas\PropExpressions\StructuredData\EntityFieldBasedPropExpressionInterface;
use Drupal\canvas\PropExpressions\StructuredData\NestedBranchNotSupportedException;
use Drupal\canvas\PropExpressions\StructuredData\StructuredDataPropExpression;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

/**
 * Validates the EntityFieldExpressionsMustNotNestBranches constraint.
 */
final class EntityFieldExpressionsMustNotNestBranchesConstraintValidator extends ConstraintValidator {

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $value, Constraint $constraint): void {
    if (!$constraint instanceof EntityFieldExpressionsMustNotNestBranchesConstraint) {
      throw new UnexpectedTypeException($constraint, EntityFieldExpressionsMustNotNestBranchesConstraint::class);
    }

    if (!\is_array($value)) {
      return;
    }

    // Nested branching only emerges when several picks on one reference field
    // coalesce into a branch whose value is itself a multi-bundle reference, so
    // group by starting point (host entity type+bundle, field name, delta) and
    // try to coalesce each group. Coalescing surfaces that case explicitly.
    /** @var array<string, list<EntityFieldBasedPropExpressionInterface>> $buckets */
    $buckets = [];
    foreach ($value as $expression_string) {
      if (!\is_string($expression_string)) {
        continue;
      }
      try {
        $parsed = StructuredDataPropExpression::fromString($expression_string);
      }
      catch (\Throwable) {
        // Invalid expressions are handled by ValidStructuredDataPropExpression.
        continue;
      }
      if ($parsed instanceof EntityFieldBasedPropExpressionInterface) {
        $buckets[$parsed->getStartingPointKey()][] = $parsed;
      }
    }

    foreach ($buckets as $bucket) {
      try {
        Coalescer::coalesce($bucket);
      }
      catch (NestedBranchNotSupportedException) {
        $first = $bucket[0];
        $this->context->addViolation($constraint->message, [
          '@field' => \sprintf(
            '%s.%s',
            $first->getHostEntityDataDefinition()->getDataType(),
            $first->getFieldName(),
          ),
        ]);
      }
    }
  }

}
