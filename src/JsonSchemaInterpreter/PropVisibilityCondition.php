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
 * or all must pass. The `not` key takes a condition that must not pass.
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
    $messages = [];
    $references = [];
    foreach ($props as $prop_name => $prop) {
      if (!\array_key_exists(self::KEY, $prop)) {
        continue;
      }
      $condition = $prop[self::KEY];
      $prefix = \sprintf('Prop "%s": `%s`', $prop_name, self::KEY);
      $errors = self::validateCondition($condition, \array_keys($props), $prop_name);
      foreach ($errors as $error) {
        $messages[] = "$prefix $error";
      }
      if (empty($errors)) {
        $references[$prop_name] = self::referencedProps($condition);
      }
    }
    // A prop whose visibility depends on itself, through other props, could
    // never be shown.
    foreach (\array_keys($references) as $prop_name) {
      if (self::dependsOn($prop_name, $prop_name, $references, [])) {
        $messages[] = \sprintf('Prop "%s": `%s` depends on itself, through other props.', $prop_name, self::KEY);
      }
    }
    return $messages;
  }

  /**
   * @param mixed $condition
   * @param string[] $prop_names
   * @param string $own_prop_name
   *
   * @return string[]
   */
  private static function validateCondition(mixed $condition, array $prop_names, string $own_prop_name): array {
    if (!\is_array($condition) || $condition === [] || array_is_list($condition)) {
      return ['must be a non-empty map of prop names to tests.'];
    }
    $errors = [];
    foreach ($condition as $key => $test) {
      $key = (string) $key;
      if ($key === 'anyOf' || $key === 'allOf') {
        if (!\is_array($test) || $test === [] || !array_is_list($test)) {
          $errors[] = "`$key` must be a non-empty list of conditions.";
          continue;
        }
        foreach ($test as $sub_condition) {
          $errors = [...$errors, ...self::validateCondition($sub_condition, $prop_names, $own_prop_name)];
        }
      }
      elseif ($key === 'not') {
        $errors = [...$errors, ...self::validateCondition($test, $prop_names, $own_prop_name)];
      }
      elseif ($key === $own_prop_name) {
        $errors[] = "cannot depend on the prop itself.";
      }
      elseif (!\in_array($key, $prop_names, TRUE)) {
        $errors[] = "refers to \"$key\", which is not a prop of this component.";
      }
      else {
        $errors = [...$errors, ...self::validateTest($key, $test)];
      }
    }
    return $errors;
  }

  /**
   * @return string[]
   */
  private static function validateTest(string $prop_name, mixed $test): array {
    if (self::isScalarOrScalarList($test)) {
      return [];
    }
    if (!\is_array($test) || array_is_list($test)) {
      return ["the test for \"$prop_name\" must be a value, a list of values or a map of operators."];
    }
    $errors = [];
    foreach ($test as $operator => $operand) {
      $valid = match ($operator) {
        'not' => self::isScalarOrScalarList($operand),
        'gt', 'gte', 'lt', 'lte' => \is_int($operand) || \is_float($operand),
        'contains' => \is_scalar($operand),
        'filled' => \is_bool($operand),
        default => NULL,
      };
      if ($valid === NULL) {
        $errors[] = \sprintf('uses the unknown operator "%s" for "%s". Use one of: %s.', $operator, $prop_name, implode(', ', self::OPERATORS));
      }
      elseif (!$valid) {
        $errors[] = \sprintf('has an invalid value for "%s" on "%s".', $operator, $prop_name);
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
   * @param array<string, string[]> $references
   * @param string[] $visited
   */
  private static function dependsOn(string $prop_name, string $target, array $references, array $visited): bool {
    foreach ($references[$prop_name] ?? [] as $referenced) {
      if ($referenced === $target) {
        return TRUE;
      }
      if (\in_array($referenced, $visited, TRUE)) {
        continue;
      }
      if (self::dependsOn($referenced, $target, $references, [...$visited, $referenced])) {
        return TRUE;
      }
    }
    return FALSE;
  }

}
