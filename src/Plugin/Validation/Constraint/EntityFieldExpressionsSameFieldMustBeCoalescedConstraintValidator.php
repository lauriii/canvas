<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Validation\Constraint;

use Drupal\canvas\PropExpressions\StructuredData\FieldObjectPropsExpression;
use Drupal\canvas\PropExpressions\StructuredData\FieldPropExpression;
use Drupal\canvas\PropExpressions\StructuredData\ReferenceFieldPropExpression;
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

    // Group expressions by starting point and report one violation per group
    // that contains at least two expressions.
    //
    // Two flavors of grouping:
    // - Loose FieldPropExpression / FieldObjectPropsExpression: bucketed by
    //   `(host, field, delta)`. JavaScriptComponent::coalesceEntityFields()
    //   coalesces these into a single FieldObjectPropsExpression at save time;
    //   only same-property collisions reach the validator.
    // - ReferenceFieldPropExpression (single-bundle): bucketed by
    //   `<full reference chain>|<final-target host|field|delta>`. The
    //   coalescing wraps them into a single ReferenceFieldPropExpression with a
    //   FieldObjectPropsExpression target; only true reference-chain
    //   duplicates on the same final field reach the validator.
    //
    // Two ReferenceFieldPropExpressions starting on the same referencer field
    // but targeting different final fields (e.g. `uid → user.name.value` and
    // `uid → user.mail.value`) remain legitimately separate — they become
    // distinct keys (`name` / `mail`) within the same nested object in
    // JsComponent::buildReferencePayload().
    /** @var array<string, list<FieldPropExpression|FieldObjectPropsExpression|ReferenceFieldPropExpression>> $buckets */
    $buckets = [];
    /** @var array<string, FieldPropExpression|FieldObjectPropsExpression> $loose_by_field */
    $loose_by_field = [];
    /** @var array<string, ReferenceFieldPropExpression> $refs_by_referencer_field */
    $refs_by_referencer_field = [];
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
      if ($parsed instanceof FieldPropExpression || $parsed instanceof FieldObjectPropsExpression) {
        $buckets[$parsed->getStartingPointKey()][] = $parsed;
        $loose_by_field[$parsed->getStartingPointKey()] ??= $parsed;
        continue;
      }
      if ($parsed instanceof ReferenceFieldPropExpression && !$parsed->targetsMultipleBundles()) {
        $final_target = $parsed->getFinalTargetExpression();
        $buckets[$parsed->getFullReferenceChain() . '|' . $final_target->getStartingPointKey()][] = $parsed;
        $refs_by_referencer_field[$parsed->getStartingPointKey()] ??= $parsed;
        continue;
      }
      if ($parsed instanceof ReferenceFieldPropExpression && $parsed->targetsMultipleBundles()) {
        $buckets[$parsed->getFullReferenceChain()][] = $parsed;
        $refs_by_referencer_field[$parsed->getStartingPointKey()] ??= $parsed;
        continue;
      }
    }

    // A loose expression and a reference descending through that same field
    // must also be coalesced — into a single FieldObjectPropsExpression whose
    // reference-derived entries follow the reference (`↝`) — because both key
    // the same payload entry in JsComponent::buildReferencePayload(), so
    // leaving them separate silently loses one of them.
    // @see \Drupal\canvas\PropExpressions\StructuredData\Coalescer::coalesce()
    foreach (\array_intersect_key($loose_by_field, $refs_by_referencer_field) as $loose) {
      $this->context->addViolation($constraint->message, [
        '@field' => \sprintf(
          '%s.%s',
          $loose->getHostEntityDataDefinition()->getDataType(),
          $loose->getFieldName(),
        ),
      ]);
    }

    foreach ($buckets as $bucket) {
      if (\count($bucket) < 2) {
        continue;
      }
      $first = $bucket[0];
      if ($first instanceof ReferenceFieldPropExpression && $first->targetsMultipleBundles()) {
        $field_owner = $first->referencer;
      }
      elseif ($first instanceof ReferenceFieldPropExpression) {
        $field_owner = $first->getFinalTargetExpression();
      }
      else {
        $field_owner = $first;
      }
      $this->context->addViolation($constraint->message, [
        '@field' => \sprintf(
          '%s.%s',
          $field_owner->getHostEntityDataDefinition()->getDataType(),
          $field_owner->getFieldName(),
        ),
      ]);
    }
  }

}
