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
 * Validates the EntityFieldExpressionsSameFieldMustBeCoalesced constraint.
 */
final class EntityFieldExpressionsSameFieldMustBeCoalescedConstraintValidator extends ConstraintValidator {

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $value, Constraint $constraint): void {
    if (!$constraint instanceof EntityFieldExpressionsSameFieldMustBeCoalescedConstraint) {
      throw new UnexpectedTypeException($constraint, EntityFieldExpressionsSameFieldMustBeCoalescedConstraint::class);
    }

    if (!\is_array($value) || \count($value) < 2) {
      // Nothing to compare if there are fewer than 2 expressions.
      return;
    }

    // Group expressions by their starting-point key (host entity type+bundle,
    // field name, delta). Every expression type implements
    // EntityFieldBasedPropExpressionInterface; for ReferenceFieldPropExpression
    // the starting point is the referencer field on the host entity. Report one
    // violation per group with 2+ expressions.
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
      if (\count($bucket) < 2) {
        continue;
      }
      $first = $bucket[0];
      // These share a field but were not coalesced. When they cannot be
      // coalesced at all — they descend through a multi-bundle reference more
      // than once — saying they "must be coalesced" would be misleading, so
      // skip them here; EntityFieldExpressionsMustNotNestBranches reports that.
      // @todo Drop this skip once nested branching is supported, in https://git.drupalcode.org/project/canvas/-/work_items/3591865
      try {
        Coalescer::coalesce($bucket);
      }
      catch (NestedBranchNotSupportedException) {
        continue;
      }
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
