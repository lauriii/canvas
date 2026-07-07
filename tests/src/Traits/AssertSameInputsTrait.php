<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Traits;

/**
 * Asserts component instance inputs order-independently.
 */
trait AssertSameInputsTrait {

  /**
   * Asserts component instance inputs are identical, ignoring input key order.
   *
   * The `inputs` field property is stored in a JSON column whose object key
   * order is not preserved by every database backend: MySQL `json` and
   * PostgreSQL `jsonb` are native binary types that reorder keys, while MariaDB
   * (where `json` is a `LONGTEXT` alias) and SQLite (which stores `json` as
   * `TEXT`) keep the source order.
   *
   * As of Drupal 11.4, trusted-data saves also sort stored mappings to their
   * config schema's key order.
   *
   * Input key order is not semantically meaningful, so comparing the result of
   * ComponentTreeItem::getInputs() with the order-sensitive assertSame() produces
   * tests that pass on some database engines and fail on others — and even
   * intermittently.
   *
   * Canonicalizes the key order with ksort() and then compares strictly: unlike
   * assertEqualsCanonicalizing() (which sorts by value, so a key↔value mix-up
   * would slip through), this keeps each key bound to its value and preserves
   * the strict type check of assertSame().
   *
   * @param array $expected
   *   The expected component instance inputs.
   * @param array|null $actual
   *   The actual component instance inputs, e.g. from
   *   ComponentTreeItem::getInputs().
   *
   * @see \Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItem::getInputs()
   * @see \Canvas\PHPStan\Rules\ComponentInputsComparisonMustIgnoreKeyOrderRule
   * @see https://dev.mysql.com/doc/refman/8.0/en/json.html
   * @see https://www.postgresql.org/docs/current/datatype-json.html
   * @see https://www.drupal.org/node/3348180
   */
  protected static function assertSameInputs(array $expected, ?array $actual): void {
    self::assertIsArray($actual);
    \ksort($expected);
    \ksort($actual);
    self::assertSame($expected, $actual);
  }

}
