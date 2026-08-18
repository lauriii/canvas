<?php

declare(strict_types=1);

namespace Drupal\canvas\Validation\JsonSchema;

use JsonSchema\ConstraintError;
use JsonSchema\Constraints\StringConstraint;
use JsonSchema\Entity\JsonPointer;

/**
 * JSON Schema constraint for color props stored as strings.
 *
 * - Validates hex colors (#rrggbb or #rrggbbaa) for free-pick values
 * - Validates Brand Kit references (canvas-color:<uuid>) exist
 * - Short-circuits for already-resolved objects (render pipeline)
 *
 * @internal
 */
final class CanvasColorStringConstraint extends StringConstraint {

  /**
   * {@inheritdoc}
   */
  public function check(&$element, $schema = NULL, ?JsonPointer $path = NULL, $i = NULL): void {
    // Short-circuit for resolved color objects before parent::check().
    // At render time, our pipeline has already transformed the stored string
    // into a color object. We must skip string validation for that object.
    if ((($schema->{'$ref'} ?? NULL) === 'json-schema-definitions://canvas.module/color')
        && \is_array($element)) {
      return;
    }

    // Run base string validation (maxLength, minLength, pattern, format).
    parent::check($element, $schema, $path, $i);

    // Skip color-specific validation if this is not a color prop.
    if (($schema->{'$ref'} ?? NULL) !== 'json-schema-definitions://canvas.module/color') {
      return;
    }

    // Skip if parent already found errors (don't pile on).
    if (!$this->isValid()) {
      return;
    }

    // Color-specific validation for stored string values.
    if (!\is_string($element)) {
      $this->addError(ConstraintError::get(ConstraintError::TYPE), $path, [
        'type' => 'string, color hex, or Brand Kit reference',
        'found' => \gettype($element),
      ]);
      return;
    }

    // Brand Kit reference.
    if (\str_starts_with($element, 'canvas-color:')) {
      $uuid = \substr($element, \strlen('canvas-color:'));
      if (empty($uuid)) {
        $this->addError(ConstraintError::get(ConstraintError::PATTERN), $path, ['pattern' => 'canvas-color:<uuid>']);
        return;
      }
      if (!self::colorEntityExists($uuid)) {
        $this->addError(ConstraintError::get(ConstraintError::FORMAT_COLOR), $path, [
          'message' => \sprintf('Color "%s" not found in Brand Kit.', $uuid),
        ]);
      }
      return;
    }

    // Hex color.
    if (\str_starts_with($element, '#')) {
      $clean = \ltrim($element, '#');
      // 6-digit hex (#rrggbb) - valid, treat as fully opaque.
      if (\strlen($clean) === 6 && \ctype_xdigit($clean)) {
        return;
      }
      // 8-digit hex (#rrggbbaa) - valid.
      if (\strlen($clean) === 8 && \ctype_xdigit($clean)) {
        return;
      }
      // Invalid hex length or characters.
      $this->addError(ConstraintError::get(ConstraintError::PATTERN), $path, [
        'pattern' => '#rrggbb or #rrggbbaa (6 or 8 hex digits)',
      ]);
      return;
    }

    // HSL/HSLA color.
    if (\str_starts_with($element, 'hsl(') || \str_starts_with($element, 'hsla(')) {
      // Validate HSL format: hsl(h, s%, l%) or hsla(h, s%, l%, a)
      // H: 0-360, S: 0-100, L: 0-100, A: 0-1
      if (self::isValidHslString($element)) {
        return;
      }
      // Invalid HSL format.
      $this->addError(ConstraintError::get(ConstraintError::PATTERN), $path, [
        'pattern' => 'hsl(h, s%, l%) or hsla(h, s%, l%, a) where h:0-360, s:0-100, l:0-100, a:0-1',
      ]);
      return;
    }

    // Unrecognized format.
    $this->addError(ConstraintError::get(ConstraintError::PATTERN), $path, [
      'pattern' => 'hex color (#rrggbb or #rrggbbaa), HSL (hsl(h, s%, l%)), or Brand Kit reference (canvas-color:<uuid>)',
    ]);
  }

  /**
   * Returns TRUE if a color entity with the given UUID exists in the Brand Kit.
   *
   * @param string $uuid
   *   The UUID to look up.
   *
   * @return bool
   *   TRUE if the color entity exists.
   */
  private static function colorEntityExists(string $uuid): bool {
    return \Drupal::entityTypeManager()->getStorage('color')->load($uuid) !== NULL;
  }

  /**
   * Validates an HSL/HSLA string format.
   *
   * @param string $value
   *   The value to validate.
   *
   * @return bool
   *   TRUE if valid HSL/HSLA format.
   */
  private static function isValidHslString(string $value): bool {
    // Match hsl(h, s%, l%) - opaque
    $hslPattern = '/^hsl\(\s*(\d+)\s*,\s*(\d+)%\s*,\s*(\d+)%\s*\)$/i';
    // Match hsla(h, s%, l%, a) - with alpha
    $hslaPattern = '/^hsla\(\s*(\d+)\s*,\s*(\d+)%\s*,\s*(\d+)%\s*,\s*([\d.]+)\s*\)$/i';

    if (\preg_match($hslaPattern, $value, $matches)) {
      $h = (int) $matches[1];
      $s = (int) $matches[2];
      $l = (int) $matches[3];
      $a = (float) $matches[4];

      return $h >= 0 && $h <= 360
        && $s >= 0 && $s <= 100
        && $l >= 0 && $l <= 100
        && $a >= 0 && $a <= 1;
    }

    if (\preg_match($hslPattern, $value, $matches)) {
      $h = (int) $matches[1];
      $s = (int) $matches[2];
      $l = (int) $matches[3];

      return $h >= 0 && $h <= 360
        && $s >= 0 && $s <= 100
        && $l >= 0 && $l <= 100;
    }

    return FALSE;
  }

}
