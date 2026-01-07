<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Validation\Constraint;

use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * Validation constraint for comparing component prop requiredness.
 *
 * @see `type: canvas.generated_field_explicit_input_ux`
 * @see \Drupal\canvas\Plugin\Canvas\ComponentSource\GeneratedFieldExplicitInputUxComponentSourceBase
 */
#[Constraint(
  id: 'MatchesComponentPropRequiredness',
  type: ['bool']
)]
final class MatchesComponentPropRequirednessConstraint extends SymfonyConstraint {

  /**
   * The validation error message.
   *
   * @var string
   */
  public $message = 'The requiredness of the prop "%prop_title" (%prop_machine_name) must match its implementation.';

}
