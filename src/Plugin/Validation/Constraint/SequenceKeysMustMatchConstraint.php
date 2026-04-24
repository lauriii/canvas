<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Validation\Constraint;

use Drupal\Core\Validation\Attribute\Constraint;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Checks the validated sequence's keys against another sequence's keys.
 *
 * The comparison mode is controlled by $matchType:
 * - 'same-set' (default): the validated sequence must contain the exact same
 *   keys as the target sequence.
 * - 'subset': the validated sequence's keys must all exist in the target
 *   sequence, but missing keys are allowed.
 *
 * @see \Drupal\Core\Validation\Plugin\Validation\Constraint\SequenceKeyExistsConstraint
 */
#[Constraint(
  id: "SequenceKeysMustMatch",
  label: new TranslatableMarkup("Sequence keys must match.", [], ['context' => 'Validation']),
  type: "sequence",
)]
final class SequenceKeysMustMatchConstraint extends SequenceDependentConstraintBase {

  public const MATCH_TYPE_SAME_SET = 'same-set';
  public const MATCH_TYPE_SUBSET = 'subset';

  /**
   * How the validated sequence's keys must relate to the target sequence.
   */
  public string $matchType = self::MATCH_TYPE_SAME_SET;

}
