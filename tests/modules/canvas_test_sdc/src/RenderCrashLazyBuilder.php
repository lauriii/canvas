<?php

declare(strict_types=1);

namespace Drupal\canvas_test_sdc;

use Drupal\Core\Security\Attribute\TrustedCallback;

/**
 * Provides a #lazy_builder whose render fails during placeholder replacement.
 *
 * A component preview can crash while it is being rendered from a placeholder
 * (the common case for views, blocks and other cacheable sub-renders). That
 * crash happens during root-level placeholder replacement, after the initial
 * render has already returned, which is precisely what makes it hard to
 * contain.
 *
 * @see \Drupal\Tests\canvas\Kernel\Element\RenderSafeComponentContainerTest
 */
final class RenderCrashLazyBuilder {

  /**
   * Renders an SDC with a value that cannot be encoded as JSON.
   *
   * Passing a value containing a circular reference to a string prop makes
   * core's ComponentValidator json_encode() fail with "Recursion detected" —
   * the exact failure reported in https://www.drupal.org/i/3541431.
   *
   * @return array
   *   A render array that throws while rendering.
   */
  #[TrustedCallback]
  public static function build(): array {
    $circular = new \stdClass();
    $circular->self = $circular;
    return [
      '#type' => 'component',
      '#component' => 'canvas_test_sdc:required-plain-string',
      '#props' => [
        'title' => ['#markup' => 'x', 'circular' => $circular],
      ],
    ];
  }

}
