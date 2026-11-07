<?php

declare(strict_types=1);

namespace Drupal\canvas\JsonSchemaInterpreter;

/**
 * Validates `x-canvas-visible-if`: when a prop is shown in the component form.
 *
 * A condition is a map. Each key is the name of another prop, mapped to a test
 * of its value. All of them must pass:
 * - a scalar: the value equals it
 * - a list of scalars: the value equals one of them
 * - a map of operators, which must all pass:
 *   - `not`: a scalar or list of scalars, as above, that must not match
 *   - `gt`, `gte`, `lt`, `lte`: a number to compare the value with
 *   - `contains`: a scalar that the value (a string or list) contains
 *   - `filled`: TRUE if the value must be set, FALSE if it must be empty
 *
 * The `anyOf` and `allOf` keys take a list of conditions, of which at least one
 * or all must pass. The `not` key takes a condition that must not pass. These
 * keys are reserved: a component with conditions cannot have props with these
 * names.
 *
 * Values compare loosely, so `2` matches `'2'`. `contains` is case-sensitive.
 * Tests must fit the prop they test: values of enums must be allowed values,
 * booleans can only be compared with `true` and `false`, `gt`, `gte`, `lt`
 * and `lte` need a number prop, and `contains` a string or array prop.
 *
 * Hidden props count as empty in the conditions of other props. Visibility only
 * affects the component instance form: values of hidden props are kept and
 * still passed to the component.
 *
 * @code
 * x-canvas-visible-if:
 *   show_button: true
 *   button_count: { gte: 2 }
 * @endcode
 *
 * @see ui/src/utils/propVisibility.ts
 * @see \Drupal\canvas\ComponentMetadataRequirementsChecker::check()
 */
final class PropVisibilityCondition {

  public const string KEY = 'x-canvas-visible-if';

  public const array RESERVED_KEYS = ['anyOf', 'allOf', 'not'];

  private const array OPERATORS = ['not', 'gt', 'gte', 'lt', 'lte', 'contains', 'filled'];

  /**
   * Validates the conditions of all props of a component.
   *
   * @param array<string, array<string, mixed>> $props
   *   The props of a component, keyed by prop name.
   *
   * @return string[]
   *   Error messages. Empty if all conditions are valid.
   */
  public static function validateProps(array $props): array {
    $conditions = \array_filter(\array_map(static fn (array $prop): mixed => $prop[self::KEY] ?? NULL, $props), static fn (mixed $condition): bool => $condition !== NULL);
    if (empty($conditions)) {
      return [];
    }
    $messages = [];
    foreach (\array_intersect(self::RESERVED_KEYS, \array_keys($props)) as $reserved) {
      $messages[] = \sprintf('Prop "%s": this name is reserved in `%s` conditions. Rename the prop.', $reserved, self::KEY);
    }
    $references = [];
    foreach ($conditions as $prop_name => $condition) {
      $errors = self::validateCondition($condition, $props, (string) $prop_name, '');
      foreach ($errors as $error) {
        $messages[] = \sprintf('Prop "%s": `%s`%s', $prop_name, self::KEY, $error);
      }
      if (empty($errors)) {
        $references[(string) $prop_name] = self::referencedProps($condition);
      }
    }
    // A prop whose visibility depends on itself, through other props, could
    // never be shown.
    foreach (\array_keys($references) as $prop_name) {
      $chain = self::findCycle($prop_name, $references, [$prop_name]);
      if ($chain !== NULL) {
        $messages[] = \sprintf('Prop "%s": `%s` depends on itself: %s. Remove one of these conditions.', $prop_name, self::KEY, implode(' → ', $chain));
      }
    }
    return $messages;
  }

  /**
   * @param mixed $condition
   * @param array<string, array<string, mixed>> $props
   * @param string $own_prop_name
   * @param string $path
   *   Where the condition is, such as `.anyOf[1]`, for error messages.
   *
   * @return string[]
   */
  private static function validateCondition(mixed $condition, array $props, string $own_prop_name, string $path): array {
    if (!\is_array($condition) || $condition === [] || array_is_list($condition)) {
      return ["$path must be a map of prop names to tests, such as `show_button: true`."];
    }
    $errors = [];
    foreach ($condition as $key => $test) {
      $key = (string) $key;
      if ($key === 'anyOf' || $key === 'allOf') {
        if (!\is_array($test) || $test === [] || !array_is_list($test)) {
          $errors[] = "$path.$key must be a list of conditions.";
          continue;
        }
        foreach ($test as $delta => $sub_condition) {
          $sub_path = "$path.{$key}[$delta]";
          $errors = [...$errors, ...self::validateCondition($sub_condition, $props, $own_prop_name, $sub_path)];
        }
      }
      elseif ($key === 'not') {
        $errors = [...$errors, ...self::validateCondition($test, $props, $own_prop_name, "$path.not")];
      }
      elseif ($key === $own_prop_name) {
        $errors[] = "$path cannot depend on the prop itself.";
      }
      elseif (!\array_key_exists($key, $props)) {
        $errors[] = "$path refers to \"$key\", which is not a prop of this component.";
      }
      else {
        $errors = [...$errors, ...self::validateTest($key, $test, $props[$key], "$path.$key")];
      }
    }
    return $errors;
  }

  /**
   * Validates a test of a prop's value against the prop's schema.
   *
   * @return string[]
   */
  private static function validateTest(string $prop_name, mixed $test, array $prop_schema, string $path): array {
    $type = \strtolower((string) (((array) ($prop_schema['type'] ?? ''))[0] ?? ''));
    $enum = $prop_schema['enum'] ?? $prop_schema['items']['enum'] ?? NULL;
    if (self::isScalarOrScalarList($test)) {
      return self::validateValues($prop_name, (array) $test, $type, $enum, $path);
    }
    if (!\is_array($test) || array_is_list($test)) {
      return ["$path must be a value, a list of values or a map of operators, such as `{ gte: 2 }`."];
    }
    $errors = [];
    foreach ($test as $operator => $operand) {
      $operator = (string) $operator;
      $error = match ($operator) {
        'not' => self::isScalarOrScalarList($operand) ? NULL : 'must be a value or a list of values',
        'gt', 'gte', 'lt', 'lte' => match (TRUE) {
          !\in_array($type, ['integer', 'number'], TRUE) => "needs a number prop, but \"$prop_name\" has type $type",
          !\is_int($operand) && !\is_float($operand) => 'must be a number',
          default => NULL,
        },
        'contains' => match (TRUE) {
          !\in_array($type, ['string', 'array'], TRUE) => "needs a text or list prop, but \"$prop_name\" has type $type",
          !\is_scalar($operand) => 'must be a value',
          default => NULL,
        },
        'filled' => \is_bool($operand) ? NULL : 'must be true or false',
        default => \sprintf('is not an operator. Use one of: %s', implode(', ', self::OPERATORS)),
      };
      if ($error !== NULL) {
        $errors[] = "$path.$operator $error.";
      }
      elseif ($operator === 'not') {
        $errors = [...$errors, ...self::validateValues($prop_name, (array) $operand, $type, $enum, "$path.not")];
      }
    }
    return $errors;
  }

  /**
   * Validates values that a prop's value is compared with.
   *
   * @param array<mixed> $values
   *
   * @return string[]
   */
  private static function validateValues(string $prop_name, array $values, string $type, ?array $enum, string $path): array {
    $errors = [];
    foreach ($values as $value) {
      if ($type === 'boolean' && !\is_bool($value)) {
        $errors[] = \sprintf('%s compares the true/false prop "%s" with %s. Use true or false.', $path, $prop_name, \json_encode($value));
      }
      elseif ($enum !== NULL && !\in_array((string) $value, \array_map('strval', $enum), TRUE)) {
        $errors[] = \sprintf('%s compares "%s" with %s, which is not one of its values: %s.', $path, $prop_name, \json_encode($value), implode(', ', \array_map('json_encode', $enum)));
      }
    }
    return $errors;
  }

  private static function isScalarOrScalarList(mixed $value): bool {
    if (\is_scalar($value)) {
      return TRUE;
    }
    return \is_array($value) && $value !== [] && array_is_list($value) && \count(\array_filter($value, 'is_scalar')) === \count($value);
  }

  /**
   * @return string[]
   */
  private static function referencedProps(array $condition): array {
    $props = [];
    foreach ($condition as $key => $test) {
      $props = match ((string) $key) {
        'anyOf', 'allOf' => [
          ...$props,
          ...\array_merge(...\array_map(self::referencedProps(...), $test)),
        ],
        'not' => [...$props, ...self::referencedProps($test)],
        default => [...$props, (string) $key],
      };
    }
    return $props;
  }

  /**
   * Finds a chain of conditions that leads back to the first prop.
   *
   * @param string $prop_name
   * @param array<string, string[]> $references
   * @param string[] $chain
   *
   * @return string[]|null
   *   The chain, starting and ending with the first prop, or NULL.
   */
  private static function findCycle(string $prop_name, array $references, array $chain): ?array {
    foreach ($references[$prop_name] ?? [] as $referenced) {
      if ($referenced === $chain[0]) {
        return [...$chain, $referenced];
      }
      if (\in_array($referenced, $chain, TRUE)) {
        continue;
      }
      $found = self::findCycle($referenced, $references, [...$chain, $referenced]);
      if ($found !== NULL) {
        return $found;
      }
    }
    return NULL;
  }

}
