<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Unit\JsonSchemaInterpreter;

use Drupal\canvas\JsonSchemaInterpreter\PropVisibilityCondition;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the validation of `x-canvas-visible-if` conditions.
 */
#[CoversClass(PropVisibilityCondition::class)]
#[Group('canvas')]
final class PropVisibilityConditionTest extends UnitTestCase {

  /**
   * @param mixed $condition
   *   The condition of the `label` prop.
   * @param string[] $expected_messages
   */
  #[DataProvider('providerConditions')]
  public function testValidateProps(mixed $condition, array $expected_messages): void {
    $props = [
      'show' => ['type' => 'boolean'],
      'count' => ['type' => 'integer'],
      'label' => ['type' => 'string', PropVisibilityCondition::KEY => $condition],
    ];
    self::assertSame($expected_messages, PropVisibilityCondition::validateProps($props));
  }

  public static function providerConditions(): \Generator {
    $prefix = 'Prop "label": `x-canvas-visible-if` ';
    yield 'value' => [['show' => TRUE], []];
    yield 'list of values' => [['count' => [1, 2]], []];
    yield 'operators' => [['count' => ['gte' => 2, 'lt' => 5, 'not' => 3]], []];
    yield 'filled' => [['count' => ['filled' => TRUE]], []];
    yield 'combinations' => [
      ['anyOf' => [['show' => TRUE], ['not' => ['count' => 1]]], 'allOf' => [['count' => ['gt' => 0]]]],
      [],
    ];
    yield 'not a map' => [TRUE, [$prefix . 'must be a non-empty map of prop names to tests.']];
    yield 'empty' => [[], [$prefix . 'must be a non-empty map of prop names to tests.']];
    yield 'list' => [[['show' => TRUE]], [$prefix . 'must be a non-empty map of prop names to tests.']];
    yield 'unknown prop' => [['shown' => TRUE], [$prefix . 'refers to "shown", which is not a prop of this component.']];
    yield 'itself' => [['label' => 'x'], [$prefix . 'cannot depend on the prop itself.']];
    yield 'unknown operator' => [
      ['count' => ['above' => 2]],
      [$prefix . 'uses the unknown operator "above" for "count". Use one of: not, gt, gte, lt, lte, contains, filled.'],
    ];
    yield 'invalid operand' => [['count' => ['gte' => 'two']], [$prefix . 'has an invalid value for "gte" on "count".']];
    yield 'invalid anyOf' => [['anyOf' => ['show' => TRUE]], [$prefix . '`anyOf` must be a non-empty list of conditions.']];
    yield 'nested error' => [['anyOf' => [['nope' => 1]]], [$prefix . 'refers to "nope", which is not a prop of this component.']];
  }

  public function testCycle(): void {
    $props = [
      'a' => ['type' => 'boolean', PropVisibilityCondition::KEY => ['b' => TRUE]],
      'b' => ['type' => 'boolean', PropVisibilityCondition::KEY => ['anyOf' => [['a' => TRUE]]]],
      'c' => ['type' => 'boolean', PropVisibilityCondition::KEY => ['a' => TRUE]],
    ];
    self::assertSame([
      'Prop "a": `x-canvas-visible-if` depends on itself, through other props.',
      'Prop "b": `x-canvas-visible-if` depends on itself, through other props.',
    ], PropVisibilityCondition::validateProps($props));
  }

}
