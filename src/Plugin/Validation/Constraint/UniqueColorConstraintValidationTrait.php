<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Validation\Constraint;

use Drupal\canvas\Entity\Color;
use Drupal\Core\Config\Schema\TypeResolver;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

/**
 * Shared validation logic for Color uniqueness constraints.
 *
 * @internal
 */
trait UniqueColorConstraintValidationTrait {

  /**
   * Validates that a value is unique across Color entities.
   *
   * @phpstan-param UniqueColorNameConstraint|UniqueColorCssVariableConstraint $constraint
   */
  protected function validateUniqueColorValue(mixed $value, Constraint $constraint): void {
    if (!\is_string($value)) {
      throw new UnexpectedValueException($value, 'string');
    }

    // @phpstan-ignore argument.type
    $id = TypeResolver::resolveDynamicTypeName("[$constraint->id]", $this->context->getObject());
    $normalized_value = $this->normalizeColorValue($value);

    $colors = Color::loadMultiple();
    foreach ($colors as $color) {
      if ($color->id() === $id) {
        continue;
      }

      if ($this->normalizeColorValue($this->getColorFieldValue($color)) === $normalized_value) {
        $this->context->addViolation($constraint->notUnique, [
          '%value' => $value,
        ]);
        return;
      }
    }
  }

  /**
   * Gets the Color field value to compare.
   */
  abstract protected function getColorFieldValue(Color $color): string;

  /**
   * Normalizes a Color field value before comparison.
   */
  protected function normalizeColorValue(string $value): string {
    return $value;
  }

}
