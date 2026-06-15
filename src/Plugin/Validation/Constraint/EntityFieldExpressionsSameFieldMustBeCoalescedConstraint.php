<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * Checks same-host same-field expressions are coalesced into one entry.
 *
 * Enforces the "one entry per host entity field" invariant on the per-prop
 * `dataDependencies.entityFields.<prop>` sequence: multiple FieldPropExpression
 * or FieldObjectPropsExpression entries targeting the same host entity type,
 * field name and delta must be coalesced into a single
 * FieldObjectPropsExpression.
 */
#[Constraint(
  id: self::PLUGIN_ID,
  label: new TranslatableMarkup("Same-field entity-field expressions must be coalesced.", [], ['context' => 'Validation']),
  type: "sequence",
)]
final class EntityFieldExpressionsSameFieldMustBeCoalescedConstraint extends SymfonyConstraint {

  public const string PLUGIN_ID = 'EntityFieldExpressionsSameFieldMustBeCoalesced';

  /**
   * The error message.
   */
  public string $message = "Multiple expressions on the same field '@field' must be coalesced into a single FieldObjectPropsExpression.";

}
