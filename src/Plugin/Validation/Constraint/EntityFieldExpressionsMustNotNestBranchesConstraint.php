<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * Rejects entity-field selections that would coalesce into a nested branch.
 *
 * Descending through the same multi-bundle reference field more than once would
 * coalesce into a branch whose value is itself a multi-bundle reference — a
 * branch inside a branch. The string representation cannot express that and the
 * parser rejects it, so nested branching is not yet supported and such
 * selections are rejected.
 *
 * @todo Remove this constraint (and its validator) once nested branching is supported, in https://git.drupalcode.org/project/canvas/-/work_items/3591865
 */
#[Constraint(
  id: self::PLUGIN_ID,
  label: new TranslatableMarkup("Entity-field selections must not coalesce into a nested branch.", [], ['context' => 'Validation']),
  type: "sequence",
)]
final class EntityFieldExpressionsMustNotNestBranchesConstraint extends SymfonyConstraint {

  public const string PLUGIN_ID = 'EntityFieldExpressionsMustNotNestBranches';

  /**
   * The error message.
   */
  public string $message = "The expressions on field '@field' descend through a multi-bundle reference more than once, which is not yet supported.";

}
