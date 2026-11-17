<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_ssr\Kernel\ComponentInfo\BoundaryChecker;

use Drupal\canvas_ssr\ComponentInfo\BoundaryChecker\ReactBoundaryChecker;
use Drupal\canvas_ssr\ComponentInfo\BoundaryCheckers;
use Drupal\Tests\canvas_ssr\Kernel\CanvasSsrKernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the React boundary checker and the collection that serves it.
 *
 * The caller decides which entities make up the graph, so these tests pass
 * entities to the checker directly.
 *
 * @legacy-covers \Drupal\canvas_ssr\ComponentInfo\BoundaryChecker\ReactBoundaryChecker
 * @legacy-covers \Drupal\canvas_ssr\ComponentInfo\BoundaryCheckers
 *
 * @see docs/adr/0003-a-boundary-checker-decides-hydration.md
 * @see \Drupal\Tests\canvas_ssr\Kernel\ScenariosTrait
 */
#[RunTestsInSeparateProcesses]
#[Group('canvas')]
#[Group('JavaScriptComponents')]
final class ReactBoundaryCheckerTest extends CanvasSsrKernelTestBase {

  /**
   * Tests what the checker finds, and which frameworks it is served for.
   *
   * The scenarios share one site install.
   */
  public function testBoundaries(): void {
    $this->assertScenarios([
      ...$this->hydrationScenarios(),
      ...$this->collectionScenarios(),
    ]);
  }

  /**
   * Scenarios for finding a client boundary, a "use client" directive.
   *
   * @return array<string, callable():void>
   *   Scenarios keyed by the claim each makes.
   */
  private function hydrationScenarios(): array {
    $checker = $this->checker();
    // Shared by the scenarios below. None of them change these.
    $inert = $this->createComponent('boundary probe', 'export default () => null;');
    $quiet = $this->createComponent('boundary probe', 'export const a = 1;');
    $boundary = $this->createComponent('boundary probe', "'use client';\nexport default () => null;");

    return [
      'a graph with no client boundary does not hydrate' => function () use ($checker, $inert, $quiet): void {
        self::assertFalse($checker->hydrates($inert, ['child' => $quiet]));
      },

      "the root's own directive is enough" => function () use ($checker, $boundary): void {
        self::assertTrue($checker->hydrates($boundary, []));
      },

      // A server-rendered parent can't finish a browser-only child, so the
      // whole island hydrates. The quiet dependency comes first, to show the
      // checker reads past it.
      "a dependency's directive makes the whole island hydrate" => function () use ($checker, $inert, $quiet, $boundary): void {
        self::assertTrue($checker->hydrates($inert, [
          'quiet' => $quiet,
          'interactive' => $boundary,
        ]));
      },

      // The authored source here is TypeScript, which the parser can't read.
      // Reading it would miss the directive and ship a hydrated island as a
      // static one.
      'the directive is read from the compiled JS, not the authored source' => function () use ($checker): void {
        $declared_only_in_compiled = $this->createComponent(
          'typescript source, directive in compiled output',
          "import type { Props } from './types';\nconst a: string = 'x';\nexport default (p: Props) => null;",
          compiled: "'use client';\nexport default (p) => null;",
        );
        $declared_only_in_source = $this->createComponent(
          'directive dropped by compilation',
          "'use client';\nexport default () => null;",
          compiled: 'export default () => null;',
        );

        self::assertTrue($checker->hydrates($declared_only_in_compiled, []));
        self::assertFalse($checker->hydrates($declared_only_in_source, []));
      },

      // Whether its directive prologue says "use client" can't be read.
      'source that cannot be parsed cannot be told about' => function () use ($checker): void {
        self::assertNull($checker->hydrates($this->createComponent('boundary probe', 'import { from "broken'), []));
      },

      // Even when another component in the graph is a boundary.
      'a dependency that cannot be parsed cannot be told about' => function () use ($checker): void {
        $boundary = $this->createComponent('boundary root', "'use client';\nexport default () => null;");
        $broken = $this->createComponent('broken child', 'import { from "broken');
        self::assertNull($checker->hydrates($boundary, ['broken_child' => $broken]));
      },
    ];
  }

  /**
   * Scenarios for the collection serving the frameworks a checker claims.
   *
   * @return array<string, callable():void>
   *   Scenarios keyed by the claim each makes.
   */
  private function collectionScenarios(): array {
    return [
      // This also covers the service tag: without it, neither framework
      // resolves.
      'the collection serves every framework a checker claims, and no others' => function (): void {
        $checkers = $this->container->get(BoundaryCheckers::class);
        \assert($checkers instanceof BoundaryCheckers);

        // Listed by hand rather than read from the constant, so dropping one
        // from the constant fails here.
        self::assertInstanceOf(ReactBoundaryChecker::class, $checkers->get('react'));
        self::assertInstanceOf(ReactBoundaryChecker::class, $checkers->get('preact'));
        self::assertNull($checkers->get('svelte'));
      },
    ];
  }

  /**
   * Gets the checker under test.
   */
  private function checker(): ReactBoundaryChecker {
    $checker = $this->container->get(ReactBoundaryChecker::class);
    \assert($checker instanceof ReactBoundaryChecker);
    return $checker;
  }

}
