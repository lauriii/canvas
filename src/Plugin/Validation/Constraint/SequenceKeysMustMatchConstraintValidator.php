<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Validation\Constraint;

use Drupal\Core\Config\Schema\Mapping;
use Drupal\Core\TypedData\PrimitiveInterface;
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

    $filter_callback = NULL;
    if ($constraint->conditions !== NULL) {
      $filter_callback = fn (mixed $mapping) => self::filter($mapping, $constraint);
    }
    $expected_sequence_keys = $this->getSequenceKeys($constraint, $filter_callback);

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

  /**
   * Filter callback for applying `$constraint->conditions` to target elements.
   *
   * When `$constraint->conditions` is a list of condition sets, an element
   * passes the filter if at least one of the sets matches.
   *
   * @see \Drupal\canvas\Plugin\Validation\Constraint\SequenceKeysMustMatchConstraint::$conditions
   */
  private static function filter(mixed $mapping, SequenceKeysMustMatchConstraint $constraint): bool {
    \assert($constraint->conditions !== NULL && \count($constraint->conditions) >= 1);

    if (!$mapping instanceof Mapping) {
      throw new \LogicException('SequenceKeysMustMatchConstraint conditions can only target sequences containing mappings.');
    }

    $condition_sets = \array_is_list($constraint->conditions) ? $constraint->conditions : [$constraint->conditions];
    return \array_any($condition_sets, fn (array $conditions): bool => self::matches($mapping, $conditions));
  }

  /**
   * Checks whether a mapping meets all conditions of a single condition set.
   *
   * A condition key can be a dotted path, such as `items.$ref`. Such a key
   * targets a nested mapping. The conditions are not met when a parent of the
   * nested key is absent or is not a mapping.
   *
   * @param \Drupal\Core\Config\Schema\Mapping $mapping
   *   The mapping to check.
   * @param array<string, scalar|null> $conditions
   *   A single condition set: condition keys with their expected primitive
   *   values.
   *
   * @return bool
   *   TRUE if the mapping meets all conditions, FALSE otherwise.
   */
  private static function matches(Mapping $mapping, array $conditions): bool {
    foreach ($conditions as $expected_key => $expected_value) {
      $parents = \explode('.', (string) $expected_key);
      $key = \array_pop($parents);

      $target = self::getNestedMapping($mapping, $parents);
      if ($target === NULL) {
        return FALSE;
      }

      if (!\in_array($key, $target->getValidKeys(), TRUE)) {
        // A nested mapping can lack the key. An unknown top-level key is an
        // error in the condition itself.
        if ($parents !== []) {
          return FALSE;
        }
        throw new \LogicException(\sprintf('Condition key "%s" is not a valid key of the target sequence item.', $expected_key));
      }

      $elements = $target->getElements();
      if (!\array_key_exists($key, $elements)) {
        return FALSE;
      }
      if (!$elements[$key] instanceof PrimitiveInterface) {
        throw new \LogicException('Only primitive values can be used in SequenceKeysMustMatchConstraint conditions.');
      }
      if ($elements[$key]->getCastedValue() !== $expected_value) {
        return FALSE;
      }
    }

    // All conditions were met.
    return TRUE;
  }

  /**
   * Walks down nested mappings.
   *
   * @param \Drupal\Core\Config\Schema\Mapping $mapping
   *   The mapping to start from.
   * @param string[] $parents
   *   The keys to follow, from the outermost to the innermost.
   *
   * @return \Drupal\Core\Config\Schema\Mapping|null
   *   The innermost mapping, or NULL if a parent is absent or is not a mapping.
   */
  private static function getNestedMapping(Mapping $mapping, array $parents): ?Mapping {
    foreach ($parents as $parent) {
      $mapping = $mapping->getElements()[$parent] ?? NULL;
      if (!$mapping instanceof Mapping) {
        return NULL;
      }
    }
    return $mapping;
  }

}
