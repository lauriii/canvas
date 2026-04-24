<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * Checks all entity field expressions target the same entity type+bundle.
 */
#[Constraint(
  id: "EntityFieldExpressionsSameTarget",
  label: new TranslatableMarkup("Entity field expressions must target the same entity type and bundle.", [], ['context' => 'Validation']),
  type: "sequence",
)]
final class EntityFieldExpressionsSameTargetConstraint extends SymfonyConstraint {

  /**
   * The error message.
   */
  public string $message = 'All entity field expressions must target the same entity type and bundle, but found: @types.';

}
