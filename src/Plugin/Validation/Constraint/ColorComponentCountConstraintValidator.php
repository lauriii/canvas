<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Validation\Constraint;

use Drupal\Core\TypedData\TypedDataInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

/**
 * Validates the ColorComponentCount constraint.
 *
 * @internal
 */
final class ColorComponentCountConstraintValidator extends ConstraintValidator {

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $value, Constraint $constraint): void {
    if (!$constraint instanceof ColorComponentCountConstraint) {
      throw new UnexpectedTypeException($constraint, ColorComponentCountConstraint::class);
    }

    if (!\is_array($value)) {
      return;
    }

    // Retrieve the parent mapping value (i.e. canvas.color.*:value) so we can
    // read the sibling 'colorSpace' key without hard-coding a property path.
    $object = $this->context->getObject();
    if (!$object instanceof TypedDataInterface) {
      return;
    }
    $parent = $object->getParent();
    if (!$parent instanceof TypedDataInterface) {
      return;
    }
    $parent_value = $parent->getValue();
    $colorSpace = $parent_value['colorSpace'] ?? NULL;

    if (!\is_string($colorSpace)) {
      return;
    }

    // Unknown color spaces are intentionally skipped: this allows future color
    // spaces to be introduced without requiring an update to this constraint.
    if (!\array_key_exists($colorSpace, ColorComponentCountConstraint::COMPONENT_COUNTS)) {
      return;
    }

    $expected = ColorComponentCountConstraint::COMPONENT_COUNTS[$colorSpace];
    $actual = \count($value);

    if ($actual !== $expected) {
      $this->context->buildViolation($constraint->message)
        ->setParameter('%colorSpace', $colorSpace)
        ->setParameter('%expected', (string) $expected)
        ->setParameter('%actual', (string) $actual)
        ->addViolation();
    }
  }

}
