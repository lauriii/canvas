<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Validation\Constraint;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

final class UriTargetFileExtensionsConstraintValidator extends ConstraintValidator {

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $value, Constraint $constraint): void {
    if (!$constraint instanceof UriTargetFileExtensionsConstraint) {
      throw new UnexpectedTypeException($constraint, __NAMESPACE__ . '\UriTargetFileExtensionsConstraint');
    }

    // No-op.
  }

}
