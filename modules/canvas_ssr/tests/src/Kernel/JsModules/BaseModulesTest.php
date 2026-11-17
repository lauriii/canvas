<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_ssr\Kernel\JsModules;

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\CodeComponentDataProvider;
use Drupal\canvas\Entity\AssetLibrary;
use Drupal\canvas\Entity\JavaScriptComponent;
use Drupal\canvas\GlobalImports;
use Drupal\canvas_ssr\JsModules\AllProvidedModules;
use Drupal\canvas_ssr\JsModules\BaseModules;
use Drupal\canvas_ssr\JsModules\Provider\SsrOnlyModuleProvider;
use Drupal\canvas_ssr\JsRunner\RunnerInterface;
use Drupal\canvas_ssr\Renderer;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\StorageCacheInterface;
use Drupal\Core\Config\StorageInterface;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Tests\canvas_ssr\FileAccessRecorder;
use Drupal\Tests\canvas_ssr\Kernel\CanvasSsrKernelTestBase;
use Drupal\Tests\canvas_ssr\Kernel\SpyRunner;
use Drupal\Tests\canvas_ssr\Kernel\TaggedModuleProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * Tests the base holds what renders register, and how renders use it.
 *
 * The tests compare against what a render passes the runner through the
 * guard and the selector.
 *
 * @see docs/adr/0010-a-base-is-built-once-and-never-changes-output.md
 *
 * @legacy-covers \Drupal\canvas_ssr\JsModules\BaseModules
 *
 * @see \Drupal\Tests\canvas_ssr\Kernel\ScenariosTrait
 */
#[RunTestsInSeparateProcesses]
#[Group('canvas')]
#[Group('JavaScriptComponents')]
final class BaseModulesTest extends CanvasSsrKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // Saving the global asset library records file usage.
    $this->installEntitySchema('file');
    self::resetSpies();
  }

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container): void {
    parent::register($container);
    $container
      ->register(SpyRunner::class, SpyRunner::class)
      ->addTag('canvas_ssr.runner', ['priority' => 1000]);
    $container
      ->register(TaggedModuleProvider::class, TaggedModuleProvider::class)
      ->addTag('canvas_ssr.js_module_provider', ['priority' => 200]);
    self::persistDefaultCache($container);
  }

  /**
   * Tests what the base covers, and how renders use it.
   *
   * The scenarios share one site install, but each starts over in a later
   * request on a site with no exposed components. Each group below explains
   * what its scenarios check.
   */
  public function testBase(): void {
    $this->assertScenarios([
      ...$this->coverageScenarios(),
      ...$this->renderFromBaseScenarios(),
      ...$this->noBaseScenarios(),
      ...$this->midRequestChangeScenarios(),
      ...$this->idScenarios(),
    ]);
  }

  /**
   * Scenarios for what the base holds.
   *
   * @return array<string, callable():void>
   *   Scenarios keyed by the claim each makes.
   */
  private function coverageScenarios(): array {
    return [
      'the base has every exposed component, with its graph' => function (): void {
        $this->startOver();
        $dependency = $this->createComponent(
          'Dependency',
          'import { h } from "preact"; export default function D(){ return h("span", null, "d"); }',
          machine_name: 'dependency',
        );
        $source = 'import { h } from "preact"; import D from "@/components/dependency"; export default function R(){ return h("div", null, h(D)); }';
        $root = $this->createComponent('Root', $source, imports: [$dependency->id()], machine_name: 'root');
        $unexposed = $this->createComponent(
          'Unexposed',
          'export default function U(){ return null; }',
          machine_name: 'unexposed',
        );
        $unexposed->disable()->save();

        $this->renderComponent($root);

        self::assertCount(1, SpyRunner::$invoked);
        $base = SpyRunner::$base;
        self::assertNotNull($base);
        self::assertContains(SpyRunner::$invoked[0], $base['compile'] ?? []);
        // The render uses the base alone.
        self::assertSame([], SpyRunner::$modules);
        foreach (['h("span", null, "d")', 'h("div", null, h(D))'] as $code) {
          $holding = \array_filter($base['modules'], static fn(string $source): bool => \str_contains($source, $code));
          self::assertCount(1, $holding, $code);
        }
        $provided = $this->container->get(AllProvidedModules::class);
        \assert($provided instanceof AllProvidedModules);
        self::assertSame($provided->sources(), \array_intersect_key($base['modules'], $provided->sources()));
        // A main module and a libraries module to compile for each exposed
        // component. None evaluates in the realm renders start from.
        self::assertCount(4, $base['compile'] ?? []);
        self::assertSame([], \array_intersect($base['compile'] ?? [], $base['preload']));
        // The realm setup module, the site data, a module for each library
        // the components import, and the framework renderer and hydration
        // data module, then the warm-up.
        self::assertSame(SsrOnlyModuleProvider::REALM_SETUP_PATH, $base['preload'][0]);
        self::assertStringStartsWith('/_canvas_ssr/base/site-data/', $base['preload'][1]);
        self::assertStringContainsString('setDrupalSettings(', $base['modules'][$base['preload'][1]] ?? '');
        self::assertSame(['@/canvas_ssr/renderer/preact', '@/canvas_ssr/hydration-data', 'preact'], self::libraryImports($base));
        self::assertSame(SsrOnlyModuleProvider::TW_MERGE_WARMUP_PATH, \end($base['preload']));
        self::assertCount(6, $base['preload']);
        self::assertNotContains('export default function U(){ return null; }', $base['modules']);
      },

      // Whether it hydrates can't be told, so it renders on its own, and
      // falls back to CSR there.
      'a component that cannot be parsed is left out of the base' => function (): void {
        $this->startOver();
        $source = 'import { h } from "preact"; export default function Broken(){ return h("div", null, [}';
        $broken = $this->createComponent('Broken', $source, machine_name: 'broken');

        $this->renderComponent($broken);

        $base = SpyRunner::$base;
        self::assertNotNull($base);
        self::assertNotContains($source, $base['modules']);
        self::assertSame([], SpyRunner::$invoked);
      },

      'an import cycle renders, and is in the base' => function (): void {
        $this->startOver();
        $a_source = 'import B from "@/components/cycle_b"; export default function CycleA(){ return null; }';
        $b_source = 'import A from "@/components/cycle_a"; export default function CycleB(){ return null; }';
        $a = $this->createComponent('A', $a_source, machine_name: 'cycle_a');
        $this->createComponent('B', $b_source, imports: ['cycle_a'], machine_name: 'cycle_b');
        $a->updateFromClientSide([
          'sourceCodeJs' => $a_source,
          'compiledJs' => $a_source,
          'importedJsComponents' => ['cycle_b'],
        ]);
        $a->save();
        $source = 'export default function Plain(){ return null; }';
        $this->createComponent('Plain', $source, machine_name: 'plain');

        $this->renderComponent($a);

        self::assertCount(1, SpyRunner::$invoked);
        self::assertSame([], SpyRunner::$modules);
        $base = SpyRunner::$base;
        self::assertNotNull($base);
        // A main module and a libraries module to compile for each
        // component.
        self::assertCount(6, $base['compile'] ?? []);
        self::assertContains(SpyRunner::$invoked[0], $base['compile'] ?? []);
        // Neither imports a library, so only the framework renderer and the
        // hydration data module are.
        self::assertSame(['@/canvas_ssr/renderer/preact', '@/canvas_ssr/hydration-data'], self::libraryImports($base));
        $cycle = \array_filter(
          $base['modules'],
          static fn(string $source): bool => \str_contains($source, 'function Cycle'),
        );
        self::assertCount(2, $cycle);
      },

      'base modules reaching tailwind-merge end with the one warming it' => function (): void {
        $this->startOver();
        $source = 'import { h } from "preact"; import { cn } from "@/lib/utils"; export default function C(){ return h("p", { class: cn("px-2", "px-3") }); }';
        $component = $this->createComponent('Classy', $source, machine_name: 'classy');

        $this->renderComponent($component);

        $base = SpyRunner::$base;
        self::assertNotNull($base);
        $warmup = SsrOnlyModuleProvider::TW_MERGE_WARMUP_PATH;
        self::assertSame($warmup, \end($base['preload']));
        self::assertSame(['@/canvas_ssr/renderer/preact', '@/canvas_ssr/hydration-data', '@/lib/utils', 'preact'], self::libraryImports($base));
        self::assertStringContainsString('twMerge(', $base['modules'][$warmup] ?? '');
        // What it imports is in the base too.
        $tailwind_merge = SpyRunner::$importMap['imports']['tailwind-merge'] ?? NULL;
        self::assertIsString($tailwind_merge);
        self::assertArrayHasKey(self::moduleUrlOf($tailwind_merge), $base['modules']);
        // A render never invokes it.
        self::assertNotContains($warmup, SpyRunner::$invoked);
      },

      'every provided module is in the base, imported or not' => function (): void {
        $this->startOver();
        $source = 'import { h } from "preact"; export default function P(){ return h("p", null, "p"); }';
        $component = $this->createComponent('Aliased', $source, machine_name: 'aliased');

        $this->renderComponent($component);

        $base = SpyRunner::$base;
        self::assertNotNull($base);
        // No component imports swr or tailwind-merge, so a render never
        // registers them. The SSR import map still maps them, so the base
        // has them.
        $swr = SsrOnlyModuleProvider::PATH_PREFIX . 'swr-shim.js';
        self::assertSame($swr, SpyRunner::$importMap['imports']['swr'] ?? NULL);
        self::assertArrayHasKey($swr, $base['modules']);
        $tailwind_merge = SpyRunner::$importMap['imports']['tailwind-merge'] ?? NULL;
        self::assertIsString($tailwind_merge);
        self::assertArrayHasKey(self::moduleUrlOf($tailwind_merge), $base['modules']);
        // Only what the component imports is evaluated.
        self::assertSame(['@/canvas_ssr/renderer/preact', '@/canvas_ssr/hydration-data', 'preact'], self::libraryImports($base));
      },

      // Runners may take the base only as text, so one such component would
      // otherwise leave every render without a base. It can't be parsed
      // either, so it falls back to CSR.
      'a component with a source that isn\'t UTF-8 is left out of the base' => function (): void {
        $this->startOver();
        $source = 'import { h } from "preact"; export default function A(){ return h("p", null, "a"); }';
        $fine = $this->createComponent('Fine', $source, machine_name: 'utf8');
        $odd_source = \str_replace('"a"', '"b"', $source);
        $this->createComponent('Odd', $odd_source, machine_name: 'latin1');
        // Schema validation refuses such a source, so it's written around it.
        $latin1 = "// Caf\xE9\n" . $odd_source;
        $storage = $this->container->get(StorageCacheInterface::class);
        \assert($storage instanceof StorageInterface);
        $data = $storage->read('canvas.js_component.latin1');
        self::assertIsArray($data);
        $storage->write('canvas.js_component.latin1', [
          'js' => ['original' => $latin1, 'compiled' => $latin1],
        ] + $data);
        $this->container->get(ConfigFactoryInterface::class)->reset('canvas.js_component.latin1');
        $components = $this->container->get(EntityTypeManagerInterface::class)->getStorage('js_component');
        $components->resetCache(['latin1']);
        $odd = $components->load('latin1');
        \assert($odd instanceof JavaScriptComponent);
        self::assertSame($latin1, $odd->getJs());
        $renderer = $this->ssrRenderer();

        $renderer->render($fine, [], [], 'preact');
        $renderer->render($odd, [], [], 'preact');

        $base = SpyRunner::$base;
        self::assertNotNull($base);
        // Only the fine component: its main module and libraries module.
        self::assertSame([SpyRunner::$invoked[0], SpyRunner::$librariesInvoked[0]], $base['compile'] ?? []);
        self::assertNotContains($latin1, $base['modules']);
        self::assertNotContains($latin1, SpyRunner::$modules);
        self::assertCount(1, SpyRunner::$invoked);
        // So startOver() can save it.
        $storage->write('canvas.js_component.latin1', $data);
        $this->container->get(ConfigFactoryInterface::class)->reset('canvas.js_component.latin1');
        $components->resetCache(['latin1']);
      },
    ];
  }

  /**
   * Scenarios for how renders use the base in use.
   *
   * @return array<string, callable():void>
   *   Scenarios keyed by the claim each makes.
   */
  private function renderFromBaseScenarios(): array {
    return [
      // Nothing is registered, and hydration is what a render would find.
      'components the base covers render from their main modules, as they would' => function (): void {
        $this->startOver();
        SpyRunner::$output = '{"html":"<p>x</p>"}';
        $client = '"use client"; import { h } from "preact"; export default function C(){ return h("p", null, "c"); }';
        $server = 'import { h } from "preact"; export default function S(){ return h("p", null, "s"); }';
        $client_component = $this->createComponent('Client', $client, machine_name: 'client');
        $server_component = $this->createComponent('Server', $server, machine_name: 'server');
        $renderer = $this->ssrRenderer();

        $client_result = $renderer->render($client_component, [], [], 'preact');
        $server_result = $renderer->render($server_component, [], [], 'preact');

        self::assertSame([], SpyRunner::$modules);
        self::assertCount(2, SpyRunner::$invoked);
        foreach (SpyRunner::$invoked as $main_module) {
          self::assertContains($main_module, SpyRunner::$base['compile'] ?? []);
        }
        self::assertTrue($client_result->hydrates);
        self::assertFalse($server_result->hydrates);
      },

      // A component's change doesn't forget the base until the next request.
      'a component whose graph changed since the base was defined is registered' => function (): void {
        $this->startOver();
        $dependency_source = 'import { h } from "preact"; export default function D(){ return h("span", null, "d"); }';
        $dependency = $this->createComponent('Dependency', $dependency_source, machine_name: 'changed_dependency');
        $source = 'import { h } from "preact"; import D from "@/components/changed_dependency"; export default function R(){ return h("div", null, h(D)); }';
        $root = $this->createComponent('Root', $source, imports: [$dependency->id()], machine_name: 'changed_root');
        $this->renderComponent($root);
        self::assertSame([], SpyRunner::$modules);

        $changed = \str_replace('"d"', '"e"', $dependency_source);
        $dependency->set('js', ['original' => $changed, 'compiled' => $changed])->save();
        $this->renderComponent($root);

        self::assertContains($changed, SpyRunner::$modules);
        self::assertCount(2, SpyRunner::$invoked);
        self::assertNotContains(SpyRunner::$invoked[1], SpyRunner::$base['compile'] ?? []);
      },

      // A failed invoke can cost a runner what it built, and the main module
      // would fail again until the base changes.
      'an unloadable component falls back to CSR without an invoke' => function (): void {
        $this->startOver();
        $source = 'import { h } from "preact"; export default function U(){ return h("p", null, "u"); }';
        $component = $this->createComponent('Unloadable', $source, machine_name: 'unloadable');
        $renderer = $this->ssrRenderer();
        $renderer->render($component, [], [], 'preact');
        self::assertCount(1, SpyRunner::$invoked);
        SpyRunner::$unloadable = SpyRunner::$invoked;
        SpyRunner::$invoked = [];
        // The guard keeps the runner's answer for the base in use until
        // reset.
        $runner = $this->container->get(RunnerInterface::class);
        \assert($runner instanceof RunnerInterface);
        $runner->reset();

        $result = $renderer->render($component, [], [], 'preact');

        self::assertNull($result->html);
        self::assertSame([], SpyRunner::$invoked);
        self::assertSame([], SpyRunner::$modules);
        self::assertSame(Cache::PERMANENT, $result->getCacheMaxAge());
      },

      // Module sources are files. Building the base reads them, so a cold
      // request does, and the base's components need none of them.
      'a warm request renders from the base in use without touching a file' => function (): void {
        $this->startOver();
        $source = 'import { h } from "preact"; import { cn } from "@/lib/utils"; export default function C(){ return h("p", { class: cn("px-2", "px-3") }); }';
        $component = $this->createComponent('Warm', $source, machine_name: 'warm');
        $renderer = $this->ssrRenderer();

        FileAccessRecorder::start();
        try {
          $renderer->render($component, [], [], 'preact');
        }
        finally {
          FileAccessRecorder::stop();
        }
        $dist = \array_filter(
          FileAccessRecorder::$accessed,
          static fn(string $access): bool => \str_contains($access, '/astro-hydration/dist/'),
        );
        self::assertNotSame([], $dist, 'The cold request reads module sources.');

        $container = $this->container->get('kernel')->rebuildContainer();
        // A real warm request has entity discovery cached, and Canvas has
        // built the island's import map before asking for its markup. The
        // rebuilt container has neither, so do what that request already did.
        $container->get(EntityTypeManagerInterface::class)->getDefinitions();
        $container->get(GlobalImports::class)->getImportMap();
        $warm = $this->ssrRenderer($container);
        SpyRunner::$invoked = [];
        FileAccessRecorder::start();
        try {
          $warm->render($component, [], [], 'preact');
        }
        finally {
          FileAccessRecorder::stop();
        }

        self::assertSame([], FileAccessRecorder::$accessed);
        self::assertCount(1, SpyRunner::$invoked);
        self::assertContains(SpyRunner::$invoked[0], SpyRunner::$base['compile'] ?? []);
        self::assertSame([], SpyRunner::$modules);
      },

      // The draft is the leaf of a chain, so the middle's source is the one
      // the base has, but its import of the draft gives it a module URL the
      // base lacks. The modules the draft imports are all in the base.
      'a preview with a draft renders without touching a file either' => function (): void {
        $this->startOver();
        $leaf_source = 'import { h } from "preact"; import { cn } from "@/lib/utils"; export default function L(){ return h("p", { class: cn("px-2", "px-3") }, "l"); }';
        $leaf = $this->createComponent('Leaf', $leaf_source, machine_name: 'drafted_leaf');
        $middle_source = 'import { h } from "preact"; import L from "@/components/drafted_leaf"; export default function M(){ return h("div", null, h(L)); }';
        $middle = $this->createComponent('Middle', $middle_source, imports: [$leaf->id()], machine_name: 'drafted_middle');
        $root_source = 'import { h } from "preact"; import M from "@/components/drafted_middle"; export default function R(){ return h("section", null, h(M)); }';
        $root = $this->createComponent('Root', $root_source, imports: [$middle->id()], machine_name: 'drafted_root');
        $renderer = $this->ssrRenderer();
        $renderer->render($root, [], [], 'preact', TRUE);

        // An editor's change to the leaf, in an earlier request.
        $draft_source = \str_replace('"l"', '"draft"', $leaf_source);
        $draft = JavaScriptComponent::load((string) $leaf->id());
        \assert($draft instanceof JavaScriptComponent);
        $draft->set('js', ['original' => $draft_source, 'compiled' => $draft_source]);
        $auto_save = $this->container->get(AutoSaveManager::class);
        \assert($auto_save instanceof AutoSaveManager);
        $auto_save->saveEntity($draft);

        $container = $this->container->get('kernel')->rebuildContainer();
        // As for the warm request without file access.
        $container->get(EntityTypeManagerInterface::class)->getDefinitions();
        $container->get(GlobalImports::class)->getImportMap();
        $preview = $this->ssrRenderer($container);
        SpyRunner::$invoked = [];
        SpyRunner::$modules = [];
        FileAccessRecorder::start();
        try {
          $preview->render($root, [], [], 'preact', TRUE);
        }
        finally {
          FileAccessRecorder::stop();
        }

        self::assertSame([], FileAccessRecorder::$accessed);
        self::assertContains($draft_source, SpyRunner::$modules);
        // The draft, the two components that import it, the main module,
        // the libraries module, and the drupalSettings module, whose URL
        // covers the root. Not the modules the base has.
        $base_modules = SpyRunner::$base['modules'] ?? [];
        self::assertCount(6, SpyRunner::$modules);
        self::assertSame([], \array_intersect_key(SpyRunner::$modules, $base_modules));
        self::assertCount(1, SpyRunner::$invoked);
        self::assertNotContains(SpyRunner::$invoked[0], SpyRunner::$base['compile'] ?? []);
      },
    ];
  }

  /**
   * Scenarios for a request that gets no base, and falls back to CSR.
   *
   * @return array<string, callable():void>
   *   Scenarios keyed by the claim each makes.
   */
  private function noBaseScenarios(): array {
    return [
      // Rendering without one would register every provided module. The
      // runner says when it can use the base, or a new base ends it.
      'a base the runner can\'t use yet falls back to CSR until it can' => function (): void {
        $this->startOver();
        SpyRunner::$takesBase = FALSE;
        $source = 'import { h } from "preact"; export default function A(){ return h("p", null, "a"); }';
        $first = $this->createComponent('A', $source, machine_name: 'no_base_a');
        $second = $this->createComponent('B', $source, machine_name: 'no_base_b');
        $renderer = $this->ssrRenderer();

        foreach ([$first, $second] as $component) {
          $result = $renderer->render($component, [], [], 'preact');
          self::assertNull($result->html);
          self::assertSame(Cache::PERMANENT, $result->getCacheMaxAge());
          self::assertContains(Renderer::BASE_PENDING_TAG, $result->getCacheTags());
          self::assertContains('config:js_component_list', $result->getCacheTags());
        }
        self::assertSame([], SpyRunner::$invoked);
        self::assertSame([], SpyRunner::$modules);
        // Not again for each island.
        self::assertSame(1, SpyRunner::$getDefinitionCalls);
      },

      // Nothing would end a fallback cached until it's built.
      'a base the runner can\'t use, and isn\'t building, falls back briefly' => function (): void {
        $this->startOver();
        SpyRunner::$takesBase = FALSE;
        SpyRunner::$buildsBase = FALSE;
        $source = 'import { h } from "preact"; export default function A(){ return h("p", null, "a"); }';
        $first = $this->createComponent('A', $source, machine_name: 'unbuilding_a');
        $second = $this->createComponent('B', $source, machine_name: 'unbuilding_b');
        $renderer = $this->ssrRenderer();

        foreach ([$first, $second] as $component) {
          $result = $renderer->render($component, [], [], 'preact');
          self::assertNull($result->html);
          self::assertSame(60, $result->getCacheMaxAge());
          self::assertNotContains(Renderer::BASE_PENDING_TAG, $result->getCacheTags());
        }
        // Not again for each island.
        self::assertSame(1, SpyRunner::$getDefinitionCalls);
      },

      // Every render relies on what providers give. That fails the same way
      // until what the base depends on changes, so it's no transient error.
      'a provided module that isn\'t UTF-8 leaves no base, and CSR' => function (): void {
        $this->startOver();
        TaggedModuleProvider::$source = "export const tagged = \"\xE9\";";
        $source = 'import { h } from "preact"; export default function A(){ return h("p", null, "a"); }';
        $component = $this->createComponent('A', $source, machine_name: 'not_utf8');
        $renderer = $this->ssrRenderer();

        $result = $renderer->render($component, [], [], 'preact');

        self::assertNull($result->html);
        self::assertSame(Cache::PERMANENT, $result->getCacheMaxAge());
        self::assertContains('config:js_component_list', $result->getCacheTags());
        self::assertNotContains(Renderer::TRANSIENT_ERROR_TAG, $result->getCacheTags());
        self::assertSame(0, SpyRunner::$getDefinitionCalls);
        self::assertSame([], SpyRunner::$invoked);
      },

      // Building the base is the slowest step of a cold render, so a request
      // pays for a failed one once, not once per island.
      'a base whose build throws is tried once a request' => function (): void {
        $this->startOver();
        TaggedModuleProvider::$failure = new \RuntimeException('The source is gone.');
        $source = 'import { h } from "preact"; export default function A(){ return h("p", null, "a"); }';
        $first = $this->createComponent('A', $source, machine_name: 'unbuilt_a');
        $second = $this->createComponent('B', $source, machine_name: 'unbuilt_b');
        $renderer = $this->ssrRenderer();

        foreach ([$first, $second] as $component) {
          $result = $renderer->render($component, [], [], 'preact');
          self::assertNull($result->html);
          self::assertSame(60, $result->getCacheMaxAge());
          self::assertContains(Renderer::TRANSIENT_ERROR_TAG, $result->getCacheTags());
        }
        self::assertSame(1, TaggedModuleProvider::$loads);
        self::assertSame([], SpyRunner::$invoked);

        // A later request tries again.
        TaggedModuleProvider::$failure = NULL;
        $later = $this->ssrRenderer($this->container->get('kernel')->rebuildContainer());
        $later->render($first, [], [], 'preact');
        self::assertSame(2, TaggedModuleProvider::$loads);
        self::assertCount(1, SpyRunner::$invoked);
      },
    ];
  }

  /**
   * Scenarios for the base changing under a request that has rendered.
   *
   * @return array<string, callable():void>
   *   Scenarios keyed by the claim each makes.
   */
  private function midRequestChangeScenarios(): array {
    return [
      // Registrations made with the old import map would otherwise sit on
      // top of the new base for the rest of the request.
      'a request that pushes and then renders starts over on the new map' => function (): void {
        $this->startOver();
        $source = 'import { h } from "preact"; export default function A(){ return h("p", null, "a"); }';
        $component = $this->createComponent('Mapped', $source, machine_name: 'mapped');
        $this->renderComponent($component);
        $old_id = SpyRunner::$baseId;
        self::assertNotNull($old_id);
        self::assertSame(0, SpyRunner::$resets);

        $asset_library = AssetLibrary::load(AssetLibrary::GLOBAL_ID);
        \assert($asset_library instanceof AssetLibrary);
        $asset_library->setImports([['name' => 'pushed', 'uri' => 'public://canvas/assets/pushed.js']]);
        $asset_library->save();
        SpyRunner::$modules = [];
        $this->renderComponent($component);

        self::assertNotSame($old_id, SpyRunner::$baseId);
        self::assertArrayHasKey('pushed', SpyRunner::$importMap['imports'] ?? []);
        self::assertSame(1, SpyRunner::$resets);
        self::assertContains(\end(SpyRunner::$invoked), SpyRunner::$base['compile'] ?? []);

        // Unchanged, it carries on.
        $this->renderComponent($component);
        self::assertSame(1, SpyRunner::$resets);
      },

      // A provided module's URL doesn't change with its source, so its old
      // source would otherwise stay registered. A component's module URL
      // covers its graph.
      'a provider\'s change mid-request starts over, a component\'s doesn\'t' => function (): void {
        $this->startOver();
        $source = 'import { h } from "preact"; import { tagged } from "' . TaggedModuleProvider::SPECIFIER . '"; export default function A(){ return h("p", null, tagged); }';
        $component = $this->createComponent('Tagged', $source, machine_name: 'tagged');
        $this->renderComponent($component);
        self::assertContains(TaggedModuleProvider::$source, SpyRunner::$base['modules'] ?? []);

        $other = 'import { h } from "preact"; export default function B(){ return h("p", null, "b"); }';
        $this->createComponent('Untagged', $other, machine_name: 'untagged');
        $this->renderComponent($component);
        self::assertSame(0, SpyRunner::$resets);

        TaggedModuleProvider::$source = 'export const tagged = "b";';
        Cache::invalidateTags([TaggedModuleProvider::TAG]);
        SpyRunner::$modules = [];
        $this->renderComponent($component);

        self::assertSame(1, SpyRunner::$resets);
        self::assertContains(TaggedModuleProvider::$source, SpyRunner::$base['modules'] ?? []);
      },
    ];
  }

  /**
   * Scenarios for the id that names a base.
   *
   * The last two leave the site under a base path, so they run last.
   *
   * @return array<string, callable():void>
   *   Scenarios keyed by the claim each makes.
   */
  private function idScenarios(): array {
    return [
      'the id holds until a component changes, and changes then' => function (): void {
        $this->startOver();
        $base_modules = $this->container->get(BaseModules::class);
        \assert($base_modules instanceof BaseModules);
        $source = 'import { h } from "preact"; export default function A(){ return h("p", null, "a"); }';
        $component = $this->createComponent('Followed', $source, machine_name: 'followed');

        $id = $base_modules->id();
        self::assertSame($id, $base_modules->id());
        // A fresh instance reads it from the cache.
        $fresh = $this->container->get('kernel')->rebuildContainer()->get(BaseModules::class);
        \assert($fresh instanceof BaseModules);
        self::assertSame($id, $fresh->id());

        $changed = \str_replace('"a"', '"b"', $source);
        $component->set('js', ['original' => $changed, 'compiled' => $changed])->save();
        $next = $this->container->get('kernel')->rebuildContainer()->get(BaseModules::class);
        \assert($next instanceof BaseModules);
        self::assertNotSame($id, $next->id());
      },

      // A runner keeps what it builds under the id it's handed, so a base
      // that changed since its id was read must come with its new id.
      'a base is only ever handed over with its own id' => function (): void {
        $this->startOver();
        $source = 'import { h } from "preact"; export default function A(){ return h("p", null, "a"); }';
        $component = $this->createComponent('Paired', $source, machine_name: 'paired');
        $this->container->get(BaseModules::class)->id();
        // A later request reads the id, then the component changes.
        $stale = $this->container->get('kernel')->rebuildContainer()->get(BaseModules::class);
        \assert($stale instanceof BaseModules);
        $read = $stale->id();
        $changed = \str_replace('"a"', '"b"', $source);
        $component->set('js', ['original' => $changed, 'compiled' => $changed])->save();

        [$id, $base] = $stale->getDefinition();
        self::assertNotSame($read, $id);
        self::assertContains($changed, \array_map(SpyRunner::withoutHooks(...), $base['modules']));
        self::assertSame($id, $stale->id());
      },

      // The id is read at the cache contexts of the import map and the site
      // data, so it must be cached there. The site URL
      // covers the base path.
      'a warm read finds the id in one lookup, with no redirect' => function (): void {
        $this->startOver();
        $source = 'import { h } from "preact"; export default function A(){ return h("p", null, "a"); }';
        $this->createComponent('Cached', $source, machine_name: 'cached');

        $id = $this->baseModulesAt('/sub/')->id();

        $backend = $this->container->get('cache.default');
        \assert($backend instanceof CacheBackendInterface);
        $keys = 'canvas_ssr:base_modules:id:components';
        self::assertFalse($backend->get($keys));
        // Canvas after 1.12 gives the interface language as site data.
        $language = \in_array('getCanvasDataLangcodeV0', \get_class_methods(CodeComponentDataProvider::class), TRUE)
          ? ':[languages:language_interface]=en'
          : '';
        $cached = $backend->get($keys . $language . ':[theme]=stark:[url.site]=http://localhost/sub');
        self::assertNotFalse($cached);
        self::assertSame($id, $cached->data['id'] ?? NULL);
      },

      // Requests under different paths can share a database, and interleave.
      'each base path the site is served under keeps its own base' => function (): void {
        $this->startOver();
        $source = 'import { h } from "preact"; export default function A(){ return h("p", null, "a"); }';
        $this->createComponent('A', $source, machine_name: 'a');

        [$root_id, $root_base] = $this->baseModulesAt('/')->getDefinition();
        [$sub_id, $sub_base] = $this->baseModulesAt('/sub/')->getDefinition();
        self::assertNotSame($root_id, $sub_id);
        $under_sub = static fn(array $base): array => \array_filter(
          \array_keys($base['modules']),
          static fn(int|string $path): bool => \str_starts_with((string) $path, '/sub/'),
        );
        self::assertSame([], $under_sub($root_base));
        self::assertNotSame([], $under_sub($sub_base));

        // Neither replaced the other.
        self::assertSame($root_id, $this->baseModulesAt('/')->id());
        self::assertSame($sub_id, $this->baseModulesAt('/sub/')->id());
      },
    ];
  }

  /**
   * Returns the specifiers the base's library modules import.
   *
   * @param array<string, mixed> $base
   *   The base.
   *
   * @return list<string>
   *   The specifiers, in preload order.
   */
  private static function libraryImports(array $base): array {
    $specifiers = [];
    foreach ($base['preload'] as $url) {
      if (\str_starts_with($url, '/_canvas_ssr/base/library/') && \preg_match('/import ("[^"]+");\n/', $base['modules'][$url] ?? '', $matches) === 1) {
        $specifiers[] = \json_decode($matches[1]);
      }
    }

    return $specifiers;
  }

  /**
   * Starts a later request on a site with no exposed components.
   *
   * The base covers every exposed component and what was pushed, and a
   * request keeps using the base it started with. Machine names stay taken,
   * so each scenario uses its own.
   */
  private function startOver(): void {
    foreach (JavaScriptComponent::loadMultiple() as $component) {
      if ($component->status()) {
        $component->disable()->save();
      }
    }
    $asset_library = AssetLibrary::load(AssetLibrary::GLOBAL_ID);
    \assert($asset_library instanceof AssetLibrary);
    if ($asset_library->getImports() !== []) {
      $asset_library->setImports(NULL);
      $asset_library->save();
    }
    $this->container->get('kernel')->rebuildContainer();
    self::resetSpies();
  }

  /**
   * Forgets what the spies saw, and sets them back to their defaults.
   */
  private static function resetSpies(): void {
    SpyRunner::clear();
    TaggedModuleProvider::$source = 'export const tagged = "a";';
    TaggedModuleProvider::$failure = NULL;
    TaggedModuleProvider::$loads = 0;
  }

  /**
   * Returns the module URL an import map entry's URL loads.
   */
  private static function moduleUrlOf(string $url): string {
    return \preg_replace('/[?#].*$/s', '', $url) ?? $url;
  }

  /**
   * Returns the base modules of a later request under a base path.
   */
  private function baseModulesAt(string $base_path): BaseModules {
    $GLOBALS['base_path'] = $base_path;
    $GLOBALS['base_url'] = 'http://localhost' . \rtrim($base_path, '/');
    $container = $this->container->get('kernel')->rebuildContainer();
    // A real request under the base path has a matching site URL.
    $request = Request::create(
      'http://localhost' . $base_path,
      server: ['SCRIPT_NAME' => $base_path . 'index.php', 'SCRIPT_FILENAME' => 'index.php'],
    );
    $request->setSession(new Session(new MockArraySessionStorage()));
    $request_stack = $container->get(RequestStack::class);
    \assert($request_stack instanceof RequestStack);
    $request_stack->push($request);
    $base_modules = $container->get(BaseModules::class);
    \assert($base_modules instanceof BaseModules);

    return $base_modules;
  }

}
