<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_ssr\Unit\JsRunner\Deno;

use Drupal\canvas_ssr\JsRunner\Deno\DenoRunner;
use Drupal\canvas_ssr\JsRunner\RunnerInterface;
use Drupal\Tests\canvas_ssr\Traits\WarmsUpDenoTrait;
use Drupal\Tests\canvas_ssr\Unit\JsRunner\RunnerConformanceTestBase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Large;

/**
 * Runs the runner conformance tests against Deno.
 *
 * All cases are inherited, so every runner meets the same contract.
 * Without a usable binary, isAvailable() is FALSE and the class is skipped.
 */
#[CoversClass(DenoRunner::class)]
#[Large]
#[Group('canvas_ssr')]
final class DenoConformanceTest extends RunnerConformanceTestBase {

  use DenoBinaryTrait;
  use WarmsUpDenoTrait;

  /**
   * {@inheritdoc}
   */
  protected function createRunner(): RunnerInterface {
    return new DenoRunner(self::denoBinary());
  }

  /**
   * {@inheritdoc}
   *
   * V8 takes a few hundred ms of CPU time to fill its heap, past the short
   * limit's default.
   */
  protected function createRunnerToRunOutOfMemory(): RunnerInterface {
    return new DenoRunner(self::denoBinary(), ['short' => 10000, 'medium' => 20000, 'long' => 40000], memoryLimitMib: 128);
  }

  /**
   * {@inheritdoc}
   *
   * Each module's import.meta.resolve() calls the one global here. It
   * answers from the import map, which a component can read anyway.
   */
  protected function runnerGlobals(): array {
    return ['__canvasSsrResolveImportMeta'];
  }

}
