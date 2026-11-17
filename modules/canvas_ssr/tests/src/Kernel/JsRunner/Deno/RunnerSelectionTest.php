<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_ssr\Kernel\JsRunner\Deno;

use Drupal\canvas_ssr\JsRunner\Deno\DenoRunner;
use Drupal\canvas_ssr\JsRunner\RunnerSelector;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Tests\canvas_ssr\Kernel\CanvasSsrKernelTestBase;
use Drupal\Tests\canvas_ssr\Kernel\SpyRunner;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests where the selector ranks Deno among the runners.
 *
 * Whether Deno works is the conformance tests' job.
 *
 * @legacy-covers \Drupal\canvas_ssr\JsRunner\Deno\DenoRunner
 */
#[RunTestsInSeparateProcesses]
#[Group('canvas')]
#[Group('JavaScriptComponents')]
final class RunnerSelectionTest extends CanvasSsrKernelTestBase {

  /**
   * The priority a runner module gives its runner in these tests.
   *
   * What a runner module would pick to rank above the Deno runner: anything
   * above Deno's 0.
   */
  private const int RUNNER_MODULE_PRIORITY = 100;

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container): void {
    parent::register($container);
    // As a runner module would register its runner.
    if ($this->name() === 'testRunnerModuleOutranksDeno') {
      $container
        ->register(SpyRunner::class, SpyRunner::class)
        ->addTag('canvas_ssr.runner', ['priority' => self::RUNNER_MODULE_PRIORITY]);
    }
  }

  /**
   * Tests the selector selects Deno when it's the only runner.
   *
   * A broken tag would quietly make every island fall back to CSR. Skipped
   * when Deno isn't available.
   */
  public function testSelectorSelectsDenoWhenItIsTheOnlyRunner(): void {
    $deno = $this->container->get(DenoRunner::class);
    self::assertInstanceOf(DenoRunner::class, $deno);
    if (!$deno->isAvailable()) {
      self::markTestSkipped(
        'The Deno runner is not available here, so nothing could select it.',
      );
    }

    $selector = $this->container->get(RunnerSelector::class);
    self::assertInstanceOf(RunnerSelector::class, $selector);
    self::assertSame($deno, $selector->select());
  }

  /**
   * Tests a runner module's runner is selected over Deno.
   *
   * Deno's priority is the default, 0, so a runner module can outrank it.
   * Skipped when Deno isn't available, since it would lose anyway.
   */
  public function testRunnerModuleOutranksDeno(): void {
    $deno = $this->container->get(DenoRunner::class);
    self::assertInstanceOf(DenoRunner::class, $deno);
    if (!$deno->isAvailable()) {
      self::markTestSkipped(
        'The Deno runner is not available here, so nothing could select it.',
      );
    }

    $selector = $this->container->get(RunnerSelector::class);
    self::assertInstanceOf(RunnerSelector::class, $selector);
    self::assertInstanceOf(SpyRunner::class, $selector->select());
  }

}
