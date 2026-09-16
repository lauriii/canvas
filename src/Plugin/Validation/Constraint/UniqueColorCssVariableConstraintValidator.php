<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Validation\Constraint;

use Drupal\canvas\Entity\Color;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

/**
 * Validates the UniqueColorCssVariableConstraint constraint.
 *
 * @internal
 */
final class UniqueColorCssVariableConstraintValidator extends ConstraintValidator {

  use UniqueColorConstraintValidationTrait;

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $value, Constraint $constraint): void {
    if (!$constraint instanceof UniqueColorCssVariableConstraint) {
      throw new UnexpectedTypeException($constraint, UniqueColorCssVariableConstraint::class);
    }

    $this->validateUniqueColorValue($value, $constraint);
  }

  /**
   * {@inheritdoc}
   */
  protected function getColorFieldValue(Color $color): string {
    return $color->getCssVariable();
  }

}
