<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_ssr\Kernel;

use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\JavaScriptComponent;
use Drupal\canvas\GlobalImports;
use Drupal\canvas\Plugin\Canvas\ComponentSource\JsComponent;
use Drupal\canvas\Render\ImportMapResponseAttachmentsProcessor;
use Drupal\canvas_ssr\JsModules\SiteImportMap;
use Drupal\canvas_ssr\JsParser\JsModuleParser;
use Drupal\canvas_ssr\JsRunner\Exception\MemoryLimitException;
use Drupal\canvas_ssr\JsRunner\RunnerInterface;
use Drupal\canvas_ssr\Renderer;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Extension\ExtensionPathResolver;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\RfcLogLevel;
use Drupal\Core\Render\RendererInterface;
use Drupal\Tests\canvas_ssr\CollectingLogger;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Tests Renderer, everything in SSR above the runner.
 *
 * It decides whether a graph can run, scopes its drupalSettings, registers
 * its modules and reads the result. These tests go through the island
 * element and check the markup, since that's where most of its decisions
 * show. AstroIslandSsrTest covers the element itself.
 *
 * @legacy-covers \Drupal\canvas_ssr\Renderer
 * @legacy-covers \Drupal\canvas_ssr\JsRunner\RunnerInterface
 *
 * @see \Drupal\Tests\canvas_ssr\Kernel\Element\AstroIslandSsrTest
 * @see \Drupal\Tests\canvas_ssr\Kernel\ScenariosTrait
 */
#[RunTestsInSeparateProcesses]
#[Group('canvas')]
#[Group('JavaScriptComponents')]
#[Group('canvas_ssr_requires_runner')]
final class RendererTest extends CanvasSsrKernelTestBase {

  use GlobalAssetLibraryTrait;

  /**
   * Collects what's logged.
   */
  private CollectingLogger $logger;

  /**
   * Normalized HTML for an island that fell back to CSR.
   */
  private const NORMALIZED_CSR_ONLY_ISLAND_HTML = '<canvas-island uid="{uuid}" component-url="{component-url}" component-export="default" renderer-url="{renderer-url}" props="{}" ssr="" client="only" opts="{opts}"><script type="module" src="{client-src}" blocking="render"></script><script type="module" src="{entry-src}" blocking="render"></script></canvas-island>';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installRunner();
    $this->installEntitySchema('canvas_page');
    // Saving the global asset library looks up its files in file_managed.
    $this->installEntitySchema('file');
    $this->logger = new CollectingLogger();
    $factory = $this->container->get(LoggerChannelFactoryInterface::class);
    \assert($factory instanceof LoggerChannelFactoryInterface);
    $factory->addLogger($this->logger);
  }

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container): void {
    parent::register($container);
    // Renderer gets the guard through the counter. Everything else still
    // gets the guard itself.
    $container
      ->register(ResetCountingRunner::class, ResetCountingRunner::class)
      ->addArgument(new Reference(RunnerInterface::class));
    $renderer = $container->getDefinition(Renderer::class);
    foreach ($renderer->getArguments() as $index => $argument) {
      if ($argument instanceof Reference && (string) $argument === RunnerInterface::class) {
        $renderer->replaceArgument($index, new Reference(ResetCountingRunner::class));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    $this->cleanupGlobalAssetLibraryModules();
    parent::tearDown();
  }

  /**
   * Tests what SSR renders, and when an island falls back to CSR.
   *
   * The scenarios share one site install, which costs more than most of them.
   * Each group below explains what its scenarios check.
   */
  public function testRendering(): void {
    $this->assertScenarios([
      ...$this->renderedGraphScenarios(),
      ...$this->repeatIslandScenarios(),
      ...$this->csrFallbackScenarios(),
      ...$this->hydrationScenarios(),
      ...$this->siteImportMapScenarios(),
      ...$this->assetSpecifierScenarios(),
    ]);
  }

  /**
   * Scenarios for what SSR produces for a graph it can render.
   *
   * @return array<string, callable():void>
   *   Scenarios keyed by the claim each makes.
   */
  private function renderedGraphScenarios(): array {
    return [
      // Rendering nothing is a result, not a failure, so it doesn't fall back
      // to CSR. The framework renderer turns each into an empty string, as
      // Preact renders nothing for them in the browser.
      'a component returning nothing renders nothing' => function (): void {
        foreach (['undefined', 'null', 'true', 'false', '""'] as $value) {
          $root = $this->createComponent("returns $value", "export default function Root(){ return $value; }");
          self::assertSame('', \trim($this->renderComponent($root)), "Returning $value.");
        }
      },
      // As Preact renders them in the browser, checked against preact's
      // render() in Chromium.
      'a component returning a number or an array renders its text' => function (): void {
        $cases = [
          '5' => '5',
          '0' => '0',
          '1.5' => '1.5',
          '-0' => '0',
          'NaN' => 'NaN',
          '"hi"' => 'hi',
          '[1, 2]' => '12',
        ];
        foreach ($cases as $value => $text) {
          $root = $this->createComponent("returns $value", "export default function Root(){ return $value; }");
          $output = $this->renderComponent($root);
          self::assertStringNotContainsString('client="only"', $output, "Returning $value.");
          self::assertSame($text, $this->extractSsrMarkup($output), "Returning $value.");
        }
      },
      'an imported dependency renders into the roots markup' => function (): void {
        $dependency_machine_name = $this->randomMachineName();
        $this->createComponent(
          'dependency first-party supported',
          'import { h } from "preact"; export default function Dependency(){ return h("span", null, "child"); }',
          machine_name: $dependency_machine_name,
        );
        $root = $this->createComponent(
          'root first-party supported',
          \sprintf('import { h } from "preact"; import Dependency from "@/components/%s"; export default function Root(){ return h("div", null, "hello", h(Dependency)); }', $dependency_machine_name),
          imports: [$dependency_machine_name],
        );

        self::assertSame('<div>hello<span>child</span></div>', \trim($this->renderComponent($root)));
      },

      // drupalSettings is scoped from the dependencies listed in config,
      // so the root lists its import there. The dependency's data
      // dependencies, and their cacheability, count as the root's.
      "a dependency's data dependencies are injected and bubble" => function (): void {
        $dependency_machine_name = $this->randomMachineName();
        $this->createComponent(
          'dependency with data',
          'export default function Dependency(){ return null; }',
          data_dependencies: ['drupalSettings' => ['v0.baseUrl']],
          machine_name: $dependency_machine_name,
        );
        $root = $this->createComponent(
          'root with dependency data dependencies',
          \sprintf('import { h } from "preact"; import Dependency from "@/components/%s"; export default function Root(){ return h("div", null, h("h1", null, "testing dep"), h(Dependency)); }', $dependency_machine_name),
          imports: [$dependency_machine_name],
        );

        $output = $this->renderComponent($root, cacheability: $cacheability);

        self::assertStringNotContainsString(self::CLIENT_ONLY_ATTRIBUTE, $output);
        self::assertStringContainsString('<h1>testing dep</h1>', $output);
        self::assertContains('config:system.site', $cacheability->getCacheTags());
      },

      // An external component is usually rendered by a frontend app, and
      // Drupal renders nothing. With no frontend configured, Canvas renders
      // its fallback implementation as an island. Its compiled JS must be
      // read from the entity: the client-side normalization omits it for
      // external components.
      //
      // @see \Drupal\canvas\Plugin\Canvas\ComponentSource\JsComponent::renderComponent()
      'an external components fallback implementation' => function (): void {
        $js = 'import { h } from "preact"; export default function Root(){ return h("div", null, "fallback"); }';
        $component = JavaScriptComponent::create([
          'machineName' => $this->randomMachineName(),
          'name' => 'external component retaining a fallback',
          'type' => 'external',
          'status' => TRUE,
          'props' => [],
          'required' => [],
          'slots' => [],
          'js' => ['original' => $js, 'compiled' => $js],
          'css' => ['original' => '', 'compiled' => ''],
        ]);
        $component->save();
        // Without a fallback, it wouldn't become an island at all.
        self::assertTrue($component->hasFallbackImplementation());

        $output = $this->renderComponent($component);

        self::assertStringNotContainsString(self::CLIENT_ONLY_ATTRIBUTE, $output);
        self::assertSame('<div>fallback</div>', $this->extractSsrMarkup($output));
      },
    ];
  }

  /**
   * Scenarios for a repeat island rendering what the first island of it did.
   *
   * Every island registers its graph, and the guard skips registrations
   * the runner already has. So a repeat must still reach a changed source.
   *
   * @see \Drupal\Tests\canvas_ssr\Unit\JsRunner\RunnerGuardTest::testRepeatRegistrationsAreSkipped()
   *
   * @return array<string, callable():void>
   *   Scenarios keyed by the claim each makes.
   */
  private function repeatIslandScenarios(): array {
    return [
      'a second island of one component renders what the first did' => function (): void {
        $root = $this->createComponent(
          'repeated root',
          'import { h } from "preact"; export default function Root(){ return h("div", null, "repeated"); }',
        );

        $first = $this->extractSsrMarkup($this->renderComponent($root));
        $second = $this->extractSsrMarkup($this->renderComponent($root));

        self::assertSame('<div>repeated</div>', $first);
        self::assertSame($first, $second);
      },

      'an unrelated island between two of one component changes neither' => function (): void {
        $a = $this->createComponent(
          'interleaved a',
          'import { h } from "preact"; export default function A(){ return h("div", null, "A"); }',
        );
        $b = $this->createComponent(
          'interleaved b',
          'import { h } from "preact"; export default function B(){ return h("div", null, "B"); }',
        );

        self::assertSame('<div>A</div>', $this->extractSsrMarkup($this->renderComponent($a)));
        self::assertSame('<div>B</div>', $this->extractSsrMarkup($this->renderComponent($b)));
        self::assertSame('<div>A</div>', $this->extractSsrMarkup($this->renderComponent($a)));
        self::assertSame('<div>B</div>', $this->extractSsrMarkup($this->renderComponent($b)));
      },

      // The root's JS is unchanged, so only the dependency's new source
      // tells the two renders apart.
      'a dependency edited between renders is the one that renders' => function (): void {
        $dependency_machine_name = $this->randomMachineName();
        $dependency = $this->createComponent(
          'dependency before the edit',
          'import { h } from "preact"; export default function Dependency(){ return h("span", null, "before"); }',
          machine_name: $dependency_machine_name,
        );
        $root_js = \sprintf(
          'import { h } from "preact"; import Dependency from "@/components/%s"; export default function Root(){ return h("div", null, h(Dependency)); }',
          $dependency_machine_name,
        );
        $root = $this->createComponent('root over an edited dependency', $root_js, imports: [$dependency_machine_name]);

        self::assertSame(
          '<div><span>before</span></div>',
          $this->extractSsrMarkup($this->renderComponent($root)),
        );

        $edited = 'import { h } from "preact"; export default function Dependency(){ return h("span", null, "after"); }';
        $dependency->set('js', ['original' => $edited, 'compiled' => $edited])->save();

        self::assertSame(
          '<div><span>after</span></div>',
          $this->extractSsrMarkup($this->renderComponent($root)),
        );
      },

      // A runner failure may not recur, so its fallback is cached briefly.
      //
      // @see docs/adr/0002-any-failure-falls-back-to-csr.md
      // It's retried soon, but rarely passes, and the component may work in
      // the browser, so its author is told.
      'a render past the time limit falls back for a while, and says so' => function (): void {
        $root = $this->createComponent(
          'renders forever',
          'export default function Root(){ for (;;) {} }',
        );

        $output = $this->renderComponent($root, cacheability: $cacheability);
        $this->assertRenderedClientOnly($output);
        self::assertSame(60, $cacheability->getCacheMaxAge());
        self::assertContains(Renderer::TRANSIENT_ERROR_TAG, $cacheability->getCacheTags());
        $warnings = $this->logger->at((string) RfcLogLevel::WARNING);
        self::assertNotSame([], $warnings);
        self::assertStringContainsString('the ' . $root->id() . ' component on the client, since its server-side render went past a time or memory limit', (string) \end($warnings));
      },

      'a runner failure falls back to CSR for a while' => function (): void {
        $root = $this->createComponent(
          'renders either side of a runner failure',
          'import { h } from "preact"; export default function Root(){ return h("div", null, "survivor"); }',
        );

        ResetCountingRunner::$invokeFailure = new \RuntimeException('The runner stopped.');
        $output = $this->renderComponent($root, cacheability: $cacheability);
        $this->assertRenderedClientOnly($output);
        self::assertSame(60, $cacheability->getCacheMaxAge());
        self::assertContains(Renderer::TRANSIENT_ERROR_TAG, $cacheability->getCacheTags());

        self::assertSame('<div>survivor</div>', $this->extractSsrMarkup($this->renderComponent($root)));
      },

      // Whatever came of it, it may have filled the realm's memory.
      'an island that threw counts as one rendered before' => function (): void {
        $thrower = $this->createComponent(
          'throws before the one that runs out',
          'throw new Error("thrown"); export default function Root(){}',
        );
        $after = $this->createComponent(
          'runs out of memory after one that threw',
          'import { h } from "preact"; export default function Root(){ return h("div", null, "after"); }',
        );
        ResetCountingRunner::$invokeFailure = new \RuntimeException('The runner stopped.');
        $this->renderComponent($thrower);
        $this->assertRenderedClientOnly($this->renderComponent($thrower));

        ResetCountingRunner::$invokeFailure = new MemoryLimitException('The runner ran out of memory.');
        self::assertSame('<div>after</div>', $this->extractSsrMarkup($this->renderComponent($after)));
      },

      // A realm's memory is shared by the islands rendered in it, so the
      // one that runs out may only have been left too little.
      'an island that runs out of memory after others renders once more, in a new realm' => function (): void {
        $first = $this->createComponent(
          'renders before the one that runs out',
          'import { h } from "preact"; export default function Root(){ return h("div", null, "first"); }',
        );
        $second = $this->createComponent(
          'runs out of memory after another',
          'import { h } from "preact"; export default function Root(){ return h("div", null, "second"); }',
        );
        self::assertSame('<div>first</div>', $this->extractSsrMarkup($this->renderComponent($first)));
        $warnings = \count($this->logger->at((string) RfcLogLevel::WARNING));

        ResetCountingRunner::$invokeFailure = new MemoryLimitException('The runner ran out of memory.');
        $output = $this->renderComponent($second, cacheability: $cacheability);
        self::assertSame('<div>second</div>', $this->extractSsrMarkup($output));
        self::assertSame(Cache::PERMANENT, $cacheability->getCacheMaxAge());
        self::assertNotContains(Renderer::TRANSIENT_ERROR_TAG, $cacheability->getCacheTags());
        // The warning names the islands it rendered after, not it alone.
        $new = \array_slice($this->logger->at((string) RfcLogLevel::WARNING), $warnings);
        self::assertCount(1, $new);
        self::assertStringContainsString('The ' . $second->id() . ' component ran out of memory on its first server-side render', (string) $new[0]);
        self::assertMatchesRegularExpression('/memory: .*\\b' . $first->id() . '\\. One of/', (string) $new[0]);
      },

      // It had a realm's memory to itself, so it would run out again.
      'one that runs out again falls back as a script failure does, and says so' => function (): void {
        $first = $this->createComponent(
          'renders before the one that runs out twice',
          'import { h } from "preact"; export default function Root(){ return h("div", null, "first"); }',
        );
        $second = $this->createComponent(
          'runs out of memory twice',
          'import { h } from "preact"; export default function Root(){ return h("div", null, "second"); }',
        );
        self::assertSame('<div>first</div>', $this->extractSsrMarkup($this->renderComponent($first)));
        $warnings = \count($this->logger->at((string) RfcLogLevel::WARNING));

        ResetCountingRunner::$invokeFailure = new MemoryLimitException('The runner ran out of memory.');
        ResetCountingRunner::$invokeFailureTimes = 2;
        $output = $this->renderComponent($second, cacheability: $cacheability);
        $this->assertRenderedClientOnly($output);
        self::assertSame(Cache::PERMANENT, $cacheability->getCacheMaxAge());
        self::assertNotContains(Renderer::TRANSIENT_ERROR_TAG, $cacheability->getCacheTags());
        self::assertContains('config:canvas.js_component.' . $second->id(), $cacheability->getCacheTags());
        self::assertNull(ResetCountingRunner::$invokeFailure, 'It rendered more than twice.');
        $new = \array_slice($this->logger->at((string) RfcLogLevel::WARNING), $warnings);
        self::assertCount(1, $new);
        self::assertStringContainsString('the ' . $second->id() . ' component on the client, since its server-side render went past a time or memory limit', (string) $new[0]);
      },

      // Nothing else used its memory, so it would only run out again.
      'one that runs out in a realm of its own renders once' => function (): void {
        $only = $this->createComponent(
          'runs out of memory alone',
          'import { h } from "preact"; export default function Root(){ return h("div", null, "only"); }',
        );
        ResetCountingRunner::$invokeFailure = new MemoryLimitException('The runner ran out of memory.');
        $output = $this->renderComponent($only, cacheability: $cacheability);
        $this->assertRenderedClientOnly($output);
        self::assertNull(ResetCountingRunner::$invokeFailure);
        self::assertSame(Cache::PERMANENT, $cacheability->getCacheMaxAge());
        self::assertNotContains(Renderer::TRANSIENT_ERROR_TAG, $cacheability->getCacheTags());

        // That failure left the realm without islands.
        ResetCountingRunner::$invokeFailure = new MemoryLimitException('The runner ran out of memory.');
        ResetCountingRunner::$invokeFailureTimes = 2;
        $this->assertRenderedClientOnly($this->renderComponent($only));
        self::assertNotNull(ResetCountingRunner::$invokeFailure, 'It rendered twice.');
        ResetCountingRunner::$invokeFailure = NULL;
        ResetCountingRunner::$invokeFailureTimes = 1;
      },

      // A script failure recurs with the same inputs, so its fallback is
      // cached like a render. It leaves the runner working, so nothing resets
      // it.
      'a script failure keeps the runner' => function (): void {
        $root = $this->createComponent(
          'renders around script failures',
          'import { h } from "preact"; export default function Root(){ return h("div", null, "kept"); }',
        );
        $unresolvable = $this->createComponent(
          'imports something SSR cannot resolve',
          'import lodash from "lodash"; export default function Root(){ return null; }',
        );

        self::assertSame('<div>kept</div>', $this->extractSsrMarkup($this->renderComponent($root)));
        $resets = ResetCountingRunner::$resets;

        foreach ([$this->throwingComponent(), $this->throwingOnEvaluateComponent(), $unresolvable] as $failing) {
          $output = $this->renderComponent($failing, cacheability: $cacheability);
          $this->assertRenderedClientOnly($output);
          self::assertSame(Cache::PERMANENT, $cacheability->getCacheMaxAge(), (string) $failing->label());
          self::assertContains(SiteImportMap::ASSET_LIBRARY_TAG, $cacheability->getCacheTags(), (string) $failing->label());
        }
        self::assertSame($resets, ResetCountingRunner::$resets, 'A script failure reset the runner.');

        self::assertSame('<div>kept</div>', $this->extractSsrMarkup($this->renderComponent($root)));
      },

      // PHP can't decode a lone surrogate, so markup with one can't come back
      // as markup. It recurs with the same inputs, and the runner answered
      // as it should, so it's a script failure, not a runner failure.
      'markup with a lone surrogate falls back as a script failure' => function (): void {
        $root = $this->createComponent(
          'renders a lone surrogate',
          'import { h } from "preact"; export default function Root(){ return h("p", null, "half \\uD800 a pair"); }',
        );

        $output = $this->renderComponent($root, cacheability: $cacheability);
        $this->assertRenderedClientOnly($output);
        self::assertSame(Cache::PERMANENT, $cacheability->getCacheMaxAge());
        self::assertContains(SiteImportMap::ASSET_LIBRARY_TAG, $cacheability->getCacheTags());
      },

      // The markup holds every component in the graph, so saving any of them
      // must invalidate it. This renders as a page does, through Canvas's
      // component source, which adds the components' cacheability.
      'saving any component in the graph invalidates the island' => function (): void {
        $leaf_name = $this->randomMachineName();
        $leaf = $this->createComponent(
          'cached leaf',
          'import { h } from "preact"; export default function Leaf(){ return h("span", null, "leaf"); }',
          machine_name: $leaf_name,
        );
        $middle_name = $this->randomMachineName();
        $middle_js = \sprintf('import { h } from "preact"; import Leaf from "@/components/%s"; export default function Middle(){ return h("div", null, h(Leaf)); }', $leaf_name);
        $middle = $this->createComponent('cached middle', $middle_js, imports: [$leaf_name], machine_name: $middle_name);
        $root_js = \sprintf('import { h } from "preact"; import Middle from "@/components/%s"; export default function Root(){ return h("section", null, h(Middle)); }', $middle_name);
        $root = $this->createComponent('cached root', $root_js, imports: [$middle_name]);

        $output = $this->renderThroughComponentSource($root, $cacheability);

        self::assertSame('<section><div><span>leaf</span></div></section>', $this->extractSsrMarkup($output));
        foreach ([$root, $middle, $leaf] as $component) {
          foreach ($component->getCacheTags() as $tag) {
            self::assertContains($tag, $cacheability->getCacheTags(), (string) $component->label());
          }
        }
      },

      'components that import each other' => function (): void {
        $a_name = $this->randomMachineName();
        $b_name = $this->randomMachineName();
        $a_js = \sprintf('import { h } from "preact"; import B from "@/components/%s"; export default function A(){ return h("div", null, h(B)); }', $b_name);
        $b_js = \sprintf('import { h } from "preact"; import "@/components/%s"; export default function B(){ return h("p", null, "b"); }', $a_name);
        $a = $this->createComponent('a imports b', $a_js, machine_name: $a_name);
        $this->createComponent('b imports a', $b_js, imports: [$a_name], machine_name: $b_name);
        $a->updateFromClientSide([
          'sourceCodeJs' => $a_js,
          'compiledJs' => $a_js,
          'importedJsComponents' => [$b_name],
        ]);
        $a->save();

        self::assertSame('<div><p>b</p></div>', $this->extractSsrMarkup($this->renderComponent($a)));
      },
    ];
  }

  /**
   * Scenarios for what makes an island fall back to CSR.
   *
   * Each checks the whole island, which must match what Canvas renders
   * without canvas_ssr.
   *
   * @return array<string, callable():void>
   *   Scenarios keyed by the claim each makes.
   */
  private function csrFallbackScenarios(): array {
    return [
      // The runner could render it, but SSR can't tell whether it hydrates,
      // so rendering it could ship markup that never does. A parse past the
      // parser's memory limit fails the same way until the component
      // changes, so the fallback is cached as a render is.
      'a component whose code cannot be parsed falls back to CSR' => function (): void {
        $nested = \str_repeat('[', 20000) . \str_repeat(']', 20000);
        $root = $this->createComponent(
          'too deeply nested to parse',
          "'use client';\nimport { h } from 'preact';\nconst nested = $nested;\nexport default function Root(){ return h('div', null, 'parsed'); }",
        );

        $output = $this->renderComponent($root, cacheability: $cacheability);
        $this->assertRenderedClientOnly($output);
        self::assertSame(Cache::PERMANENT, $cacheability->getCacheMaxAge());
        self::assertContains('config:canvas.js_component.' . $root->id(), $cacheability->getCacheTags());
        self::assertNotContains(Renderer::TRANSIENT_ERROR_TAG, $cacheability->getCacheTags());
      },

      // Nothing checks this up front. The import fails to resolve when it's
      // reached.
      'a dependency importing something SSR cannot resolve' => function (): void {
        $dependency_machine_name = $this->randomMachineName();
        $this->createComponent(
          'dependency with unsupported import',
          'import lodash from "lodash"; export default function Dependency(){ return null; }',
          machine_name: $dependency_machine_name,
        );
        $root = $this->createComponent(
          'root with dependency unsupported import',
          \sprintf('import Dependency from "@/components/%s"; export default function Root(){ return null; }', $dependency_machine_name),
          imports: [$dependency_machine_name],
        );

        $this->assertRenderedClientOnly($this->renderComponent($root));
      },

      // The main module catches this and returns the error.
      'a component that throws while rendering' => function (): void {
        $this->assertRenderedClientOnly($this->renderComponent($this->throwingComponent()));
      },

      // invoke() throws a ScriptException. Timeouts are the runner's job,
      // tested in the conformance suite.
      //
      // @see \Drupal\Tests\canvas_ssr\Unit\JsRunner\RunnerConformanceTestBase::testRunnerInterruptsWhatNeverFinishes()
      'a component that throws while evaluating' => function (): void {
        $this->assertRenderedClientOnly($this->renderComponent($this->throwingOnEvaluateComponent()));
      },

      'a component with no compiled JS to run' => function (): void {
        $root = $this->createComponent(
          'root without compiled js',
          'import { h } from "preact"; export default function Root(){ return h("div", null, "not compiled"); }',
          compiled: '',
        );

        $this->assertRenderedClientOnly($this->renderComponent($root));
      },

      // A syntax error fails the same way until the component changes too.
      'a component with a syntax error, cached as a render' => function (): void {
        $root = $this->createComponent(
          'root that cannot be parsed',
          'import { h } from "preact"; export default function Root(){ return h("div", null, "unparsable"',
        );

        $output = $this->renderComponent($root, cacheability: $cacheability);
        $this->assertRenderedClientOnly($output);
        self::assertSame(Cache::PERMANENT, $cacheability->getCacheMaxAge());
        self::assertContains('config:canvas.js_component.' . $root->id(), $cacheability->getCacheTags());
        self::assertNotContains(Renderer::TRANSIENT_ERROR_TAG, $cacheability->getCacheTags());
      },

      // What PHP's memory_limit leaves depends on what else the request did,
      // so a parse that runs out of it is a transient error.
      'a parse that ran out of what the request left is a transient error' => function (): void {
        $root = $this->createComponent(
          'root too deep for what the request leaves',
          'export default function Root(){ return ' . \str_repeat('[', 1000) . \str_repeat(']', 1000) . '; }',
        );

        $previous = (string) \ini_get('memory_limit');
        self::assertNotFalse(\ini_set('memory_limit', (string) (\memory_get_usage(TRUE) + JsModuleParser::MEMORY_MARGIN + 4 * 1024 * 1024)));
        try {
          $output = $this->renderComponent($root, cacheability: $cacheability);
        }
        finally {
          \ini_set('memory_limit', $previous);
        }
        $this->assertRenderedClientOnly($output);
        self::assertSame(60, $cacheability->getCacheMaxAge());
        self::assertContains(Renderer::TRANSIENT_ERROR_TAG, $cacheability->getCacheTags());
      },

      // The same component renders under preact, so the framework is the
      // only reason to bail. No boundary checker handles svelte.
      'a framework SSR does not support' => function (): void {
        $root = $this->createComponent(
          'component built with an unsupported framework',
          'import { h } from "preact"; export default function Root(){ return h("div", null, "unsupported"); }',
        );

        $output = $this->renderComponent($root, framework: 'svelte');

        $this->assertRenderedClientOnly($output);
        // The island keeps the framework it declared.
        self::assertStringContainsString('&quot;value&quot;:&quot;svelte&quot;', $output);
      },

      // Islands share a runner, so one broken component mustn't break SSR
      // for the rest of the page.
      'a failed render leaves the islands after it untouched' => function (): void {
        $safe_component = $this->createComponent(
          'safe after failure',
          '"use client"; import { h } from "preact"; export default function Root(){ return h("div", null, "safe"); }',
        );

        $this->assertRenderedClientOnly($this->renderComponent($this->throwingComponent()));

        self::assertSame(
          '<canvas-island uid="{uuid}" component-url="{component-url}" component-export="default" renderer-url="{renderer-url}" props="{}" ssr="" client="load" opts="{opts}" prefix="{prefix}"><div>safe</div></canvas-island>',
          $this->normalizeIslandHtml($this->renderComponent($safe_component)),
        );
      },
    ];
  }

  /**
   * Scenarios for an island hydrating when its graph has a client boundary.
   *
   * @return array<string, callable():void>
   *   Scenarios keyed by the claim each makes.
   */
  private function hydrationScenarios(): array {
    return [
      'the root is a client boundary' => function (): void {
        $root = $this->createComponent(
          'root with use client',
          '"use client"; import { h } from "preact"; export default function Root(){ return h("div", null, "to be hydrated"); }',
        );

        self::assertSame(
          '<canvas-island uid="{uuid}" component-url="{component-url}" component-export="default" renderer-url="{renderer-url}" props="{}" ssr="" client="load" opts="{opts}" prefix="{prefix}"><div>to be hydrated</div></canvas-island>',
          $this->normalizeIslandHtml($this->renderComponent($root)),
        );
      },

      // Hydration follows the dependencies listed in config, so these list
      // their imports there.
      'a component two levels down is a client boundary' => function (): void {
        $leaf_machine_name = $this->randomMachineName();
        $this->createComponent(
          'leaf with use client',
          '"use client"; import { h } from "preact"; export default function Leaf(){ return h("span", null, "leaf"); }',
          machine_name: $leaf_machine_name,
        );

        $middle_machine_name = $this->randomMachineName();
        $this->createComponent(
          'middle imports leaf',
          \sprintf('import { h } from "preact"; import Leaf from "@/components/%s"; export default function Middle(){ return h("div", null, h(Leaf)); }', $leaf_machine_name),
          imports: [$leaf_machine_name],
          machine_name: $middle_machine_name,
        );

        $root = $this->createComponent(
          'root imports middle',
          \sprintf('import { h } from "preact"; import Middle from "@/components/%s"; export default function Root(){ return h("section", null, h(Middle)); }', $middle_machine_name),
          imports: [$middle_machine_name],
        );

        self::assertSame(
          '<canvas-island uid="{uuid}" component-url="{component-url}" component-export="default" renderer-url="{renderer-url}" props="{}" ssr="" client="load" opts="{opts}" prefix="{prefix}"><section><div><span>leaf</span></div></section></canvas-island>',
          $this->normalizeIslandHtml($this->renderComponent($root)),
        );
      },
    ];
  }

  /**
   * Scenarios for what a render resolves an asset specifier to, mapped or not.
   *
   * Canvas compiles `import logo from "@/assets/logo.svg"` to
   * `const logo = import.meta.resolve("@/assets/logo.svg")`, so assets are
   * never imported. The runner resolves them with the SSR import map, which
   * has every entry of the site import map the browser gets.
   *
   * The conformance suite covers how a runner resolves. This checks the
   * runner gets the site import map Canvas builds.
   *
   * @see \Drupal\Tests\canvas_ssr\Unit\JsRunner\RunnerConformanceTestBase
   *
   * @return array<string, callable():void>
   *   Scenarios keyed by the claim each makes.
   */
  private function assetSpecifierScenarios(): array {
    return [
      'an asset the import map covers' => function (): void {
        // A pushed image reaches the import map from the asset library's
        // "assets". It isn't a module; it only gets resolved.
        $this->setGlobalAssetLibraryEntries(assets: [
          ['name' => '@/assets/logo.svg', 'uri' => 'public://canvas/assets/logo.svg'],
        ]);

        $component = $this->createComponent(
          'Asset consumer',
          <<<'JS'
          import logo from "@/assets/logo.svg";
          export default function Root() {
            return <img src={logo} alt="Logo" />;
          }
          JS,
          // What Canvas compiles the source above to.
          <<<'JS'
          import { jsx as _jsx } from "react/jsx-runtime";
          const logo = import.meta.resolve("@/assets/logo.svg");
          export default function Root() {
            return _jsx("img", { src: logo, alt: "Logo" });
          }
          JS,
        );

        $output = $this->renderComponent($component);

        // Compare with the map, not a literal: the cache-busting query
        // changes with the Canvas version.
        $global_imports = $this->container->get(GlobalImports::class);
        \assert($global_imports instanceof GlobalImports);
        $expected = $global_imports->getImportMap()[ImportMapResponseAttachmentsProcessor::GLOBAL_IMPORTS]['@/assets/logo.svg'];
        self::assertStringContainsString('?', $expected);

        self::assertStringNotContainsString(self::CLIENT_ONLY_ATTRIBUTE, $output);
        self::assertSame(
          \sprintf('<img src="%s" alt="Logo"/>', $expected),
          $this->extractSsrMarkup($output),
        );
      },

      // The browser throws for an unmapped bare specifier too, so SSR falls
      // back to CSR rather than make up a URL.
      'an asset no import map entry matches' => function (): void {
        $component = $this->createComponent(
          'Missing asset consumer',
          <<<'JS'
          import missing from "@/assets/missing.svg";
          export default function Root() {
            return <img src={missing} alt="Missing" />;
          }
          JS,
          <<<'JS'
          import { jsx as _jsx } from "react/jsx-runtime";
          const missing = import.meta.resolve("@/assets/missing.svg");
          export default function Root() {
            return _jsx("img", { src: missing, alt: "Missing" });
          }
          JS,
        );

        self::assertStringContainsString(
          self::CLIENT_ONLY_ATTRIBUTE,
          $this->renderComponent($component),
        );
      },
    ];
  }

  /**
   * Scenarios for SSR rendering only islands whose map is the site import map.
   *
   * They compare with the site import map as it is now, so they run before
   * anything that pushes to the asset library.
   *
   * @return array<string, callable():void>
   *   Scenarios keyed by the claim each makes.
   *
   * @see \Drupal\canvas_ssr\Element\AstroIslandSsr
   */
  private function siteImportMapScenarios(): array {
    $component = $this->createComponent(
      'Plain',
      'export default function Root() { return <p>Plain</p>; }',
      'import { jsx as _jsx } from "react/jsx-runtime"; export default function Root() { return _jsx("p", { children: "Plain" }); }',
    );
    $global_imports = $this->container->get(GlobalImports::class);
    \assert($global_imports instanceof GlobalImports);
    $site_map = $global_imports->getImportMap();
    $imports_key = ImportMapResponseAttachmentsProcessor::GLOBAL_IMPORTS;
    $scopes_key = ImportMapResponseAttachmentsProcessor::SCOPED_IMPORTS;

    return [
      // Scopes differ per island by design.
      'an island with its own scopes still renders on the server' => function () use ($component, $site_map, $scopes_key): void {
        $site_map[$scopes_key] = ['/some/component.js' => ['lib' => '/lib.js']];
        $output = $this->renderComponent($component, import_maps: $site_map);

        self::assertSame('<p>Plain</p>', $this->extractSsrMarkup($output));
      },

      // Like a theme's alter hook would, or an island built on the other
      // side of an asset library push.
      'an island whose imports differ falls back to CSR' => function () use ($component, $site_map, $imports_key): void {
        $site_map[$imports_key]['extra'] = '/extra.js';

        self::assertStringContainsString(
          self::CLIENT_ONLY_ATTRIBUTE,
          $this->renderComponent($component, import_maps: $site_map),
        );
      },

      'an island with no import map falls back to CSR' => function () use ($component): void {
        self::assertStringContainsString(
          self::CLIENT_ONLY_ATTRIBUTE,
          $this->renderComponent($component, import_maps: []),
        );
      },

      // A push changes what imports resolve to.
      'the markup varies with the asset library' => function () use ($component): void {
        $output = $this->renderComponent($component, cacheability: $cacheability);

        self::assertStringNotContainsString(self::CLIENT_ONLY_ATTRIBUTE, $output);
        self::assertContains(SiteImportMap::ASSET_LIBRARY_TAG, $cacheability->getCacheTags());
      },
    ];
  }

  /**
   * Asserts an island fell back to CSR, matching what Canvas renders alone.
   */
  private function assertRenderedClientOnly(string $output): void {
    self::assertSame(
      self::NORMALIZED_CSR_ONLY_ISLAND_HTML,
      $this->normalizeIslandHtml($output),
    );
  }

  /**
   * Creates a component whose render throws.
   */
  private function throwingComponent(): JavaScriptComponent {
    return $this->createComponent(
      'throws during ssr',
      'export default function Root(){ throw new Error("SSR crash"); }',
    );
  }

  /**
   * Creates a component whose module throws when it's evaluated.
   */
  private function throwingOnEvaluateComponent(): JavaScriptComponent {
    return $this->createComponent(
      'throws while evaluating',
      'throw new Error("SSR load crash"); export default function Root(){ return null; }',
    );
  }

  /**
   * Renders a component as a page does, through Canvas's component source.
   *
   * @param \Drupal\canvas\Entity\JavaScriptComponent $js_component
   *   The component.
   * @param \Drupal\Core\Cache\CacheableMetadata|null $cacheability
   *   Set to the island's bubbled cacheability.
   *
   * @param-out \Drupal\Core\Cache\CacheableMetadata $cacheability
   *
   * @return string
   *   The HTML.
   */
  private function renderThroughComponentSource(JavaScriptComponent $js_component, ?CacheableMetadata &$cacheability = NULL): string {
    $component = Component::load(JsComponent::componentIdFromJavascriptComponentId((string) $js_component->id()));
    \assert($component instanceof Component);
    $build = $component->getComponentSource()->renderComponent([], [], $this->container->get('uuid')->generate(), FALSE);
    $renderer = $this->container->get('renderer');
    \assert($renderer instanceof RendererInterface);
    $output = (string) $renderer->renderInIsolation($build);
    $cacheability = CacheableMetadata::createFromRenderArray($build);

    return $output;
  }

  /**
   * Normalizes dynamic island values so exact HTML can be asserted.
   */
  private function normalizeIslandHtml(string $html): string {
    $normalized = trim($html);
    $normalized = preg_replace('/\s+/', ' ', $normalized) ?? '';
    $normalized = preg_replace('/ uid="[^"]*"/', ' uid="{uuid}"', $normalized) ?? '';
    $normalized = preg_replace('/ prefix="[^"]*"/', ' prefix="{prefix}"', $normalized) ?? '';
    $normalized = preg_replace('/ component-url="[^"]*"/', ' component-url="{component-url}"', $normalized) ?? '';
    $normalized = preg_replace('/ renderer-url="[^"]*"/', ' renderer-url="{renderer-url}"', $normalized) ?? '';
    $normalized = preg_replace('/ opts="[^"]*"/', ' opts="{opts}"', $normalized) ?? '';
    $canvas_path = $this->container->get(ExtensionPathResolver::class)->getPath('module', 'canvas');
    $normalized = preg_replace('#<script type="module" src="/' . preg_quote($canvas_path, '#') . '/packages/astro-hydration/dist/(?:canvas-)?client\.js\?[^"]+" blocking="render"></script>#', '<script type="module" src="{client-src}" blocking="render"></script>', $normalized) ?? '';
    $normalized = preg_replace('#<script type="module" src="/canvas/api/v0/auto-saves/js/js_component/[^"]+" blocking="render"></script>#', '<script type="module" src="{entry-src}" blocking="render"></script>', $normalized) ?? '';
    return $normalized;
  }

}
