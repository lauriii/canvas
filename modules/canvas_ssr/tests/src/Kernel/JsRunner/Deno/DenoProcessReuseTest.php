<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_ssr\Kernel\JsRunner\Deno;

use Drupal\canvas_ssr\JsRunner\Deno\DenoRunner;
use Drupal\canvas_ssr\JsRunner\RunnerSelector;
use Drupal\Tests\canvas_ssr\Kernel\CanvasSsrKernelTestBase;
use Drupal\Tests\canvas_ssr\Traits\DenoProcessesTrait;
use Drupal\Tests\canvas_ssr\Traits\WarmsUpDenoTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests a page's islands render in one Deno process.
 *
 * Starting a Deno process is most of what a cold invoke costs. Nothing
 * Renderer does between islands may end the one the first island started.
 *
 * @see docs/adr/0008-a-deno-process-lives-for-one-request.md
 *
 * @legacy-covers \Drupal\canvas_ssr\Renderer
 * @legacy-covers \Drupal\canvas_ssr\JsRunner\Deno\DenoRunner
 *
 * @see \Drupal\Tests\canvas_ssr\Unit\JsRunner\Deno\DenoProcessTest
 */
#[RunTestsInSeparateProcesses]
#[Group('canvas')]
#[Group('JavaScriptComponents')]
final class DenoProcessReuseTest extends CanvasSsrKernelTestBase {

  use DenoProcessesTrait;
  use WarmsUpDenoTrait;

  /**
   * Tests islands of different components share the first one's process.
   *
   * Skipped when the Deno runner isn't available.
   */
  public function testIslandsShareOneDenoProcess(): void {
    $selector = $this->container->get(RunnerSelector::class);
    self::assertInstanceOf(RunnerSelector::class, $selector);
    if (!$selector->select() instanceof DenoRunner) {
      self::markTestSkipped('The Deno runner is not available here.');
    }
    $components = [];
    foreach (['a', 'b', 'c'] as $text) {
      $source = \sprintf('import { h } from "preact"; export default function C(){ return h("p", null, "%s"); }', $text);
      $components[$text] = $this->createComponent($text, $source);
    }

    $processes = [];
    // Twice over, so repeat islands are covered too.
    for ($round = 1; $round <= 2; $round++) {
      foreach ($components as $text => $component) {
        self::assertSame("<p>$text</p>", $this->extractSsrMarkup($this->renderComponent($component)));
        $processes[] = self::denoProcesses();
      }
    }

    self::assertCount(6, $processes);
    self::assertCount(1, $processes[0]);
    self::assertSame([$processes[0]], \array_values(\array_unique($processes, SORT_REGULAR)));
  }

}
