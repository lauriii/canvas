<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_ssr\Kernel;

use Drupal\canvas\Entity\JavaScriptComponent;
use Drupal\canvas_ssr\JsRunner\Deno\DenoRunner;
use Drupal\canvas_ssr\Timeouts;
use Drupal\Core\Cache\Cache;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\RfcLogLevel;
use Drupal\Tests\canvas_ssr\CollectingLogger;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the realm is locked before any component runs.
 *
 * Islands share a realm, so a component that patched a built-in, replaced
 * a global or mutated a library's exports would change every island
 * rendered after it. The realm setup module freezes the built-ins and the
 * existing globals, every module's exports are frozen once it evaluates,
 * and both first tame the properties an object commonly sets on itself
 * though its prototype has them.
 *
 * @see js/realm-setup.js
 * @see \Drupal\canvas_ssr\JsModules\TamedProperties
 * @see \Drupal\canvas_ssr\JsModules\ModuleEvaluationHooks
 */
#[RunTestsInSeparateProcesses]
#[Group('canvas_ssr')]
#[Group('canvas_ssr_requires_runner')]
final class RealmSetupTest extends CanvasSsrKernelTestBase {

  /**
   * Collects what canvas_ssr logs.
   */
  private CollectingLogger $logger;

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container): void {
    parent::register($container);
    $container
      ->register(ChunkedLibraryProvider::class, ChunkedLibraryProvider::class)
      ->addTag('canvas_ssr.js_module_provider', ['priority' => 200]);
    // The prototype search calls every method of every built-in in one
    // island. That uses much of the default short limit even on a fast
    // host, so on a busy one the island can run out of time and fall back
    // to the client.
    $container->setParameter('canvas_ssr.timeouts', ['short' => 1000] + Timeouts::DEFAULTS);
    // A site's addition, merged with the defaults.
    $container->setParameter('canvas_ssr.additional_tamed_properties', [
      'globals' => ['Map.prototype' => ['get']],
      'modules' => ['drupal-jsonapi-params' => ['DrupalJsonApiParams.prototype' => ['addSort']]],
    ]);
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installRunner();
    $this->logger = new CollectingLogger();
    $factory = $this->container->get(LoggerChannelFactoryInterface::class);
    \assert($factory instanceof LoggerChannelFactoryInterface);
    $factory->addLogger($this->logger);
  }

  /**
   * Tests what a component may and may not change.
   */
  public function testRealmSetup(): void {
    $this->assertScenarios([
      // First, so no island has segmented anything yet, and at module scope,
      // where a component's module may change nothing islands share. Deno
      // has Intl.Segmenter, and other runners may lack it.
      "segment() hands out nothing whose prototype isn't frozen, from its first call" => function (): void {
        $segmenter = $this->component('segmenter', <<<'JS'
          function first() {
            if (typeof Intl.Segmenter !== 'function') return 'none';
            const segments = new Intl.Segmenter('en', { granularity: 'word' }).segment('a b');
            const frozen = Object.isFrozen(Object.getPrototypeOf(segments))
              && Object.isFrozen(Object.getPrototypeOf(segments[Symbol.iterator]()));
            return `${frozen} ${[...segments].map((s) => s.segment).join('|')}`;
          }
          const result = first();
          JS, 'result');
        $expected = self::runnerClassUnderTest() === DenoRunner::class ? ['<p>true a| |b</p>'] : ['<p>true a| |b</p>', '<p>none</p>'];
        self::assertContains($this->ssrMarkup($segmenter), $expected);
      },

      'patching a built-in falls back to CSR, and later islands are unchanged' => function (): void {
        $patcher = $this->component('patcher', 'Array.prototype.join = () => "patched";', '"never"');
        self::assertStringContainsString('client="only"', $this->renderComponent($patcher));

        $joiner = $this->component('joiner', '', '["a", "b"].join("+")');
        self::assertSame('<p>a+b</p>', $this->ssrMarkup($joiner));
        // Its author is told why, since it works in the browser.
        $this->assertWarnedOfFrozenWrite('patcher');
      },

      // Freezing a constructor doesn't stop an accessor on it that sets or
      // reads what the engine keeps for the whole realm.
      "a built-in that isn't a prototype has no accessor" => function (): void {
        $setter = $this->component('static_setter', <<<'JS'
          function set() {
            try { Error.prepareStackTrace = () => 'hooked'; } catch {}
            try { Error.stackTraceLimit = 0; } catch {}
            return /(secret)/.test('a secret') ? 'matched' : 'missed';
          }
          JS, 'set()');
        self::assertSame('<p>matched</p>', $this->ssrMarkup($setter));

        $reader = $this->component('static_reader', '', '`${String(new Error("x").stack).includes("hooked")} ${RegExp.$1 ?? ""} ${RegExp.lastMatch ?? ""}`.trim()');
        self::assertSame('<p>false</p>', $this->ssrMarkup($reader));

        $lister = $this->component('static_lister', <<<'JS'
          function list() {
            const found = [];
            const seen = new Set([globalThis]);
            const visit = (holder, path, isPrototype) => {
              if ((typeof holder !== 'object' && typeof holder !== 'function') || holder === null || seen.has(holder)) return;
              seen.add(holder);
              for (const key of Reflect.ownKeys(holder)) {
                const descriptor = Object.getOwnPropertyDescriptor(holder, key);
                if ('value' in descriptor) visit(descriptor.value, `${path}.${String(key)}`, key === 'prototype');
                else if (!isPrototype && typeof key !== 'symbol') found.push(`${path}.${key}`);
              }
            };
            for (const name of Object.getOwnPropertyNames(globalThis)) {
              visit(Object.getOwnPropertyDescriptor(globalThis, name).value, name, false);
            }
            return JSON.stringify(found);
          }
          JS, 'list()');
        self::assertSame('<p>[]</p>', $this->ssrMarkup($lister));
      },

      // Some built-ins are reached only by calling something, like the
      // prototype of what entries() returns, so no walk from the globals
      // finds them. This constructs what the realm has, calls its methods,
      // and looks at what comes back. That takes far longer than a real
      // island, hence the longer short limit in register().
      "nothing a call hands out has a prototype that isn't frozen" => function (): void {
        $searcher = $this->component('prototype_searcher', <<<'JS'
          function search() {
            const unfrozen = new Map();
            const seen = new WeakSet();
            const called = new WeakSet();
            const isObject = (value) => (typeof value === 'object' && value !== null) || typeof value === 'function';
            const check = (value, path, depth) => {
              if (!isObject(value) || seen.has(value)) return;
              seen.add(value);
              for (let prototype = Object.getPrototypeOf(value); prototype; prototype = Object.getPrototypeOf(prototype)) {
                if (!Object.isFrozen(prototype) && !unfrozen.has(prototype)) unfrozen.set(prototype, path);
              }
              // Objects of one prototype have the same methods.
              const prototype = Object.getPrototypeOf(value);
              if (depth > 0 && isObject(prototype) && !called.has(prototype)) {
                called.add(prototype);
                call(value, path, depth - 1);
              }
            };
            const call = (object, path, depth) => {
              const keys = new Set();
              for (let holder = Object(object); holder && holder !== Object.prototype && holder !== Function.prototype; holder = Object.getPrototypeOf(holder)) {
                for (const key of Reflect.ownKeys(holder)) keys.add(key);
              }
              for (const key of keys) {
                if (key === 'constructor' || key === 'prototype') continue;
                let member;
                try { member = object[key]; } catch { continue; }
                if (typeof member !== 'function') {
                  check(member, `${path}.${String(key)}`, depth);
                  continue;
                }
                for (const args of [[], ['a']]) {
                  try { check(member.apply(object, args), `${path}.${String(key)}(${args})`, depth); } catch {}
                }
              }
            };
            const visit = (name, value) => {
              if (typeof value === 'function') {
                for (const args of [[], ['a'], ['a=b'], [[1, 2]], [1], ['en']]) {
                  try { check(new value(...args), `new ${name}(${args})`, 3); } catch {}
                }
              }
              if (isObject(value)) call(value, name, 2);
            };
            // A fetch would count against the island, and the rest say
            // nothing or can't be called safely.
            const skipped = ['fetch', 'drupalSettings', 'console', 'globalThis', 'eval', 'Function'];
            for (const name of Object.getOwnPropertyNames(globalThis)) {
              const value = Object.getOwnPropertyDescriptor(globalThis, name).value;
              if (skipped.includes(name)) continue;
              visit(name, value);
              if (name === 'Intl') {
                for (const key of Object.getOwnPropertyNames(value)) visit(`Intl.${key}`, value[key]);
              }
            }
            // A generator's own prototype is its function's, so start above it.
            check(Object.getPrototypeOf((function* () {})()), 'generator', 2);
            check(Object.getPrototypeOf((async function* () {})()), 'async generator', 2);
            check((async () => {})(), 'async function', 2);
            check(/a/g, 'regular expression', 3);
            call('abc', 'string', 3);
            call([1], 'array', 3);
            call(new Map([[1, 2]]), 'map', 3);
            return JSON.stringify([...unfrozen.values()]);
          }
          JS, 'search()');
        self::assertSame('<p>[]</p>', $this->ssrMarkup($searcher));

        // What was frozen for it still works.
        $iterator = $this->component('params_iterator', '', 'new URLSearchParams("a=1&b=2").get("b")');
        self::assertSame('<p>2</p>', $this->ssrMarkup($iterator));
      },

      'writing to a built-in while rendering falls back to CSR too' => function (): void {
        $writer = $this->component('writer', 'function write() { Object.prototype.extra = 1; return "never"; }', 'write()');
        self::assertStringContainsString('client="only"', $this->renderComponent($writer));
        $this->assertWarnedOfFrozenWrite('writer');
      },

      'replacing a global falls back to CSR' => function (): void {
        $replacer = $this->component('replacer', 'globalThis.parseInt = () => 7;', '"never"');
        self::assertStringContainsString('client="only"', $this->renderComponent($replacer));

        $parser = $this->component('parser', '', 'parseInt("3")');
        self::assertSame('<p>3</p>', $this->ssrMarkup($parser));
      },

      "the global object's prototype is frozen, and stays the global object's" => function (): void {
        $patcher = $this->component('prototype_patcher', 'Object.getPrototypeOf(globalThis).extra = 1;', '"never"');
        self::assertStringContainsString('client="only"', $this->renderComponent($patcher));

        // An inherited global would outlive this island.
        $replacer = $this->component('prototype_replacer', <<<'JS'
          function replace() {
            const inherited = { __ssrTestInherited: { get: () => "forged" } };
            Object.setPrototypeOf(globalThis, Object.create(Object.getPrototypeOf(globalThis), inherited));
            return globalThis.__ssrTestInherited;
          }
          JS, 'replace()');
        self::assertSame('<p>forged</p>', $this->ssrMarkup($replacer));

        // Nor while a module evaluates for a later island.
        $reader = $this->component('inherited_reader', 'const atModuleScope = String(globalThis.__ssrTestInherited);', 'atModuleScope + ":" + String(globalThis.__ssrTestInherited)');
        self::assertSame('<p>undefined:undefined</p>', $this->ssrMarkup($reader));
      },

      // As in the browser. Otherwise an island could keep the prototype it
      // gave the global object.
      'the global object stays extensible' => function (): void {
        foreach (['preventExtensions', 'seal', 'freeze'] as $method) {
          $preventer = $this->component("prevents_$method", <<<JS
            function prevent() {
              const inherited = { __ssrTestInherited: { get: () => "forged" } };
              Object.setPrototypeOf(globalThis, Object.create(Object.getPrototypeOf(globalThis), inherited));
              Object.$method(globalThis);
            }
            JS, 'prevent()');
          self::assertStringContainsString('client="only"', $this->renderComponent($preventer));
        }

        $reader = $this->component('extensible_reader', '', '`${Reflect.preventExtensions(globalThis)}:${Object.isExtensible(globalThis)}:${Object.isFrozen(Object.freeze({}))}:${globalThis.__ssrTestInherited}`');
        self::assertSame('<p>false:true:true:undefined</p>', $this->ssrMarkup($reader));
      },

      // Otherwise no later island could be given its own.
      "drupalSettings can't be redefined or deleted, and an assigned one doesn't outlive the island" => function (): void {
        $changes = [
          'redefines' => 'Object.defineProperty(globalThis, "drupalSettings", { value: 1, configurable: false })',
          'deletes' => 'delete globalThis.drupalSettings',
        ];
        foreach ($changes as $name => $change) {
          $changer = $this->component("{$name}_settings", "function change() { $change; }", 'change()');
          self::assertStringContainsString('client="only"', $this->renderComponent($changer));
        }

        $assigner = $this->component('assigns_settings', 'function assign() { globalThis.drupalSettings = { forged: 1 }; return drupalSettings.forged; }', 'assign()');
        self::assertSame('<p>1</p>', $this->ssrMarkup($assigner));

        // It declares no data, so it gets none.
        $reader = $this->component('settings_reader', '', 'typeof globalThis.drupalSettings');
        self::assertSame('<p>undefined</p>', $this->ssrMarkup($reader));
      },

      'deeply nested built-ins are frozen too' => function (): void {
        $deep = $this->component('deep', 'Object.getPrototypeOf(function* () {}).prototype.extra = 1;', '"never"');
        self::assertStringContainsString('client="only"', $this->renderComponent($deep));
      },

      'an object may set a tamed property its prototype has' => function (): void {
        $tamed = $this->component('tamed', <<<'JS'
          class NotFound extends Error {}
          const error = new NotFound("gone");
          error.name = "NotFound";
          const point = { x: 1 };
          point.toString = () => "point";
          JS, '`${error.name}:${error.message}:${point}`');
        self::assertSame('<p>NotFound:gone:point</p>', $this->ssrMarkup($tamed));
      },

      'a site adds properties to tame' => function (): void {
        $added = $this->component('added', 'const m = new Map(); m.get = () => "own";', 'm.get()');
        self::assertSame('<p>own</p>', $this->ssrMarkup($added));

        $untamed = $this->component('untamed', 'const m = new Map(); m.set = () => m;', '"never"');
        self::assertStringContainsString('client="only"', $this->renderComponent($untamed));
      },

      "mutating a library's export falls back to CSR" => function (): void {
        $changer = $this->component('changer', 'import { JsonApiClient } from "drupal-canvas"; JsonApiClient.prototype.extra = 1;', '"never"');
        self::assertStringContainsString('client="only"', $this->renderComponent($changer));

        $untamed = $this->component('untamed_export', 'import { DrupalJsonApiParams } from "drupal-jsonapi-params"; Object.create(DrupalJsonApiParams.prototype).addFields = () => "own";', '"never"');
        self::assertStringContainsString('client="only"', $this->renderComponent($untamed));
      },

      // As preact/compat adds to what preact core exports.
      "a library may add to what its chunk exports" => function (): void {
        $user = $this->component('chunk_user', 'import { Thing } from "@canvas-ssr-test/chunked";', 'new Thing().added');
        self::assertSame('<p>added</p>', $this->ssrMarkup($user));

        // Then it's frozen.
        $changer = $this->component('chunk_changer', 'import { Thing } from "@canvas-ssr-test/chunked"; Thing.prototype.changed = 1;', '"never"');
        self::assertStringContainsString('client="only"', $this->renderComponent($changer));

        // So is what a chunk exports that its library doesn't. The
        // specifier is computed, since a component that imports by URL
        // isn't rendered at all.
        $reacher = $this->component('chunk_reacher', 'import { other } from "@canvas-ssr-test/other"; const { Hidden } = await import(["/_canvas_ssr/test/other", "hidden.js"].join("/")); Hidden.prototype.changed = 1;', 'other');
        self::assertStringContainsString('client="only"', $this->renderComponent($reacher));
        $this->assertWarnedOfFrozenWrite('chunk_reacher');
      },

      // No island has imported the chunk's library yet, so no library has
      // frozen what it exports.
      "a chunk a component reaches first is frozen before the component's code runs" => function (): void {
        $reacher = $this->component('part_reacher', 'const { Part } = await import(["/_canvas_ssr/test/reached", "part.js"].join("/")); Object.defineProperty(Part.prototype, "name", { value: "changed" });', '"never"');
        self::assertStringContainsString('client="only"', $this->renderComponent($reacher));
        $this->assertWarnedOfFrozenWrite('part_reacher');

        $user = $this->component('part_user', 'import { reached } from "@canvas-ssr-test/reached";', 'reached');
        self::assertSame('<p>part</p>', $this->ssrMarkup($user));
      },

      // The library evaluates among the component's modules, where a chunk
      // is frozen without waiting for a library. The library that imports
      // the chunk still evaluates first.
      "a library first imported by a computed specifier may add to what its chunk exports" => function (): void {
        $computed = $this->component('adder_computed', 'const { Base } = await import(["@canvas-ssr-test", "adder"].join("/"));', 'new Base().added');
        self::assertSame('<p>added</p>', $this->ssrMarkup($computed));

        $named = $this->component('adder_named', 'import { Base } from "@canvas-ssr-test/adder";', 'new Base().added');
        self::assertSame('<p>added</p>', $this->ssrMarkup($named));
      },

      // Such a module isn't known as one of the component's libraries or
      // components, so what it fetches isn't recorded against it.
      "a component that imports by URL isn't rendered" => function (): void {
        $chunk = '/_canvas_ssr/test/other/hidden.js';
        $specifiers = [
          'static' => "import '$chunk';",
          'dynamic' => "const load = () => import('$chunk?v=1');",
          'template' => "const load = () => import(`$chunk`);",
          'relative' => "import '" . \str_repeat('../', 12) . \substr($chunk, 1) . "';",
          'sibling' => "import './sibling.js';",
          'reexport' => "export * from '//example.com/module.js';",
          'absolute' => "import 'HTTPS://example.com/module.js';",
          // A module the import map names has a name to import it by.
          'entry' => "import { other } from '/_canvas_ssr/test/other/entry.js';",
        ];
        foreach ($specifiers as $name => $module_scope) {
          $namer = $this->component("url_importer_$name", $module_scope, '"never"');
          self::assertStringContainsString('client="only"', $this->renderComponent($namer), $name);
          $warnings = $this->logger->at((string) RfcLogLevel::WARNING);
          self::assertStringContainsString("the url_importer_$name component imports", (string) \end($warnings), $name);
          self::assertStringContainsString('by its URL', (string) \end($warnings), $name);
        }

        // Nor is a component that imports one that does, and the warning
        // says which has the import.
        $importer = $this->createComponent('url_importer_importer', "import { h } from 'preact'; import C from '@/components/url_importer_static'; export default function I() { return h(C); }", imports: ['url_importer_static'], machine_name: 'url_importer_importer');
        self::assertStringContainsString('client="only"', $this->renderComponent($importer));
        $warnings = $this->logger->at((string) RfcLogLevel::WARNING);
        self::assertStringContainsString("Rendering the url_importer_importer component on the client, since the url_importer_static component imports \"$chunk\"", (string) \end($warnings));
      },

      // A component may silence a method, as in the browser, but only for
      // its own island. What a module does as it evaluates is kept.
      'console stays writable, but each island gets it as modules left it' => function (): void {
        $keeper = $this->component('console_keeper', 'import "@canvas-ssr-test/console";', 'console.__ssrTestKept()');
        self::assertSame('<p>kept</p>', $this->ssrMarkup($keeper));

        $changes = [
          'replaces' => 'console.__ssrTestKept = () => "replaced"',
          'deletes' => 'delete console.__ssrTestKept',
          'redefines' => 'Object.defineProperty(console, "__ssrTestKept", { value: () => "redefined", configurable: false })',
          'makes a method throw' => 'console.log = () => { throw new Error("silenced"); }',
          'replaces the prototype of' => 'Object.setPrototypeOf(console, { __ssrTestInherited: () => "inherited" })',
        ];
        $user = $this->component('console_user', '', '(console.log("logged"), `${console.__ssrTestKept()}:${typeof console.__ssrTestInherited}`)');
        foreach ($changes as $name => $change) {
          $changer = $this->component('console_' . \str_replace(' ', '_', $name), "function change() { $change; return 'changed'; }", 'change()');
          self::assertSame('<p>changed</p>', $this->ssrMarkup($changer));

          self::assertSame('<p>kept:undefined</p>', $this->ssrMarkup($user), "After a component that $name console.");
        }

        $freezer = $this->component('console_freezer', 'function change() { Object.freeze(console); }', 'change()');
        self::assertStringContainsString('client="only"', $this->renderComponent($freezer));
      },

      // The renderer puts options back before each island, so nothing an
      // island does to it may stop that.
      "what a component does to preact's options can't reach a later island" => function (): void {
        $changes = [
          'defines a hook non-configurable on' => 'Object.defineProperty(options, "vnode", { value: mark, writable: false, configurable: false })',
          'defines a getter on' => 'Object.defineProperty(options, "vnode", { get: () => mark, configurable: false })',
          'replaces the prototype of' => 'Object.setPrototypeOf(options, { diffed: mark, set vnode(value) {} })',
        ];
        $user = $this->createComponent('options_plain', 'import { h } from "preact"; export default function C() { return h("p", { "data-ssr-test-mark": "plain" }, "text"); }', machine_name: 'options_plain');
        foreach ($changes as $name => $change) {
          $changer = $this->createComponent('options_' . \str_replace([' ', '-'], '_', $name), <<<JS
            import { h, options } from "preact";
            const mark = (vnode) => {
              if (vnode.props?.["data-ssr-test-mark"] === "plain") {
                vnode.props["data-ssr-test-mark"] = "marked";
              }
            };
            export default function C() { $change; return h("p", null, "changed"); }
            JS);
          self::assertSame('<p>changed</p>', $this->ssrMarkup($changer));

          self::assertSame('<p data-ssr-test-mark="plain">text</p>', $this->ssrMarkup($user), "After a component that $name options.");
        }

        foreach (['Object.freeze', 'Object.seal', 'Object.preventExtensions'] as $method) {
          $freezer = $this->createComponent('options_freezer', "import { h, options } from 'preact'; export default function C() { $method(options); return h('p', null, 'froze'); }");
          self::assertStringContainsString('client="only"', $this->renderComponent($freezer));
          self::assertSame('<p data-ssr-test-mark="plain">text</p>', $this->ssrMarkup($user), "After a component that calls $method() on options.");
        }
      },

      // Deno's replaces itself on its first call, which the lock mustn't
      // break.
      'a component may call queueMicrotask()' => function (): void {
        $component = $this->component('microtask', 'let ran = "not yet"; function queue() { queueMicrotask(() => { ran = "ran"; }); return "queued"; }', 'queue()');
        self::assertSame('<p>queued</p>', $this->ssrMarkup($component));
        self::assertSame('<p>queued</p>', $this->ssrMarkup($component));
      },

      // A library may add hooks to preact's options as it evaluates, as a
      // signals library does. They're kept for every island after.
      "hooks a library adds to preact's options as it evaluates are kept" => function (): void {
        $hooker = $this->component('options_hooker', 'import "@canvas-ssr-test/options-hook";', '"text"');
        $this->renderComponent($hooker);

        $user = $this->createComponent('options_user', 'import { h } from "preact"; export default function C() { return h("p", { "data-ssr-test-hook": "" }, "text"); }', machine_name: 'options_user');
        self::assertSame('<p data-ssr-test-hook="hooked">text</p>', $this->ssrMarkup($user));
      },

      // test() and exec() set lastIndex on a /g or /y one.
      'an exported regular expression can still match' => function (): void {
        $matcher = $this->createComponent('matcher', 'import { h } from "preact"; export const pattern = /a/g; export default function C() { return h("p", null, `${pattern.test("a")}:${Object.isExtensible(pattern)}`); }', machine_name: 'matcher');
        self::assertSame('<p>true:false</p>', $this->ssrMarkup($matcher));
      },

      // Otherwise it could keep page data for islands on other pages.
      'a module imported during a render gets only site data' => function (): void {
        $site_importer = $this->importer('site_importer', '@canvas-ssr-test/site-reader', '`${read}:${typeof drupalSettings.canvasData.v0.pageTitle}`');
        // The render itself still reads the page data.
        self::assertSame('<p>string:true:string</p>', $this->ssrMarkup($site_importer));

        $page_importer = $this->importer('page_importer', '@canvas-ssr-test/page-reader', 'read');
        self::assertStringContainsString('client="only"', $this->renderComponent($page_importer));
        $this->assertWarnedOfPageDataRead('page_importer');
      },

      // It can't be told from the module's own read.
      'reading page data while a module imported during the render awaits falls back to CSR' => function (): void {
        $reader = $this->awaiter('paused_reader', '@canvas-ssr-test/slow-a', 'String(drupalSettings.canvasData.v0.pageTitle)');
        self::assertStringContainsString('client="only"', $this->renderComponent($reader));
        $this->assertWarnedOfPageDataRead('paused_reader');

        // The next island gets its page data.
        $next = $this->createComponent('page_data_user', 'import { h } from "preact"; export default function C() { return h("p", null, typeof drupalSettings.canvasData.v0.pageTitle); }', data_dependencies: ['drupalSettings' => ['v0.pageTitle']], machine_name: 'page_data_user');
        self::assertSame('<p>string</p>', $this->ssrMarkup($next));
      },

      // Otherwise what the island did to console would be kept, as what a
      // module did while it evaluated outside a render.
      'a module that ends after the island it began in keeps nothing of the island' => function (): void {
        $leaker = $this->awaiter('console_leaker', '@canvas-ssr-test/slow-b', '(console.__ssrTestLeaked = () => "leaked", "rendered")');
        self::assertSame('<p>rendered</p>', $this->ssrMarkup($leaker));

        $reader = $this->component('leak_reader', '', 'typeof console.__ssrTestLeaked');
        self::assertSame('<p>undefined</p>', $this->ssrMarkup($reader));
      },

      // Not covered: a module's own variables can't be frozen. ADR 11 lists
      // it.
      "a component's own module state is shared by its islands" => function (): void {
        $counter = $this->component('counter', 'let renders = 0;', '++renders');
        self::assertSame('<p>1</p>', $this->ssrMarkup($counter));
        self::assertSame('<p>2</p>', $this->ssrMarkup($counter));
      },

      // Not covered: freezing reaches only what a module exports. ADR 11
      // lists it.
      "an object a library hands out but doesn't export isn't frozen" => function (): void {
        $patcher = $this->component('made_patcher', 'import { make } from "@canvas-ssr-test/maker"; function patch() { Object.getPrototypeOf(make()).label = () => "patched"; return make().label(); }', 'patch()');
        self::assertSame('<p>patched</p>', $this->ssrMarkup($patcher));

        $user = $this->component('made_user', 'import { make } from "@canvas-ssr-test/maker";', 'make().label()');
        self::assertSame('<p>patched</p>', $this->ssrMarkup($user));
      },

      "a site tames a module's properties" => function (): void {
        $tamed = $this->component('tamed_export', 'import { DrupalJsonApiParams } from "drupal-jsonapi-params"; const params = Object.create(DrupalJsonApiParams.prototype); params.addSort = () => "own";', 'params.addSort()');
        self::assertSame('<p>own</p>', $this->ssrMarkup($tamed));
      },

      'preact options stay writable, but each island starts from its own' => function (): void {
        $hook = $this->component('hook', 'import { options } from "preact"; function set() { options.__ssrTestHook = 1; return "set"; }', 'set()');
        self::assertSame('<p>set</p>', $this->ssrMarkup($hook));

        $reader = $this->component('reader', 'import { options } from "preact";', 'String(options.__ssrTestHook)');
        self::assertSame('<p>undefined</p>', $this->ssrMarkup($reader));
      },

      'a component that wraps the vnode hook wraps it for its own island only' => function (): void {
        $wrapper = $this->component('vnode_wrapper', <<<'JS'
          import { options } from "preact";
          function wrap() {
            const previous = options.vnode;
            options.vnode = (vnode) => {
              vnode.props.title = "wrapped";
              previous?.(vnode);
            };
            return "wrapped";
          }
          JS, 'wrap()');
        self::assertSame('<p title="wrapped">wrapped</p>', $this->ssrMarkup($wrapper));

        $plain = $this->component('vnode_plain', '', '"plain"');
        self::assertSame('<p>plain</p>', $this->ssrMarkup($plain));
      },

      // Object.freeze() throws for one with elements.
      'a module may export a typed array' => function (): void {
        $table = $this->component('typed_array', 'export const TABLE = new Uint32Array([1, 2]);', 'TABLE[0] + TABLE[1]');
        self::assertSame('<p>3</p>', $this->ssrMarkup($table));
      },

      // So its markup doesn't depend on what rendered before it.
      'every island renders with preact/compat' => function (): void {
        $without_compat = $this->component('without_compat', '', "h('svg', { strokeWidth: 2 })");
        self::assertSame('<p><svg stroke-width="2"></svg></p>', $this->ssrMarkup($without_compat));
      },

      'a component may add globals of its own while it renders' => function (): void {
        $adder = $this->component('adder', 'function add() { globalThis.myWidgetConfig = { size: 2 }; return globalThis.myWidgetConfig.size; }', 'add()');
        self::assertSame('<p>2</p>', $this->ssrMarkup($adder));
      },

      // What a module does as it evaluates lasts for every later island in
      // the realm, but in the browser a component's module evaluates only on
      // a page it's on. So it may not, and the change is undone.
      "a component's module that changes what islands share as it evaluates falls back, with a warning" => function (): void {
        $changes = [
          'adds a global' => 'globalThis.__ssrTestAdded = 1;',
          'defines a global' => 'Object.defineProperty(globalThis, "__ssrTestAdded", { value: 1 });',
          'mutates console' => 'console.__ssrTestAdded = 1;',
          'replaces a console method' => 'console.log = () => {};',
          "mutates preact's options" => 'import { options } from "preact"; options.__ssrTestAdded = 1;',
        ];
        $reader = $this->component('shared_reader', 'import { options } from "preact"; const log = console.log;', '`${typeof globalThis.__ssrTestAdded}:${typeof console.__ssrTestAdded}:${typeof options.__ssrTestAdded}:${console.log === log}`');
        foreach ($changes as $name => $change) {
          $machine_name = 'module_scope_' . \preg_replace('/\W+/', '_', $name);
          $changer = $this->component($machine_name, $change, '"changed"');
          $output = $this->renderComponent($changer, cacheability: $cacheability);
          self::assertStringContainsString('client="only"', $output, "A component's module that $name.");
          self::assertSame(Cache::PERMANENT, $cacheability->getCacheMaxAge(), "A component's module that $name.");
          $warnings = $this->logger->at((string) RfcLogLevel::WARNING);
          self::assertStringContainsString($machine_name, (string) \end($warnings));
          self::assertStringContainsString('changes what SSR shares between islands as it evaluates', (string) \end($warnings));

          // Nothing it changed is left for the next island.
          self::assertSame('<p>undefined:undefined:undefined:true</p>', $this->ssrMarkup($reader), "After a component's module that $name.");
        }
      },

      // A library the module imports while it awaits evaluates between the
      // module's first line and its last, but what it changes is its own.
      "what a library changes while a component's module awaits it isn't the component's" => function (): void {
        foreach (['global', 'console', 'options'] as $changed) {
          $importer = $this->component("late_$changed", "const { kept } = await import('@canvas-ssr-test/late-$changed');", 'kept()');
          self::assertSame("<p>$changed</p>", $this->ssrMarkup($importer), "A library that changes $changed.");
          // And it's kept, as for any library.
          $user = $this->component("late_{$changed}_user", "import { kept } from '@canvas-ssr-test/late-$changed';", 'kept()');
          self::assertSame("<p>$changed</p>", $this->ssrMarkup($user), "After a library that changes $changed.");
        }

        // What the component's module changed before the library evaluated
        // is still its own, and the library doesn't get to keep it.
        $early = $this->component('late_early', "console.__ssrTestEarly = 1;\nconst { kept } = await import('@canvas-ssr-test/late-plain');", 'kept()');
        self::assertStringContainsString('client="only"', $this->renderComponent($early));
        $warnings = $this->logger->at((string) RfcLogLevel::WARNING);
        self::assertStringContainsString('changed console as it evaluated', (string) \end($warnings));
        $after = $this->component('late_after', "import { kept } from '@canvas-ssr-test/late-plain';", '`${kept()}:${typeof console.__ssrTestEarly}`');
        self::assertSame('<p>undefined:undefined</p>', $this->ssrMarkup($after));

        // So does one that imports it.
        $importer = $this->createComponent('module_scope_importer', "import { h } from 'preact'; import C from '@/components/module_scope_adds_a_global'; export default function I() { return h(C); }", imports: ['module_scope_adds_a_global'], machine_name: 'module_scope_importer');
        self::assertStringContainsString('client="only"', $this->renderComponent($importer));
      },

      // A library the component names evaluates before the component's
      // modules. One it doesn't evaluates among them, and is still told
      // apart.
      "what a library imported by a computed specifier changes isn't the component's either" => function (): void {
        $importer = $this->component('computed_global', "const { kept } = await import(String('@canvas-ssr-test/computed-global'));", 'kept()');
        self::assertSame('<p>global</p>', $this->ssrMarkup($importer));
        $user = $this->component('computed_global_user', '', 'globalThis.__ssrTestComputed');
        self::assertSame('<p>global</p>', $this->ssrMarkup($user));

        $early = $this->component('computed_early', "console.__ssrTestEarly = 1;\nconst { kept } = await import(String('@canvas-ssr-test/computed-plain'));", 'kept()');
        self::assertStringContainsString('client="only"', $this->renderComponent($early));
        $warnings = $this->logger->at((string) RfcLogLevel::WARNING);
        self::assertStringContainsString('changed console as it evaluated', (string) \end($warnings));
        $after = $this->component('computed_after', '', 'typeof console.__ssrTestEarly');
        self::assertSame('<p>undefined</p>', $this->ssrMarkup($after));
      },

      // Another module evaluating meanwhile mustn't undo what it changed
      // first. A base evaluates each library on its own, so a render with
      // one would differ from a render without.
      'a library that awaits as it evaluates keeps what it changed before' => function (): void {
        $importer = $this->component('awaits_importer', "import { after, kept } from '@canvas-ssr-test/awaits-changer'; import { sibling } from '@canvas-ssr-test/awaits-sibling';", '`${after} ${kept()}`');
        self::assertSame('<p>number:number number:number</p>', $this->ssrMarkup($importer));
      },

      // The module has evaluated by then, so it can't throw. The island does
      // instead, when it begins, and so does every later island that
      // imports one of its modules: they'd render without the change. One
      // of another component is told whose modules did it.
      "what a component's module leaves running may not change what islands share" => function (): void {
        $later = $this->component('later_changer', 'Promise.resolve().then(() => { globalThis.__ssrTestLater = 1; console.__ssrTestLater = 1; });', '`${typeof globalThis.__ssrTestLater}:${typeof console.__ssrTestLater}`');
        $reader = $this->component('later_reader', '', '`${typeof globalThis.__ssrTestLater}:${typeof console.__ssrTestLater}`');
        $importer = $this->createComponent('later_importer', "import { h } from 'preact'; import C from '@/components/later_changer'; export default function I() { return h(C); }", imports: ['later_changer'], machine_name: 'later_importer');
        foreach ([$later, $later, $importer] as $island) {
          $output = $this->renderComponent($island, cacheability: $cacheability);
          self::assertStringContainsString('client="only"', $output, (string) $island->id());
          self::assertSame(Cache::PERMANENT, $cacheability->getCacheMaxAge());
          $warnings = $this->logger->at((string) RfcLogLevel::WARNING);
          self::assertStringContainsString($island === $later
            ? 'the later_changer component on the client, since something its modules left running changes what SSR shares between islands'
            : 'the later_importer component on the client, since it shares code with the later_changer component, which rendered earlier, and something its modules left running changes what SSR shares between islands', (string) \end($warnings));
          self::assertStringContainsString('the global __ssrTestLater, console', (string) \end($warnings));

          // Nothing it changed is left for the next island.
          self::assertSame('<p>undefined:undefined</p>', $this->ssrMarkup($reader));
        }

        // However long it runs for: the runner runs the job queue dry
        // before the island begins.
        $long = $this->component('later_long', '(async () => { for (let i = 0; i < 1000; i++) await null; globalThis.__ssrTestLater = 1; })();', 'typeof globalThis.__ssrTestLater');
        self::assertStringContainsString('client="only"', $this->renderComponent($long));
        $warnings = $this->logger->at((string) RfcLogLevel::WARNING);
        self::assertStringContainsString('the later_long component on the client, since something its modules left running', (string) \end($warnings));
        self::assertSame('<p>undefined:undefined</p>', $this->ssrMarkup($reader));
      },

      // What it left running had run before the earlier island rendered.
      "a module an earlier island evaluated isn't blamed for what a later one's left running" => function (): void {
        $this->component('blame_child', '', '"child"');
        $user = $this->createComponent('blame_user', "import { h } from 'preact'; import C from '@/components/blame_child'; export default function U() { return h(C); }", imports: ['blame_child'], machine_name: 'blame_user');
        $culprit = $this->createComponent('blame_culprit', "import { h } from 'preact'; import C from '@/components/blame_child'; Promise.resolve().then(() => { globalThis.__ssrTestBlame = 1; }); export default function X() { return h(C); }", imports: ['blame_child'], machine_name: 'blame_culprit');
        self::assertSame('<p>child</p>', $this->ssrMarkup($user));
        self::assertStringContainsString('client="only"', $this->renderComponent($culprit));
        self::assertSame('<p>child</p>', $this->ssrMarkup($user));
      },

      // Both evaluated in the culprit's island, so which one did it can't be
      // told. The later island's fallback ends when the culprit's island's
      // dependencies change, as when the culprit is fixed.
      "a module evaluated with the one that left something running is blamed until the culprit changes" => function (): void {
        $this->component('blame_new_child', '', '"child"');
        $culprit = $this->createComponent('blame_new_culprit', "import { h } from 'preact'; import C from '@/components/blame_new_child'; Promise.resolve().then(() => { globalThis.__ssrTestBlameNew = 1; }); export default function X() { return h(C); }", imports: ['blame_new_child'], machine_name: 'blame_new_culprit');
        $user = $this->createComponent('blame_new_user', "import { h } from 'preact'; import C from '@/components/blame_new_child'; export default function U() { return h(C); }", imports: ['blame_new_child'], machine_name: 'blame_new_user');
        self::assertStringContainsString('client="only"', $this->renderComponent($culprit));
        $output = $this->renderComponent($user, cacheability: $cacheability);
        self::assertStringContainsString('client="only"', $output);
        $warnings = $this->logger->at((string) RfcLogLevel::WARNING);
        self::assertStringContainsString('the blame_new_user component on the client, since it shares code with the blame_new_culprit component', (string) \end($warnings));
        self::assertSame([], \array_diff($culprit->getCacheTags(), $cacheability->getCacheTags()));
      },

      // The library has evaluated before the component's modules begin, so
      // it isn't awaiting when what one of them left running runs, and
      // doesn't get to keep it.
      "what a component's module leaves running isn't a library's that awaits" => function (): void {
        $this->component('later_badge', 'Promise.resolve().then(() => { globalThis.__ssrTestBadge = 1; });', '"badge"');
        $root = $this->createComponent('later_badge_root', "import { h } from 'preact'; import Badge from '@/components/later_badge'; import { done } from '@canvas-ssr-test/slow-c'; export default function R() { return h(Badge); }", imports: ['later_badge'], machine_name: 'later_badge_root');
        self::assertStringContainsString('client="only"', $this->renderComponent($root));
        $warnings = $this->logger->at((string) RfcLogLevel::WARNING);
        self::assertStringContainsString('left running changed the global __ssrTestBadge', (string) \end($warnings));
        $reader = $this->component('later_badge_reader', '', 'typeof globalThis.__ssrTestBadge');
        self::assertSame('<p>undefined</p>', $this->ssrMarkup($reader));
      },

      // A library evaluates before the component's modules, so what it left
      // running has run by the time they do, and is the library's.
      'what a library leaves running may change what islands share' => function (): void {
        $importer = $this->component('library_later', "import { kept } from '@canvas-ssr-test/library-later';", 'kept()');
        self::assertSame('<p>number</p>', $this->ssrMarkup($importer));
        $user = $this->component('library_later_user', '', 'typeof globalThis.__ssrTestLibraryLater');
        self::assertSame('<p>number</p>', $this->ssrMarkup($user));

        // Even if the island it first evaluated for never began: the
        // library won't evaluate again. The reader has rendered before, and
        // had its libraries evaluated, so none of its modules evaluates
        // after the island that fails.
        $reader = $this->component('library_later_b_reader', "import { once } from '@canvas-ssr-test/once';", 'typeof globalThis.__ssrTestLibraryLaterB');
        $failing = $this->component('library_later_failing', "import { kept } from '@canvas-ssr-test/library-later-b'; import '@canvas-ssr-test/no-such-module';", 'kept()');
        self::assertSame('<p>undefined</p>', $this->ssrMarkup($reader));
        self::assertStringContainsString('client="only"', $this->renderComponent($failing));
        self::assertSame('<p>number</p>', $this->ssrMarkup($reader));
      },

      // No island began, to throw for what its modules left running. That's
      // dropped, not held against the next island.
      "what the modules of an island that never began left running isn't the next island's" => function (): void {
        $warned = function (): int {
          return \count($this->logger->at((string) RfcLogLevel::WARNING));
        };
        // Its own module throws.
        $thrower = $this->component('stale_thrower', 'Promise.resolve().then(() => { globalThis.__ssrTestStale = 1; }); throw new Error("Thrown while evaluating.");', '"unreachable"');
        self::assertStringContainsString('client="only"', $this->renderComponent($thrower));
        $before = $warned();
        $next = $this->component('stale_next', 'const evaluated = true;', 'typeof globalThis.__ssrTestStale');
        self::assertSame('<p>undefined</p>', $this->ssrMarkup($next));
        self::assertSame($before, $warned());

        // A library it imports throws, after a component it imports
        // evaluated.
        $this->component('stale_leaver', 'Promise.resolve().then(() => { globalThis.__ssrTestStale = 2; });', '"leaver"');
        $root = $this->createComponent('stale_root', "import { h } from 'preact'; import Leaver from '@/components/stale_leaver'; import '@canvas-ssr-test/throws'; export default function R() { return h(Leaver); }", imports: ['stale_leaver'], machine_name: 'stale_root');
        self::assertStringContainsString('client="only"', $this->renderComponent($root));
        $before = $warned();
        $next = $this->component('stale_next_2', 'const evaluated = true;', 'typeof globalThis.__ssrTestStale');
        self::assertSame('<p>undefined</p>', $this->ssrMarkup($next));
        self::assertSame($before, $warned());
      },

      // Not covered: such a library evaluates among the component's
      // modules, and what changes while it awaits can't be told from what
      // it changed. ADR 11 lists it.
      'what changes while a library imported by a computed specifier awaits is kept as its own' => function (): void {
        // Outside a render: what a component's module left running.
        $leaver = $this->component('gap_leaver', "import { onStarted } from '@canvas-ssr-test/signal';\nonStarted(() => { Promise.resolve().then(() => { globalThis.__ssrTestGapModule = 1; }); });\nawait import(String('@canvas-ssr-test/slow-d'));", 'typeof globalThis.__ssrTestGapModule');
        self::assertSame('<p>number</p>', $this->ssrMarkup($leaver));

        // During one: a global the render added.
        $adder = $this->awaiter('gap_adder', '@canvas-ssr-test/slow-e', '(globalThis.__ssrTestGapRender = 1, "rendered")');
        self::assertSame('<p>rendered</p>', $this->ssrMarkup($adder));

        $reader = $this->component('gap_reader', '', '`${typeof globalThis.__ssrTestGapModule}:${typeof globalThis.__ssrTestGapRender}`');
        self::assertSame('<p>number:number</p>', $this->ssrMarkup($reader));
      },

      // Not as in the browser, where it may not have by the first render.
      // ADR 11 lists it.
      'what a module left running has finished before the island renders' => function (): void {
        $settled = $this->component('settled', "import { state as library } from '@canvas-ssr-test/settles';\nlet own = 'pending';\n(async () => { for (let i = 0; i < 1000; i++) await null; own = 'done'; })();", '`${own}:${library()}`');
        self::assertSame('<p>done:done</p>', $this->ssrMarkup($settled));
      },

      // Not as in the browser either. ADR 11 lists it.
      "a library named in an import() the render never reaches evaluates anyway" => function (): void {
        $namer = $this->component('unreached_namer', "function never() { return import('@canvas-ssr-test/unreached'); }", 'typeof globalThis.__ssrTestUnreached');
        self::assertSame('<p>number</p>', $this->ssrMarkup($namer));
      },

      // As a browser's window doesn't exist during SSR, a component may
      // set it. The next island mustn't see it.
      "a global a render adds is gone for the next island, however it's added" => function (): void {
        $adds = [
          'assigned' => 'globalThis.window = globalThis',
          'defined' => 'Object.defineProperty(globalThis, "window", { value: globalThis })',
          'defined_non_configurable' => 'Object.defineProperty(globalThis, "window", { value: globalThis, configurable: false })',
          'defined_together' => 'Object.defineProperties(globalThis, { window: { value: globalThis } })',
          'reflected' => 'Reflect.defineProperty(globalThis, "window", { value: globalThis })',
        ];
        foreach ($adds as $name => $add) {
          $setter = $this->component("sets_window_$name", "function set() { $add; return typeof window; }", 'set()');
          self::assertSame('<p>object</p>', $this->ssrMarkup($setter));

          // At module scope too, where a module that evaluates later reads
          // it.
          $reader = $this->component("reads_window_$name", 'const seen = typeof window;', '`${seen}:${typeof window}`');
          self::assertSame('<p>undefined:undefined</p>', $this->ssrMarkup($reader));
        }
      },

      'a global a library adds while it evaluates is kept' => function (): void {
        $registrar = $this->component('registrar', 'import "@canvas-ssr-test/registry";', '++globalThis.__ssrTestRegistry.count');
        self::assertSame('<p>1</p>', $this->ssrMarkup($registrar));
        self::assertSame('<p>2</p>', $this->ssrMarkup($registrar));

        // It's global, so other components' islands see it too.
        $reader = $this->component('registry_reader', '', 'typeof globalThis.__ssrTestRegistry');
        self::assertSame('<p>object</p>', $this->ssrMarkup($reader));
      },

      // What it holds isn't, as ADR 11 says.
      "a global a library adds while it evaluates is put back if a render reassigns or deletes it" => function (): void {
        $owner = $this->component('config_owner', 'import "@canvas-ssr-test/config";', 'globalThis.__ssrTestConfig.theme');
        self::assertSame('<p>light</p>', $this->ssrMarkup($owner));

        $changes = [
          'reassigns' => 'globalThis.__ssrTestConfig = { theme: "forged" }',
          'deletes' => 'delete globalThis.__ssrTestConfig',
          'redefines' => 'Object.defineProperty(globalThis, "__ssrTestConfig", { get: () => ({ theme: "forged" }) })',
        ];
        foreach ($changes as $name => $change) {
          $changer = $this->component("{$name}_config", "function change() { $change; return 'changed'; }", 'change()');
          self::assertSame('<p>changed</p>', $this->ssrMarkup($changer));

          self::assertSame('<p>light</p>', $this->ssrMarkup($owner), "After a component that $name it.");
        }

        $mutator = $this->component('mutates_config', 'function change() { globalThis.__ssrTestConfig.theme = "dark"; return "changed"; }', 'change()');
        self::assertSame('<p>changed</p>', $this->ssrMarkup($mutator));
        self::assertSame('<p>dark</p>', $this->ssrMarkup($owner));
      },
    ]);
  }

  /**
   * Asserts the last warning says a component wrote to something frozen.
   *
   * @param string $component
   *   The component's machine name.
   */
  private function assertWarnedOfFrozenWrite(string $component): void {
    $warnings = $this->logger->at((string) RfcLogLevel::WARNING);
    self::assertNotSame([], $warnings);
    self::assertStringContainsString("the $component component on the client, since it wrote to a built-in", (string) \end($warnings));
  }

  /**
   * Asserts the last warning says a component read page data too early.
   *
   * @param string $component
   *   The component's machine name.
   */
  private function assertWarnedOfPageDataRead(string $component): void {
    $warnings = $this->logger->at((string) RfcLogLevel::WARNING);
    self::assertNotSame([], $warnings);
    self::assertStringContainsString("the $component component on the client, since it read page data or interface translations while a module it imported during the render evaluated", (string) \end($warnings));
  }

  /**
   * Creates a component that imports a module while it renders.
   *
   * It declares site and page data, and renders once the import has
   * settled. The specifier is computed, since a library a component names
   * evaluates before its island renders.
   *
   * @param string $machine_name
   *   The machine name.
   * @param string $specifier
   *   What it imports. The module exports `read`.
   * @param string $expression
   *   The JS expression to render, which may use `read`.
   */
  private function importer(string $machine_name, string $specifier, string $expression): JavaScriptComponent {
    return $this->createComponent($machine_name, <<<JS
      import { h } from "preact";
      let read = null;
      let imported = false;
      export default function C() {
        if (!imported) {
          throw import(String("$specifier")).then((module) => { read = module.read; imported = true; });
        }
        return h("p", null, $expression);
      }
      JS, data_dependencies: ['drupalSettings' => ['v0.baseUrl', 'v0.pageTitle']], machine_name: $machine_name);
  }

  /**
   * Creates a component that renders while a module it imported awaits.
   *
   * It declares site and page data. The specifier is computed, as in
   * importer().
   *
   * @param string $machine_name
   *   The machine name.
   * @param string $specifier
   *   What it imports: a module that awaits at the top level.
   * @param string $expression
   *   The JS expression to render.
   */
  private function awaiter(string $machine_name, string $specifier, string $expression): JavaScriptComponent {
    return $this->createComponent($machine_name, <<<JS
      import { h } from "preact";
      let started = false;
      export default function C() {
        if (!started) {
          throw new Promise((resolve) => {
            globalThis.__ssrTestStarted = () => { started = true; resolve(); };
            import(String("$specifier"));
          });
        }
        return h("p", null, $expression);
      }
      JS, data_dependencies: ['drupalSettings' => ['v0.baseUrl', 'v0.pageTitle']], machine_name: $machine_name);
  }

  /**
   * Creates a component that renders an expression in a paragraph.
   *
   * @param string $machine_name
   *   The machine name.
   * @param string $module_scope
   *   JS that runs when the component's module evaluates.
   * @param string $expression
   *   The JS expression to render.
   */
  private function component(string $machine_name, string $module_scope, string $expression): JavaScriptComponent {
    return $this->createComponent(
      $machine_name,
      "import { h } from 'preact';\n$module_scope\nexport default function C() { return h('p', null, $expression); }",
      machine_name: $machine_name,
    );
  }

  /**
   * Renders a component that must render on the server.
   *
   * @param \Drupal\canvas\Entity\JavaScriptComponent $component
   *   The component.
   *
   * @return string
   *   Its server-rendered markup.
   */
  private function ssrMarkup(JavaScriptComponent $component): string {
    $output = $this->renderComponent($component);
    self::assertStringNotContainsString('client="only"', $output);

    return $this->extractSsrMarkup($output);
  }

}
