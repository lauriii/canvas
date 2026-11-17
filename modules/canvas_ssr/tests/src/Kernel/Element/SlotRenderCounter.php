<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_ssr\Kernel\Element;

use Drupal\Core\Security\TrustedCallbackInterface;

/**
 * Counts how many times a slot is rendered.
 *
 * The output can't show whether a slot was rendered again or reused.
 */
final class SlotRenderCounter implements TrustedCallbackInterface {

  /**
   * How many times build() has run since the last slot().
   */
  public static int $renders = 0;

  /**
   * Counts a render.
   *
   * @param array<string, mixed> $element
   *   The slot render array.
   *
   * @return array<string, mixed>
   *   The slot render array, unchanged.
   */
  public static function build(array $element): array {
    self::$renders++;
    return $element;
  }

  /**
   * Returns a counted slot, and resets the count.
   *
   * @param string $markup
   *   The markup the slot renders to.
   *
   * @return array<string, mixed>
   *   A render array that counts its own renders.
   */
  public static function slot(string $markup): array {
    self::$renders = 0;
    return [
      '#pre_render' => [[self::class, 'build']],
      '#markup' => $markup,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public static function trustedCallbacks(): array {
    return ['build'];
  }

}
