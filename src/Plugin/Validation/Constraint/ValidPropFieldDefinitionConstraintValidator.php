<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Validation\Constraint;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

/**
 * Validates a prop field definition is either scalar or composite.
 */
final class ValidPropFieldDefinitionConstraintValidator extends ConstraintValidator {

  /**
   * The keys that only scalar prop field definitions may (and must) have.
   */
  private const SCALAR_ONLY_KEYS = [
    'field_type',
    'field_widget',
    'expression',
    'default_value',
  ];

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $value, Constraint $constraint): void {
    if (!$constraint instanceof ValidPropFieldDefinitionConstraint) {
      throw new UnexpectedTypeException($constraint, ValidPropFieldDefinitionConstraint::class);
    }
    if ($value === NULL) {
      return;
    }
    if (!\is_array($value)) {
      throw new UnexpectedValueException($value, 'array');
    }

    if (\array_key_exists('sub_definitions', $value)) {
      if (!\is_array($value['sub_definitions']) || $value['sub_definitions'] === []) {
        $this->context->addViolation($constraint->emptySubDefinitionsMessage);
      }
      foreach (self::SCALAR_ONLY_KEYS as $key) {
        if (\array_key_exists($key, $value)) {
          $this->context->addViolation($constraint->extraneousCompositeKeyMessage, ['%key' => $key]);
        }
      }
      return;
    }

    foreach (self::SCALAR_ONLY_KEYS as $key) {
      if (!\array_key_exists($key, $value)) {
        $this->context->addViolation($constraint->missingScalarKeyMessage, ['%key' => $key]);
      }
    }
  }

}
