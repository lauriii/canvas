<?php

declare(strict_types=1);

namespace Drupal\canvas_ai\Utility;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Builds the progress lines for tool calls an agent made without narrating.
 *
 * @see \Drupal\canvas_dev_ai\Controller\CanvasDevAiBuilder::getAiProgress()
 */
final class ToolProgressHelper {

  /**
   * Gets the component IDs a place_components call places, in tree order.
   *
   * @param mixed $operations
   *   The operations argument of the place_components call.
   *
   * @return list<string>|null
   *   The component IDs, or NULL when the operations are not a list or a
   *   components value is not a YAML string.
   *
   * @see \Drupal\canvas_ai\Plugin\AiFunctionCall\PlaceComponents
   */
  public static function componentIdsFromOperations(mixed $operations): ?array {
    if (!\is_array($operations)) {
      return NULL;
    }
    $component_ids = [];
    foreach ($operations as $operation) {
      if (!\is_array($operation) || !\is_string($operation['components'] ?? NULL)) {
        return NULL;
      }
      try {
        self::collectComponentIds(Yaml::parse($operation['components']), $component_ids);
      }
      catch (ParseException) {
        return NULL;
      }
    }
    return $component_ids;
  }

  /**
   * Gets the first two distinct values, and whether another one exists.
   *
   * @param list<string> $values
   *   The values, in order.
   *
   * @return array{0: list<string>, 1: bool}
   *   The first two distinct values, and TRUE when a third distinct value
   *   exists.
   */
  public static function firstTwoDistinct(array $values): array {
    $distinct = \array_values(\array_unique($values));
    return [\array_slice($distinct, 0, 2), \count($distinct) > 2];
  }

  /**
   * Formats a progress line naming one or two components.
   *
   * @param string $verb
   *   The verb the line starts with.
   * @param list<string> $names
   *   One or two component names.
   * @param bool $has_more
   *   Whether more components follow the named ones.
   *
   * @return string
   *   "{verb} A", "{verb} A and B" or "{verb} A, B and other components".
   */
  public static function format(string $verb, array $names, bool $has_more): string {
    return match (TRUE) {
      $has_more => \sprintf('%s %s, %s and other components', $verb, ...$names),
      \count($names) === 2 => \sprintf('%s %s and %s', $verb, ...$names),
      default => $verb . ' ' . $names[0],
    };
  }

  /**
   * Collects the component IDs of a components tree in tree order.
   *
   * Each component comes before the components in its slots, which come
   * before the next component.
   *
   * @param mixed $components
   *   A list of single-key maps of a component ID to its props and slots.
   * @param list<string> $component_ids
   *   The collected component IDs.
   */
  private static function collectComponentIds(mixed $components, array &$component_ids): void {
    if (!\is_array($components)) {
      return;
    }
    foreach ($components as $component) {
      if (!\is_array($component) || \count($component) !== 1) {
        continue;
      }
      $component_id = (string) \array_key_first($component);
      $component_ids[] = $component_id;
      $slots = $component[$component_id]['slots'] ?? [];
      foreach (\is_array($slots) ? $slots : [] as $slot_components) {
        self::collectComponentIds($slot_components, $component_ids);
      }
    }
  }

}
