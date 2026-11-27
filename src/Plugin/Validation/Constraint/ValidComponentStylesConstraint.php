<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * Validates the shape of a style selection, never its availability.
 *
 * @see \Drupal\canvas\Style\ComponentStyles::validateShape()
 */
#[Constraint(
  id: 'ValidComponentStyles',
  label: new TranslatableMarkup('Valid component styles', [], ['context' => 'Validation']),
)]
final class ValidComponentStylesConstraint extends SymfonyConstraint {
}
