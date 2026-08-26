<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Validation\Constraint;

use Drupal\canvas\Entity\Color;
use Drupal\Core\Config\Schema\TypeResolver;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

/**
 * Validates the UniqueColorCssVariableConstraint constraint.
 *
 * @internal
 */
final class UniqueColorCssVariableConstraintValidator extends ConstraintValidator {

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $value, Constraint $constraint): void {
    if (!$constraint instanceof UniqueColorCssVariableConstraint) {
      throw new UnexpectedTypeException($constraint, UnexpectedTypeException::class);
    }

    if (!\is_string($value)) {
      throw new UnexpectedValueException($value, 'string');
    }

    // @phpstan-ignore argument.type
    $id = TypeResolver::resolveDynamicTypeName("[$constraint->id]", $this->context->getObject());

    // Load all colors and check for duplicate CSS variable.
    $colors = Color::loadMultiple();
    foreach ($colors as $color) {
      if ($color->getCssVariable() === $value && $color->id() !== $id) {
        $this->context->addViolation($constraint->notUnique, [
          '%value' => $value,
        ]);
        return;
      }
    }
  }

}
