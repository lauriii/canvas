<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Validation\Constraint;

use Drupal\canvas\Entity\Color;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

/**
 * Validates the UniqueColorNameConstraint constraint.
 *
 * @internal
 */
final class UniqueColorNameConstraintValidator extends ConstraintValidator {

  use UniqueColorConstraintValidationTrait;

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $value, Constraint $constraint): void {
    if (!$constraint instanceof UniqueColorNameConstraint) {
      throw new UnexpectedTypeException($constraint, UniqueColorNameConstraint::class);
    }

    $this->validateUniqueColorValue($value, $constraint);
  }

  /**
   * {@inheritdoc}
   */
  protected function getColorFieldValue(Color $color): string {
    return $color->getName();
  }

  /**
   * {@inheritdoc}
   */
  protected function normalizeColorValue(string $value): string {
    return mb_strtolower(trim($value));
  }

}
