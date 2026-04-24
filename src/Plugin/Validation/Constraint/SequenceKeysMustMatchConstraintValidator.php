<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Validation\Constraint;

use Drupal\Core\Validation\Plugin\Validation\Constraint\ValidKeysConstraint;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

/**
 * Validates the SequenceKeysMustMatch constraint.
 */
final class SequenceKeysMustMatchConstraintValidator extends SequenceDependentConstraintValidatorBase {

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $value, Constraint $constraint): void {
    if (!\is_array($value)) {
      throw new UnexpectedTypeException($value, 'sequence');
    }
    if (!$constraint instanceof SequenceKeysMustMatchConstraint) {
      throw new UnexpectedTypeException($constraint, SequenceKeysMustMatchConstraint::class);
    }

    $expected_sequence_keys = $this->getSequenceKeys($constraint);

    $invalid_keys = array_diff(\array_keys($value), $expected_sequence_keys);
    $missing_keys = match ($constraint->matchType) {
      SequenceKeysMustMatchConstraint::MATCH_TYPE_SAME_SET => array_diff($expected_sequence_keys, \array_keys($value)),
      SequenceKeysMustMatchConstraint::MATCH_TYPE_SUBSET => [],
      default => throw new UnexpectedValueException($constraint->matchType, \sprintf('"%s" or "%s"', SequenceKeysMustMatchConstraint::MATCH_TYPE_SAME_SET, SequenceKeysMustMatchConstraint::MATCH_TYPE_SUBSET)),
    };

    if (empty($missing_keys) && empty($invalid_keys)) {
      return;
    }

    // Reuse the messages from the ValidKeysConstraint when missing or invalid
    // keys are found.
    $valid_keys_constraint = new ValidKeysConstraint([
      'allowedKeys' => '<infer>',
    ]);
    foreach ($missing_keys as $key) {
      $this->context->addViolation($valid_keys_constraint->missingRequiredKeyMessage, ['@key' => $key]);
    }
    foreach ($invalid_keys as $key) {
      $this->context->buildViolation($valid_keys_constraint->invalidKeyMessage)
        ->setParameter('@key', $key)
        ->atPath((string) $key)
        ->setInvalidValue($key)
        ->addViolation();
    }
  }

}
