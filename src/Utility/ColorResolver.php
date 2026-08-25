<?php

declare(strict_types=1);

namespace Drupal\canvas\Utility;

use Drupal\canvas\Entity\Color;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Resolves stored Canvas color prop values to rich objects.
 *
 * @internal
 */
final class ColorResolver {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Extracts the referenced Color config entity ID from a stored value.
   *
   * @param string $stored_value
   *   The stored color prop value.
   *
   * @return string|null
   *   The referenced Color's ID, or NULL if this is not a Brand Kit reference
   *   (i.e. it is a literal CSS value, or an empty reference).
   */
  public static function parseColorEntityId(string $stored_value): ?string {
    if (!\str_starts_with($stored_value, Color::REFERENCE_PREFIX)) {
      return NULL;
    }
    $id = \substr($stored_value, \strlen(Color::REFERENCE_PREFIX));
    return $id === '' ? NULL : $id;
  }

  /**
   * Resolves a stored canvas color value to a rich object for templates.
   *
   * - 'canvas-color:<uuid>' → loads Color config entity, returns object
   * - '#rrggbbaa' → parses hex, returns object with null kit fields
   *
   * @param string $stored_value
   *   The stored color string.
   *
   * @return array{0: array<string, mixed>|null, 1: \Drupal\Core\Cache\CacheableMetadata}
   *   The resolved color array and its cacheability.
   */
  public function resolve(string $stored_value): array {
    $cacheability = new CacheableMetadata();

    if ($stored_value === '') {
      return [NULL, $cacheability];
    }

    // Brand Kit reference.
    if (\str_starts_with($stored_value, Color::REFERENCE_PREFIX)) {
      $uuid = self::parseColorEntityId($stored_value);
      $color = $uuid === NULL ? NULL : $this->entityTypeManager->getStorage('color')->load($uuid);
      if ($color instanceof Color) {
        $cacheability->addCacheableDependency($color);
        $value = $color->getValue();
        $cssColorValue = $this->computeCssColorValue($value);

        return [
          [
            'value' => $value,
            'cssColorValue' => $cssColorValue,
            'cssVariable' => $color->getCssVariable(),
            'colorName' => $color->getName(),
          ],
          $cacheability,
        ];
      }
      // Color entity not found — return NULL with list cache tag.
      $cacheability->addCacheTags(
        $this->entityTypeManager->getDefinition('color')->getListCacheTags()
      );
      return [NULL, $cacheability];
    }

    // Free pick (8-digit hex #rrggbbaa or 6-digit #rrggbb).
    if (\str_starts_with($stored_value, '#')) {
      $value = $this->parseFreePickToValue($stored_value);
      $cssColorValue = $this->computeCssColorValue($value);

      return [
        [
          'value' => $value,
          'cssColorValue' => $cssColorValue,
          'cssVariable' => NULL,
          'colorName' => NULL,
        ],
        $cacheability,
      ];
    }

    // Free pick HSL/HSLA (hsl(h, s%, l%) or hsla(h, s%, l%, a)).
    if (\str_starts_with($stored_value, 'hsl(') || \str_starts_with($stored_value, 'hsla(')) {
      $value = $this->parseFreePickHslToValue($stored_value);
      if ($value !== NULL) {
        $cssColorValue = $this->computeCssColorValue($value);

        return [
          [
            'value' => $value,
            'cssColorValue' => $cssColorValue,
            'cssVariable' => NULL,
            'colorName' => NULL,
          ],
          $cacheability,
        ];
      }
    }

    // Unrecognized format — return NULL.
    return [NULL, $cacheability];
  }

  /**
   * Computes a CSS color value string from a W3C Design Token value.
   *
   * @param array{colorSpace: string, components: list<float>, alpha: float|null, hex: string|null} $value
   *   The color value struct.
   *
   * @return string
   *   The CSS color value string.
   *
   * @see ui/src/features/code-editor/utils/utils.ts
   *    TypeScript counterpart computeCssColorValue() — keep both in sync.
   * @see tests/src/Kernel/Utility/ColorResolverTest.php
   *    Canonical test vectors for both implementations.
   */
  public static function computeCssColorValue(array $value): string {
    $colorSpace = $value['colorSpace'];
    $components = $value['components'];
    $alpha = $value['alpha'] ?? NULL;

    switch ($colorSpace) {
      case 'srgb':
        // Components are [R, G, B] each [0-1].
        $r = (int) \round($components[0] * 255);
        $g = (int) \round($components[1] * 255);
        $b = (int) \round($components[2] * 255);

        if ($alpha === NULL || $alpha === 1.0) {
          return \sprintf('rgb(%d, %d, %d)', $r, $g, $b);
        }
        return \sprintf('rgba(%d, %d, %d, %.2f)', $r, $g, $b, $alpha);

      case 'hsl':
        // Components are [H, S, L] where H is [0-360], S and L are [0-100].
        $h = \round($components[0]);
        $s = \round($components[1]);
        $l = \round($components[2]);

        if ($alpha === NULL || $alpha === 1.0) {
          return \sprintf('hsl(%d, %d%%, %d%%)', $h, $s, $l);
        }
        return \sprintf('hsla(%d, %d%%, %d%%, %.2f)', $h, $s, $l, $alpha);

      default:
        // Fallback: return a neutral gray.
        return 'rgb(0, 0, 0)';
    }
  }

  /**
   * Parses a free-pick hex string into a W3C Design Token value struct.
   *
   * @param string $hex
   *   The stored hex color string (#rrggbb or #rrggbbaa).
   *
   * @return array{colorSpace: 'srgb', components: list<float>, alpha: float|null, hex: string}
   *   The W3C Design Token value struct.
   */
  private static function parseFreePickToValue(string $hex): array {
    $clean = \ltrim($hex, '#');

    // Parse RGB bytes.
    $r = (int) \hexdec(\substr($clean, 0, 2));
    $g = (int) \hexdec(\substr($clean, 2, 2));
    $b = (int) \hexdec(\substr($clean, 4, 2));

    // Parse alpha if present.
    if (\strlen($clean) === 8) {
      $alphaInt = (int) \hexdec(\substr($clean, 6, 2));
      $alpha = $alphaInt / 255;
    }
    else {
      $alpha = NULL;
    }

    return [
      'colorSpace' => 'srgb',
      'components' => [$r / 255, $g / 255, $b / 255],
      'alpha' => $alpha,
      'hex' => \sprintf('#%02x%02x%02x', $r, $g, $b),
    ];
  }

  /**
   * Parses a free-pick HSL string into a W3C Design Token value struct.
   *
   * @param string $hsl
   *   The stored HSL color string (hsl(h, s%, l%) or hsla(h, s%, l%, a)).
   *
   * @return array{colorSpace: 'hsl', components: list<float>, alpha: float|null, hex: null}|null
   *   The W3C Design Token value struct, or NULL if parsing fails.
   */
  private static function parseFreePickHslToValue(string $hsl): ?array {
    // Match hsl(h, s%, l%) - opaque
    $hslPattern = '/^hsl\(\s*(\d+)\s*,\s*(\d+)%\s*,\s*(\d+)%\s*\)$/i';
    // Match hsla(h, s%, l%, a) - with alpha
    $hslaPattern = '/^hsla\(\s*(\d+)\s*,\s*(\d+)%\s*,\s*(\d+)%\s*,\s*([\d.]+)\s*\)$/i';

    if (\preg_match($hslaPattern, $hsl, $matches)) {
      $h = (int) $matches[1];
      $s = (int) $matches[2];
      $l = (int) $matches[3];
      $a = (float) $matches[4];

      return [
        'colorSpace' => 'hsl',
        'components' => [(float) $h, (float) $s, (float) $l],
        'alpha' => $a,
        'hex' => NULL,
      ];
    }

    if (\preg_match($hslPattern, $hsl, $matches)) {
      $h = (int) $matches[1];
      $s = (int) $matches[2];
      $l = (int) $matches[3];

      return [
        'colorSpace' => 'hsl',
        'components' => [(float) $h, (float) $s, (float) $l],
        'alpha' => NULL,
        'hex' => NULL,
      ];
    }

    return NULL;
  }

}
