<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_ssr\Kernel\JsRunner;

use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Extension\Requirement\RequirementSeverity;
use Drupal\Tests\canvas_ssr\Kernel\CanvasSsrKernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the selector selects the runner under test.
 *
 * The SSR tests skip when it doesn't, so a run where the runner can't render
 * would otherwise pass. This test fails instead.
 *
 * @see \Drupal\Tests\canvas_ssr\Kernel\RunnerTestTrait
 */
#[RunTestsInSeparateProcesses]
#[Group('canvas')]
#[Group('JavaScriptComponents')]
#[Group('canvas_ssr_requires_runner')]
final class RunnerSelectedTest extends CanvasSsrKernelTestBase {

  /**
   * Tests the selector selects the runner under test.
   */
  public function testSelectorSelectsTheRunnerUnderTest(): void {
    self::assertNotNull(
      $this->selectRunner(),
      $this->runnerNotSelectedMessage(),
    );

    // And the status report says SSR is on.
    $requirements = $this->container->get(ModuleHandlerInterface::class)->invoke('canvas_ssr', 'runtime_requirements');
    self::assertIsArray($requirements);
    self::assertSame(RequirementSeverity::OK, $requirements['canvas_ssr']['severity']);
    self::assertSame('On', (string) $requirements['canvas_ssr']['value']);
  }

}
