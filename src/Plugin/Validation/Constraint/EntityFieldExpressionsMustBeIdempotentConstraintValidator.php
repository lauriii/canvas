<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Validation\Constraint;

use Drupal\canvas\PropExpressions\StructuredData\Coalescer;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

/**
 * Validates the EntityFieldExpressionsMustBeIdempotent constraint.
 */
final class EntityFieldExpressionsMustBeIdempotentConstraintValidator extends ConstraintValidator {

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $value, Constraint $constraint): void {
    if (!$constraint instanceof EntityFieldExpressionsMustBeIdempotentConstraint) {
      throw new UnexpectedTypeException($constraint, EntityFieldExpressionsMustBeIdempotentConstraint::class);
    }

    if (!\is_array($value)) {
      return;
    }

    // Check each entry on its own: an entry is reproducible by the picker iff
    // expanding then re-combining it yields the same entry (modulo the
    // canonical key-sorting `coalesce()` applies, which is why both sides are
    // coalesced before comparison). Cross-entry coalescing is the separate
    // concern of EntityFieldExpressionsSameFieldMustBeCoalesced, so checking
    // entries individually here avoids double-reporting it.
    // @see \Drupal\canvas\PropExpressions\StructuredData\Coalescer::expand()
    foreach ($value as $expression_string) {
      if (!\is_string($expression_string)) {
        continue;
      }
      try {
        $normalized = Coalescer::coalesce([$expression_string]);
        $round_tripped = Coalescer::coalesce(Coalescer::expand([$expression_string]));
      }
      catch (\Throwable) {
        // Invalid expressions are handled by ValidStructuredDataPropExpression.
        continue;
      }
      \sort($normalized);
      \sort($round_tripped);
      if ($normalized === $round_tripped) {
        continue;
      }
      $this->context->addViolation($constraint->message, [
        '@expression' => $expression_string,
        '@normalized' => \implode(', ', $round_tripped),
      ]);
    }
  }

}
