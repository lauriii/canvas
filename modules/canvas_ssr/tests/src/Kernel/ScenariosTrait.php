<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_ssr\Kernel;

use PHPUnit\Framework\AssertionFailedError;

/**
 * Runs several named scenarios in one test method.
 *
 * Each kernel test method installs a site, which is most of the cost of a
 * simple test. So scenarios that share fixtures can share a method. Unlike
 * plain sequential assertions, one failure doesn't hide the rest: every
 * failure is reported, by name.
 *
 * A scenario that needs its own Drupal modules, services or global state
 * still needs its own method.
 */
trait ScenariosTrait {

  /**
   * Asserts every scenario passes, and reports all that don't.
   *
   * @param array<string, callable():void> $scenarios
   *   Scenarios keyed by the claim each makes, which names it on failure.
   */
  protected function assertScenarios(array $scenarios): void {
    $failures = [];
    foreach ($scenarios as $claim => $scenario) {
      try {
        $scenario();
      }
      catch (AssertionFailedError $e) {
        $failures[$claim] = $e->getMessage();
      }
      // Let other exceptions through, with their stack traces.
    }

    self::assertSame([], $failures);
  }

}
