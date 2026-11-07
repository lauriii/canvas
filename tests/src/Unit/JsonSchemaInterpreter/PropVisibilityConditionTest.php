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
      'layout' => ['type' => 'string', 'enum' => ['stack', 'split']],
      'columns' => ['type' => 'integer', 'enum' => [2, 3]],
      'tags' => ['type' => 'array', 'items' => ['type' => 'string']],
      'label' => ['type' => 'string', PropVisibilityCondition::KEY => $condition],
    ];
    self::assertSame($expected_messages, PropVisibilityCondition::validateProps($props));
  }

  public static function providerConditions(): \Generator {
    $prefix = 'Prop "label": `x-canvas-visible-if`';
    yield 'boolean' => [['show' => TRUE], []];
    yield 'list of values' => [['layout' => ['stack', 'split']], []];
    yield 'integer enum, loosely' => [['columns' => '3'], []];
    yield 'operators' => [['count' => ['gte' => 2, 'lt' => 5, 'not' => 3]], []];
    yield 'filled' => [['tags' => ['filled' => TRUE]], []];
    yield 'contains' => [['tags' => ['contains' => 'new']], []];
    yield 'not a test' => [['count' => [['gte' => 2]]], [$prefix . '.count must be a value, a list of values or a map of operators, such as `{ gte: 2 }`.']];
    yield 'combinations' => [
      ['anyOf' => [['show' => TRUE], ['not' => ['count' => 1]]], 'allOf' => [['count' => ['gt' => 0]]]],
      [],
    ];
    yield 'not a map' => [TRUE, [$prefix . ' must be a map of prop names to tests, such as `show_button: true`.']];
    yield 'list' => [[['show' => TRUE]], [$prefix . ' must be a map of prop names to tests, such as `show_button: true`.']];
    yield 'unknown prop' => [['shown' => TRUE], [$prefix . ' refers to "shown", which is not a prop of this component.']];
    yield 'unknown operator' => [
      ['count' => ['above' => 2]],
      [$prefix . '.count.above is not an operator. Use one of: not, gt, gte, lt, lte, contains, filled.'],
    ];
    yield 'not a number' => [['count' => ['gte' => 'two']], [$prefix . '.count.gte must be a number.']];
    yield 'number operator on text' => [['layout' => ['gt' => 1]], [$prefix . '.layout.gt needs a number prop, but "layout" has type string.']];
    yield 'contains on a number' => [['count' => ['contains' => 1]], [$prefix . '.count.contains needs a text or list prop, but "count" has type integer.']];
    yield 'boolean compared with text' => [['show' => 'yes'], [$prefix . '.show compares the true/false prop "show" with "yes". Use true or false.']];
    yield 'value not in enum' => [['layout' => 'splt'], [$prefix . '.layout compares "layout" with "splt", which is not one of its values: "stack", "split".']];
    yield 'not value not in enum' => [['layout' => ['not' => 'grid']], [$prefix . '.layout.not compares "layout" with "grid", which is not one of its values: "stack", "split".']];
    yield 'invalid anyOf' => [['anyOf' => ['show' => TRUE]], [$prefix . '.anyOf must be a list of conditions.']];
    yield 'nested error' => [['anyOf' => [['show' => TRUE], ['nope' => 1]]], [$prefix . '.anyOf[1] refers to "nope", which is not a prop of this component.']];
    yield 'not of a value' => [['not' => TRUE], [$prefix . '.not must be a map of prop names to tests, such as `show_button: true`.']];
  }

  public function testItself(): void {
    $props = ['label' => ['type' => 'string', PropVisibilityCondition::KEY => ['label' => 'x']]];
    self::assertSame(['Prop "label": `x-canvas-visible-if` cannot depend on the prop itself.'], PropVisibilityCondition::validateProps($props));
  }

  public function testCycle(): void {
    $props = [
      'a' => ['type' => 'boolean', PropVisibilityCondition::KEY => ['b' => TRUE]],
      'b' => ['type' => 'boolean', PropVisibilityCondition::KEY => ['anyOf' => [['a' => TRUE]]]],
      'c' => ['type' => 'boolean', PropVisibilityCondition::KEY => ['a' => TRUE]],
    ];
    self::assertSame([
      'Prop "a": `x-canvas-visible-if` depends on itself: a → b → a. Remove one of these conditions.',
      'Prop "b": `x-canvas-visible-if` depends on itself: b → a → b. Remove one of these conditions.',
    ], PropVisibilityCondition::validateProps($props));
  }

  public function testReservedNames(): void {
    $props = [
      'not' => ['type' => 'boolean'],
      'label' => ['type' => 'string', PropVisibilityCondition::KEY => ['not' => ['not' => TRUE]]],
    ];
    self::assertSame(['Prop "not": this name is reserved in `x-canvas-visible-if` conditions. Rename the prop.'], array_slice(PropVisibilityCondition::validateProps($props), 0, 1));
    // Without conditions, the names are allowed.
    unset($props['label'][PropVisibilityCondition::KEY]);
    self::assertSame([], PropVisibilityCondition::validateProps($props));
  }

}
