<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_ssr\Traits;

use PHPUnit\Framework\Attributes\BeforeClass;

/**
 * Warms Deno up before a test class that always runs it under time limits.
 *
 * Kernel tests that run Deno only when it's the runner under test get this
 * from CanvasSsrKernelTestBase instead.
 *
 * @see \Drupal\Tests\canvas_ssr\Traits\DenoWarmUp
 */
trait WarmsUpDenoTrait {

  /**
   * Warms Deno up, if this process hasn't.
   */
  #[BeforeClass]
  public static function warmUpDeno(): void {
    DenoWarmUp::run();
  }

}
