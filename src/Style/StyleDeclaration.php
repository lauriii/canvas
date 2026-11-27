<?php

declare(strict_types=1);

namespace Drupal\canvas\Style;

use Drupal\canvas\Entity\ComponentInterface;

/**
 * Which styles a component accepts, per element, with their limits.
 *
 * Authors write one shape for the root and for each part: a list of style
 * IDs, or a map of style ID to a limit (or NULL for none).
 *
 * @code
 * thirdPartySettings:
 *   canvas:
 *     styles:
 *       palette: ~
 *       fill: { folders: [Brand] }
 *     style_parts:
 *       heading:
 *         label: Heading
 *         styles:
 *           text_size: { options: [l, xl] }
 * @endcode
 *
 * Stored settings keep root styles as a list (`styles`) and root limits
 * apart (`style_limits`), so components without limits keep their version
 * hash; each part stores `styles` and `limits`.
 *
 * @see docs/adr/0033-style-parts.md
 *
 * @phpstan-import-type StyleLimits from \Drupal\canvas\Style\ComponentStyles
 * @phpstan-type StoredPart array{label: string, styles: list<string>, limits?: array<string, StyleLimits>}
 *
 * @internal
 */
final class StyleDeclaration {

  /**
   * Splits an authored style list or map into style IDs and limits.
   *
   * @return array{0: list<string>, 1: array<string, StyleLimits>}
   */
  public static function split(mixed $authored): array {
    if (!\is_array($authored)) {
      return [[], []];
    }
    $styles = [];
    $limits = [];
    foreach ($authored as $key => $value) {
      // List items: a style ID, or a one-entry map of style ID to limit.
      if (\is_int($key)) {
        if (\is_string($value)) {
          $styles[] = $value;
        }
        elseif (\is_array($value) && \count($value) === 1) {
          [$key, $value] = [(string) \array_key_first($value), \reset($value)];
        }
        else {
          continue;
        }
      }
      if (\is_string($key)) {
        $styles[] = $key;
        if (\is_array($value) && $value !== []) {
          $limits[$key] = $value;
        }
      }
    }
    ksort($limits);
    return [\array_values(\array_unique($styles)), $limits];
  }

  /**
   * Turns an SDC's authored `thirdPartySettings.canvas` into stored settings.
   *
   * @return array{styles?: list<string>, style_limits?: array<string, StyleLimits>, style_parts?: array<string, StoredPart>}
   */
  public static function fromAuthored(array $canvas): array {
    $authored = $canvas['styles'] ?? NULL;
    [$styles, $limits] = $authored === ComponentStyles::ALL
      ? [[ComponentStyles::ALL], []]
      : self::split($authored);
    $parts = [];
    foreach (\is_array($canvas['style_parts'] ?? NULL) ? $canvas['style_parts'] : [] as $part => $definition) {
      if (!\is_array($definition)) {
        continue;
      }
      [$part_styles, $part_limits] = self::split($definition['styles'] ?? []);
      $parts[(string) $part] = [
        'label' => (string) ($definition['label'] ?? ''),
        'styles' => $part_styles,
        ...($part_limits !== [] ? ['limits' => $part_limits] : []),
      ];
    }
    return [
      ...($styles !== [] ? ['styles' => $styles] : []),
      ...($limits !== [] ? ['style_limits' => $limits] : []),
      ...($parts !== [] ? ['style_parts' => $parts] : []),
    ];
  }

  /**
   * A component's limits for one element: the root, or a named part.
   *
   * @return array<string, StyleLimits>
   */
  public static function limits(ComponentInterface $component, ?string $part = NULL): array {
    $settings = $component->getSettings();
    $limits = $part === NULL
      ? ($settings['style_limits'] ?? [])
      : ($settings['style_parts'][$part]['limits'] ?? []);
    return \is_array($limits) ? $limits : [];
  }

  /**
   * The selection keys stored settings accept, before availability.
   *
   * @return array{keys: array<string, string>, all: bool}
   *   Style IDs keyed by selection key, and whether the root accepts every
   *   style ('*').
   */
  private static function declaredKeys(array $settings): array {
    $root = \is_array($settings['styles'] ?? NULL) ? $settings['styles'] : [];
    $all = \in_array(ComponentStyles::ALL, $root, TRUE);
    $keys = [];
    foreach ($all ? [] : $root as $style_id) {
      $keys[(string) $style_id] = (string) $style_id;
    }
    foreach (\is_array($settings['style_parts'] ?? NULL) ? $settings['style_parts'] : [] as $part => $definition) {
      foreach ((array) ($definition['styles'] ?? []) as $style_id) {
        $keys[$part . ComponentStyles::PART_SEPARATOR . $style_id] = (string) $style_id;
      }
    }
    return ['keys' => $keys, 'all' => $all];
  }

  /**
   * Moves stored values that a new component version moved between elements.
   *
   * A value whose element no longer accepts its style moves to the one new
   * place that accepts the same style, if there is exactly one and it has no
   * value: a hero's `display_size` becomes `heading.display_size` when the
   * new version moves Headline size to its Heading part, and back again.
   * Anything else is left for the editor, as a degraded entry.
   *
   * @param array<string, string> $selection
   *   The stored selection.
   * @param array $from
   *   The stored settings of the instance's version.
   * @param array $to
   *   The stored settings of the version it updates to.
   *
   * @return array<string, string>
   */
  public static function migrateSelection(array $selection, array $from, array $to): array {
    $before = self::declaredKeys($from);
    $after = self::declaredKeys($to);
    $accepted = static fn (array $declared, string $key): bool => isset($declared['keys'][$key])
      || ($declared['all'] && !str_contains($key, ComponentStyles::PART_SEPARATOR));
    foreach ($selection as $key => $value) {
      $key = (string) $key;
      if ($accepted($after, $key) || !$accepted($before, $key)) {
        continue;
      }
      [, $style_id] = ComponentStyles::splitKey($key);
      $targets = \array_keys(\array_filter(
        $after['keys'],
        static fn (string $candidate_style, string $candidate) => $candidate_style === $style_id
          && !isset($selection[$candidate])
          && !$accepted($before, $candidate),
        ARRAY_FILTER_USE_BOTH,
      ));
      if (\count($targets) === 1) {
        unset($selection[$key]);
        $selection[$targets[0]] = $value;
      }
    }
    return $selection;
  }

}
