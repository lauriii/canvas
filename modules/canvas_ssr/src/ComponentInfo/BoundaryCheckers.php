<?php

declare(strict_types=1);

namespace Drupal\canvas_ssr\ComponentInfo;

use Drupal\canvas_ssr\ComponentInfo\BoundaryChecker\BoundaryCheckerInterface;

/**
 * The boundary checkers, by the framework each serves.
 *
 * Checkers are tagged services that list their frameworks. The highest
 * priority checker for a framework wins, so a Drupal module can replace one.
 *
 * SSR bails for a framework without a checker, rather than judge it by
 * another framework's rules. See ADR 3.
 */
final class BoundaryCheckers {

  /**
   * Checkers keyed by framework, once collected.
   *
   * @var array<string, \Drupal\canvas_ssr\ComponentInfo\BoundaryChecker\BoundaryCheckerInterface>|null
   */
  private ?array $checkersByFramework = NULL;

  /**
   * Constructs a BoundaryCheckers collection.
   *
   * @param iterable<\Drupal\canvas_ssr\ComponentInfo\BoundaryChecker\BoundaryCheckerInterface> $checkers
   *   The checkers, highest priority first.
   */
  public function __construct(
    private readonly iterable $checkers,
  ) {}

  /**
   * Gets the checker for a framework.
   *
   * @param string $framework
   *   The island's framework, such as "preact".
   *
   * @return \Drupal\canvas_ssr\ComponentInfo\BoundaryChecker\BoundaryCheckerInterface|null
   *   The checker, or NULL if there's none.
   */
  public function get(string $framework): ?BoundaryCheckerInterface {
    if ($this->checkersByFramework === NULL) {
      $this->checkersByFramework = [];
      foreach ($this->checkers as $checker) {
        foreach ($checker->getFrameworks() as $framework_name) {
          // The first, highest priority, claim wins.
          $this->checkersByFramework[$framework_name] ??= $checker;
        }
      }
    }

    return $this->checkersByFramework[$framework] ?? NULL;
  }

}
