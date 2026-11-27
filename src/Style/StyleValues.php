<?php

declare(strict_types=1);

namespace Drupal\canvas\Style;

use Drupal\canvas\Entity\Color;

/**
 * The value forms a stored style selection can take, besides option IDs.
 *
 * - **Custom values** (`=24px`): a number, and a unit the style allows.
 * - **Token references** (`canvas-color:<uuid>`): a brand kit color, the same
 *   reference color props store.
 *
 * Neither can collide with an option ID: `=` and `:` are outside core's option
 * ID pattern. Values are only ever rendered from parsed parts, never from the
 * stored string.
 *
 * @see docs/adr/0032-style-options-can-come-from-design-tokens.md
 *
 * @phpstan-type CustomRange array{min: ?float, max: ?float, step: ?float}
 * @phpstan-type CustomSpec array{type: 'length'|'number'|'integer'|'percentage', units: list<string>, min: ?float, max: ?float, step: ?float, ranges: array<string, CustomRange>, value: string, property: string}
 */
final class StyleValues {

  /**
   * A custom property name, as `property` declares it.
   */
  public const string CUSTOM_PROPERTY_PATTERN = '/^--[a-z0-9-]+$/';

  /**
   * The shape of any custom value, before a style's own rules.
   */
  private const string CUSTOM_PATTERN = '/^=(-?\d+(?:\.\d+)?)([a-z%]*)$/';

  /**
   * Pixels per unit at the default root font size. The editor uses these to
   * keep a value's size when switching units; bounds never depend on them.
   */
  public const array PIXELS_PER_UNIT = [
    'px' => 1.0,
    'rem' => 16.0,
    'em' => 16.0,
  ];

  public static function isCustom(string $value): bool {
    return str_starts_with($value, '=');
  }

  public static function isTokenReference(string $value): bool {
    return str_starts_with($value, Color::REFERENCE_PREFIX);
  }

  /**
   * Whether a stored value has a valid shape: an option ID, a custom value, or
   * a token reference. A style's own rules are checked later, leniently.
   */
  public static function hasValidShape(string $value): bool {
    return preg_match(ComponentStyles::OPTION_ID_PATTERN, $value) === 1
      || preg_match(self::CUSTOM_PATTERN, $value) === 1
      || preg_match('/^' . preg_quote(Color::REFERENCE_PREFIX, '/') . '[0-9a-f-]{36}$/', $value) === 1;
  }

  /**
   * Normalizes a `custom` declaration, or NULL when it is unusable.
   *
   * @param mixed $custom
   *   The raw declaration.
   *
   * @return CustomSpec|null
   */
  public static function normalizeCustom(mixed $custom): ?array {
    if (!\is_array($custom)) {
      return NULL;
    }
    $value = trim((string) ($custom['value'] ?? ''));
    $property = (string) ($custom['property'] ?? '');
    if ($value === '' || !preg_match(self::CUSTOM_PROPERTY_PATTERN, $property)) {
      return NULL;
    }
    $number = static fn (mixed $n): ?float => is_numeric($n) ? (float) $n : NULL;
    $bounds = static fn (mixed $b): array => [
      'min' => $number(\is_array($b) ? ($b['min'] ?? NULL) : NULL),
      'max' => $number(\is_array($b) ? ($b['max'] ?? NULL) : NULL),
      'step' => $number(\is_array($b) ? ($b['step'] ?? NULL) : NULL),
    ];
    // Lengths and percentages list their units, each with its own bounds:
    // 1 to 10 rem is not 1 to 10 px. Unitless numbers put their bounds at
    // the top level.
    $ranges = [];
    if (\is_array($custom['units'] ?? NULL) && $custom['units'] !== []) {
      foreach ($custom['units'] as $unit => $unit_bounds) {
        // A plain list of units has no bounds.
        [$unit, $unit_bounds] = \is_int($unit) ? [(string) $unit_bounds, NULL] : [(string) $unit, $unit_bounds];
        if (preg_match('/^[a-z%]+$/', $unit)) {
          $ranges[$unit] = $bounds($unit_bounds);
        }
      }
      if ($ranges === []) {
        return NULL;
      }
    }
    else {
      $ranges[''] = $bounds($custom);
    }
    $units = \array_keys($ranges);
    // The type follows from the units; integers say so.
    $type = match (TRUE) {
      ($custom['type'] ?? NULL) === 'integer' && $units === [''] => 'integer',
      $units === [''] => 'number',
      $units === ['%'] => 'percentage',
      default => 'length',
    };
    return [
      'type' => $type,
      'units' => \array_map('strval', $units),
      ...$ranges[$units[0]],
      'ranges' => $ranges,
      'value' => $value,
      'property' => $property,
    ];
  }

  /**
   * Parses a custom value against a style's rules.
   *
   * @param CustomSpec $spec
   *   The style's normalized `custom` declaration.
   *
   * @return array{number: float, unit: string, css: string}|null
   *   NULL when the value does not satisfy the style's rules.
   */
  public static function parseCustom(string $value, array $spec): ?array {
    if (!preg_match(self::CUSTOM_PATTERN, $value, $matches)) {
      return NULL;
    }
    $number = (float) $matches[1];
    $unit = $matches[2];
    if (!\in_array($unit, $spec['units'], TRUE)) {
      return NULL;
    }
    if ($spec['type'] === 'integer' && floor($number) !== $number) {
      return NULL;
    }
    $range = $spec['ranges'][$unit] ?? $spec;
    if (($range['min'] !== NULL && $number < $range['min']) || ($range['max'] !== NULL && $number > $range['max'])) {
      return NULL;
    }
    // Rebuilt from the parsed number: nothing else of the stored string is
    // ever output.
    $css = rtrim(rtrim(number_format($number, 4, '.', ''), '0'), '.') . $unit;
    return ['number' => $number, 'unit' => $unit, 'css' => $css];
  }

}
