<?php

declare(strict_types=1);

namespace Drupal\canvas\DesignToken;

use Drupal\canvas\Entity\Color;
use Drupal\Component\Plugin\Exception\PluginException;
use Drupal\Core\Theme\DesignToken\DesignTokenValuePluginBase;

/**
 * The DTCG `color` design token value, until core provides one.
 *
 * This class lives outside `Plugin/DesignTokenValue` so discovery never finds
 * it. It is registered by an alter hook, only when core has no `color` type.
 *
 * The stored shape is the DTCG 2025.10 color object that `canvas.color.*`
 * uses for `value`. A 3-, 6- or 8-digit hex string is accepted as input. Any
 * other string (including alias syntax) throws a PluginException, which makes
 * core's discovery drop the token.
 *
 * @see \Drupal\canvas\Hook\DesignTokenHooks::designTokenValueInfoAlter()
 * @internal
 */
final class ColorValue extends DesignTokenValuePluginBase {

  /**
   * The normalized color.
   *
   * Protected, not private: definitions holding this plugin are cached, and
   * DependencySerializationTrait::__sleep() only sees non-private properties.
   *
   * @var array{colorSpace: string, components: list<float>, alpha: float|null, hex: string|null}
   */
  protected array $color;

  /**
   * {@inheritdoc}
   */
  public function setConfiguration(array $configuration): static {
    parent::setConfiguration($configuration);
    // Core's prepareConfiguration() wraps scalars as ['value' => …].
    $input = \array_keys($configuration) === ['value'] ? $configuration['value'] : $configuration;
    $color = self::parse($input);
    if ($color === NULL) {
      throw new PluginException('Invalid DTCG color value.');
    }
    $this->color = $color;
    return $this;
  }

  /**
   * Parses a DTCG color object or a hex string into the normalized shape.
   *
   * @return array{colorSpace: string, components: list<float>, alpha: float|null, hex: string|null}|null
   *   The normalized color, with `alpha` and `hex` always present, or NULL
   *   when the input is not a valid color.
   */
  public static function parse(mixed $input): ?array {
    if (\is_string($input)) {
      if (!\preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6}|[0-9a-f]{8})$/i', $input)) {
        return NULL;
      }
      $hex = \strtolower(\substr($input, 1));
      if (\strlen($hex) === 3) {
        $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
      }
      $bytes = \array_map(static fn (string $pair): int => (int) \hexdec($pair), \str_split($hex, 2));
      return [
        'colorSpace' => 'srgb',
        'components' => [$bytes[0] / 255, $bytes[1] / 255, $bytes[2] / 255],
        'alpha' => isset($bytes[3]) ? \round($bytes[3] / 255, 4) : NULL,
        'hex' => '#' . \substr($hex, 0, 6),
      ];
    }
    if (!\is_array($input) || !\in_array($input['colorSpace'] ?? NULL, ['srgb', 'hsl'], TRUE)) {
      return NULL;
    }
    $components = $input['components'] ?? NULL;
    if (!\is_array($components) || !\array_is_list($components) || \count($components) !== 3) {
      return NULL;
    }
    foreach ($components as $component) {
      if (!\is_int($component) && !\is_float($component)) {
        return NULL;
      }
    }
    $alpha = $input['alpha'] ?? NULL;
    if ($alpha !== NULL && (!\is_numeric($alpha) || $alpha < 0 || $alpha > 1)) {
      return NULL;
    }
    $hex = $input['hex'] ?? NULL;
    if ($hex !== NULL && (!\is_string($hex) || !\preg_match('/^#[0-9a-f]{6}$/i', $hex))) {
      return NULL;
    }
    return [
      'colorSpace' => $input['colorSpace'],
      'components' => \array_map(static fn (int|float $c): float => (float) $c, $components),
      'alpha' => $alpha === NULL ? NULL : (float) $alpha,
      'hex' => $hex === NULL ? NULL : \strtolower($hex),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function toDtcg(): mixed {
    return $this->color;
  }

  /**
   * {@inheritdoc}
   *
   * Identical to what Canvas emits for a site color with the same value.
   */
  public function toCss(): string {
    return Color::cssValue($this->color);
  }

}
