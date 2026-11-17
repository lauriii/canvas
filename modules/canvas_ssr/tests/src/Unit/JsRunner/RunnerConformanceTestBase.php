<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_ssr\Unit\JsRunner;

use Drupal\canvas_ssr\JsRunner\Exception\MemoryLimitException;
use Drupal\canvas_ssr\JsRunner\Exception\ScriptException;
use Drupal\canvas_ssr\JsRunner\Exception\TimeLimitException;
use Drupal\canvas_ssr\JsRunner\RunnerGuard;
use Drupal\canvas_ssr\JsRunner\RunnerInterface;
use Drupal\canvas_ssr\JsRunner\RunnerSelector;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * What canvas_ssr needs from any runner, tested against a real one.
 *
 * A runner module extends this for its runner, returns an instance from
 * createRunner(), and marks itself #[Large]. The attribute isn't
 * inherited, and it matters: a runner that can't stop a module that never
 * finishes would otherwise hang the suite.
 *
 * Tests go through the interface and run JS, never into a runner's
 * internals. The runner sits behind RunnerGuard and RunnerSelector, as in
 * a real render.
 *
 * If the runner reports itself unavailable, every case skips.
 *
 * @see \Drupal\canvas_ssr\JsRunner\RunnerInterface
 * @see docs/adr/0004-runners-are-pluggable-and-one-ships.md
 */
abstract class RunnerConformanceTestBase extends UnitTestCase {

  /**
   * A base's modules to compile, some of which can't load.
   */
  protected const array MODULES_TO_COMPILE = [
    '/compiled/fine.js' => 'export default () => "F";',
    '/compiled/unresolved.js' => 'import x from "missing"; export default () => x;',
    '/compiled/broken.js' => 'export const = ;',
    '/compiled/imports-broken.js' => 'import "/compiled/broken.js"; export default () => "";',
    '/compiled/bound.js' => 'export const got = globalThis.host(); export default () => got;',
  ];

  /**
   * Those of MODULES_TO_COMPILE that can't load.
   */
  protected const array UNLOADABLE_MODULES_TO_COMPILE = [
    '/compiled/unresolved.js',
    '/compiled/broken.js',
    '/compiled/imports-broken.js',
  ];

  /**
   * The ECMAScript globals, which belong to the language, not the host.
   */
  private const ECMASCRIPT_GLOBALS = [
    'AggregateError', 'Array', 'ArrayBuffer', 'AsyncDisposableStack',
    'Atomics', 'BigInt', 'BigInt64Array', 'BigUint64Array', 'Boolean',
    'DataView', 'Date', 'DisposableStack', 'Error', 'EvalError',
    'FinalizationRegistry', 'Float16Array', 'Float32Array', 'Float64Array',
    'Function', 'Infinity', 'Int8Array', 'Int16Array', 'Int32Array', 'Intl',
    'Iterator', 'JSON', 'Map', 'Math', 'NaN', 'Number', 'Object', 'Promise',
    'Proxy', 'RangeError', 'ReferenceError', 'Reflect', 'RegExp', 'Set',
    'SharedArrayBuffer', 'String', 'SuppressedError', 'Symbol', 'SyntaxError',
    'Temporal', 'TypeError', 'URIError', 'Uint8Array', 'Uint8ClampedArray',
    'Uint16Array', 'Uint32Array', 'WeakMap', 'WeakRef', 'WeakSet', 'decodeURI',
    'decodeURIComponent', 'encodeURI', 'encodeURIComponent', 'escape', 'eval',
    'globalThis', 'isFinite', 'isNaN', 'parseFloat', 'parseInt', 'undefined',
    'unescape',
    // A non-standard error class some engines add, for errors like stack
    // overflow.
    'InternalError',
  ];

  /**
   * The web platform APIs supported during SSR, sorted.
   *
   * The list can evolve as components need more.
   */
  private const WEB_PLATFORM_GLOBALS = [
    'DOMException', 'Headers', 'TextDecoder', 'TextEncoder', 'URL',
    'URLSearchParams', 'atob', 'btoa', 'console', 'performance', 'queueMicrotask',
  ];

  /**
   * A module whose default export returns a fixed string.
   */
  private const MODULE_RETURNING_A = 'export default function() { return "A"; }';

  /**
   * The runner under test, behind the guard and the selector.
   */
  protected RunnerInterface $runner;

  /**
   * Returns a runner to hold to the contract.
   *
   * Called once per test, so no state carries over. Construction mustn't
   * need what it runs on, like a binary; isAvailable() reports that.
   *
   * If the runner's execution limit can be set, set it as low as these tests
   * allow. The modules are tiny, and the limit is how long
   * testRunnerInterruptsWhatNeverFinishes() takes.
   *
   * @return \Drupal\canvas_ssr\JsRunner\RunnerInterface
   *   The runner to test.
   */
  abstract protected function createRunner(): RunnerInterface;

  /**
   * Returns a runner that can run out of memory before it runs out of time.
   *
   * Give it a memory limit as low as it can run with, and a time limit long
   * enough to fill it: testRunnerEndsWhatOutgrowsItsMemory() allocates until
   * the runner stops it. A runner with no memory limit skips the test, with
   * markTestSkipped(), and says so in limitations().
   *
   * @return \Drupal\canvas_ssr\JsRunner\RunnerInterface
   *   The runner to test.
   */
  abstract protected function createRunnerToRunOutOfMemory(): RunnerInterface;

  /**
   * Names the globals the runner adds for its own use.
   *
   * Every main module sees them besides the supported web platform APIs.
   * None may give a component a way to reach outside its render.
   *
   * @return list<string>
   *   The globals' names.
   */
  protected function runnerGlobals(): array {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $under_test = $this->createRunner();
    $this->runner = new RunnerGuard(new RunnerSelector([$under_test]));
    if (!$this->runner->isAvailable()) {
      self::markTestSkipped(\sprintf(
        '%s reports itself unavailable in this environment.',
        $under_test::class,
      ));
    }
  }

  /**
   * Tests a registered module's default export is what invoke() returns.
   */
  public function testInvokeReturnsMainModuleDefaultExport(): void {
    $this->runner->addModule('/m.js', self::MODULE_RETURNING_A);

    self::assertSame('A', $this->runner->invoke('/m.js', '""'));
  }

  /**
   * Tests the payload reaches the main module's default export.
   */
  public function testInvokePassesItsPayloadToMainModule(): void {
    $this->runner->addModule(
      '/m.js',
      'export default function(payload) { return JSON.parse(payload).name; }',
    );

    self::assertSame('canvas', $this->runner->invoke('/m.js', '{"name":"canvas"}'));
  }

  /**
   * Tests modules can be registered in any order.
   *
   * The renderer doesn't know a module's imports, so an importer may come
   * before what it imports.
   */
  public function testModulesRegisterInAnyOrder(): void {
    $this->runner->addModule(
      '/m.js',
      'import { value } from "/dep.js"; export default function() { return value; }',
    );
    $this->runner->addModule('/dep.js', 'export const value = "D";');

    self::assertSame('D', $this->runner->invoke('/m.js', '""'));
  }

  /**
   * Tests modules that import each other load.
   *
   * Bundlers split shared code into chunks that can import each other.
   */
  public function testCyclicImportsLoad(): void {
    $this->runner->addModule('/a.js', 'import { b } from "/b.js"; export const a = "A"; export const both = () => a + b;');
    $this->runner->addModule('/b.js', 'import { a } from "/a.js"; export const b = "B"; export const ab = () => a + b;');
    $this->runner->addModule(
      '/m.js',
      'import { both } from "/a.js"; import { ab } from "/b.js"; export default function() { return both() + ab(); }',
    );

    self::assertSame('ABAB', $this->runner->invoke('/m.js', '""'));
  }

  /**
   * Tests modules in a cycle that can't load fail each time, and only they.
   *
   * A module that imports what nothing provides is an everyday mistake. The
   * modules it's in a cycle with can't load either, however often they're
   * tried, and the realm is as good as before for the ones that can.
   */
  public function testCyclicImportsThatCantLoadFailEachTime(): void {
    $this->runner->addModule('/a.js', 'import { b } from "/b.js"; import "/nowhere.js"; export const a = "A";');
    $this->runner->addModule('/b.js', 'import { a } from "/a.js"; export const b = "B";');
    $this->runner->addModule('/ok.js', 'export const ok = "ok";');
    $this->runner->addModule('/m.js', <<<'JS'
      export default async function() {
        const out = [];
        for (const url of ["/a.js", "/b.js", "/a.js", "/b.js", "/ok.js"]) {
          try {
            await import(url);
            out.push("loaded");
          }
          catch {
            out.push("failed");
          }
        }
        return out.join(",");
      }
      JS);

    self::assertSame('failed,failed,failed,failed,loaded', $this->runner->invoke('/m.js', '""'));
    self::assertSame('failed,failed,failed,failed,loaded', $this->runner->invoke('/m.js', '""'));
  }

  /**
   * Tests a changed source under a registered module URL replaces it.
   *
   * A runner may skip an identical source, but not a changed one under the
   * same module URL.
   */
  public function testChangedModuleSourceReplacesRegisteredOne(): void {
    $this->runner->addModule('/m.js', self::MODULE_RETURNING_A);
    self::assertSame('A', $this->runner->invoke('/m.js', '""'));

    $this->runner->addModule('/m.js', 'export default function() { return "B"; }');

    self::assertSame('B', $this->runner->invoke('/m.js', '""'));
  }

  /**
   * Tests a registration survives everything short of reset().
   *
   * RunnerGuard forwards each source once until reset(). A runner may
   * rebuild itself between calls, but it has to keep its registrations.
   *
   * This does what the renderer does between registering and invoking: use
   * a base, bind a function, and render another island.
   */
  public function testRegistrationSurvivesEverythingBeforeInvoke(): void {
    $this->runner->addModule('/dep.js', 'export const value = "D";');
    $this->runner->addModule(
      '/m.js',
      'import { value } from "/dep.js"; export default function() { return value; }',
    );

    $this->useMap(['imports' => ['preact' => '/preact.js']]);
    $this->runner->bindFunction(
      'conformanceProbe',
      static fn(string $argument): string => $argument,
      '(function () { return ""; })',
      '(function (result) { return result; })',
    );
    // Another island, registered and rendered in between, as a page does.
    $this->runner->addModule('/other.js', self::MODULE_RETURNING_A);
    self::assertSame('A', $this->runner->invoke('/other.js', '""'));

    // Neither module is re-registered, as with the guard.
    self::assertSame('D', $this->runner->invoke('/m.js', '""'));
  }

  /**
   * Tests reset() lets an identical source register again and take effect.
   *
   * Any memo of what the runner has has to go too. Otherwise the second
   * registration is skipped and the module is missing.
   */
  public function testResetLetsIdenticalSourceRegisterAgain(): void {
    $this->runner->addModule('/m.js', self::MODULE_RETURNING_A);
    self::assertSame('A', $this->runner->invoke('/m.js', '""'));

    $this->runner->reset();
    // Byte-identical to the source registered above.
    $this->runner->addModule('/m.js', self::MODULE_RETURNING_A);

    self::assertSame('A', $this->runner->invoke('/m.js', '""'));
  }

  /**
   * Tests a script failure keeps the realm, and the module state in it.
   *
   * A browser keeps its page after a throw. The realm setup module keeps
   * built-ins and globals from changing, so what a failed invoke leaves is
   * its own modules' state, as in the browser. Replacing the realm would
   * cost every later island its modules' evaluation.
   *
   * The state lives in a dependency, like the real one does.
   */
  public function testScriptFailureKeepsTheRealm(): void {
    $this->registerCountingModules();
    $this->assertScriptFails('/m.js', '"fail"');

    $this->registerCountingModules();

    // Not "1:1": the failed render's count stays.
    self::assertSame('2:1', $this->runner->invoke('/m.js', '""'));
  }

  /**
   * Tests a module can await as it evaluates after others failed doing so.
   *
   * A script failure keeps the realm whatever the failed module was doing.
   * Deno 2.9 ends the process on the third such module, once two have
   * thrown after awaiting, unless the runner keeps its event loop turning.
   */
  public function testModuleAwaitsAfterOthersThrewAfterAwaiting(): void {
    $this->runner->addModule('/seen.js', 'export const seen = [];');
    foreach (['/thrower-1.js', '/thrower-2.js'] as $url) {
      $this->runner->addModule($url, <<<'JS'
        import { seen } from "/seen.js";
        seen.push(import.meta.url);
        await null;
        await null;
        throw new Error("Thrown after awaiting.");
        export default function () {}
        JS);
      $this->assertScriptFails($url, '""');
    }
    $this->runner->addModule('/awaiter.js', <<<'JS'
      import { seen } from "/seen.js";
      const awaited = await Promise.resolve("awaited");
      export default function () { return `${awaited} after ${seen.length}`; }
      JS);

    // The count shows the realm is the one the others failed in.
    self::assertSame('awaited after 2', $this->runner->invoke('/awaiter.js', '""'));
  }

  /**
   * Tests a promise chain an invoke didn't wait for has run when it returns.
   *
   * Otherwise it would run during a later invoke, between what that one
   * awaits, and could change what it sees.
   */
  public function testJobsAnInvokeLeavesRunBeforeItReturns(): void {
    $this->runner->addModule('/m.js', <<<'JS'
      let state = 'unset';
      export default function (payload) {
        if (JSON.parse(payload) === 'start') {
          (async () => {
            for (let i = 0; i < 1000; i++) await null;
            state = 'set';
          })();
          return 'started';
        }
        return state;
      }
      JS);
    self::assertSame('started', $this->runner->invoke('/m.js', '"start"'));
    self::assertSame('set', $this->runner->invoke('/m.js', '"read"'));
  }

  /**
   * Tests a job that throws, or rejects unhandled, doesn't end the invoke.
   *
   * A browser reports a throw from a queueMicrotask() callback, and a
   * rejection nothing handles, and carries on. So the invoke returns, the
   * jobs queued after it run, and the realm stays.
   */
  public function testJobThatThrowsIsPassedOver(): void {
    $this->runner->addModule('/m.js', <<<'JS'
      let ran = 0;
      queueMicrotask(() => { throw new Error('while evaluating'); });
      export default async function () {
        queueMicrotask(() => { throw new Error('in a job'); });
        queueMicrotask(() => { ran++; });
        Promise.reject(new Error('unhandled'));
        await null;
        return `ran ${ran}`;
      }
      JS);
    self::assertSame('ran 1', $this->runner->invoke('/m.js', '""'));
    self::assertSame('ran 2', $this->runner->invoke('/m.js', '""'));
  }

  /**
   * Tests what evaluation left running runs before the default export.
   *
   * A main module's evaluation left it running, and it has run by the time
   * the default export is called.
   *
   * A module evaluates once in a realm. So a promise chain it started and
   * didn't await would otherwise run during the first call of its export,
   * and not during later ones, and the first would see it half done.
   */
  public function testJobsEvaluationLeavesRunBeforeTheExportIsCalled(): void {
    $this->runner->addModule('/dependency.js', <<<'JS'
      export let state = 'unset';
      (async () => {
        for (let i = 0; i < 1000; i++) await null;
        state = 'set';
      })();
      JS);
    $this->runner->addModule('/m.js', <<<'JS'
      import { state as dependency } from "/dependency.js";
      let state = 'unset';
      (async () => {
        for (let i = 0; i < 1000; i++) await null;
        state = 'set';
      })();
      export default function () {
        return `${state}:${dependency}`;
      }
      JS);
    self::assertSame('set:set', $this->runner->invoke('/m.js', '""'));
    self::assertSame('set:set', $this->runner->invoke('/m.js', '""'));
  }

  /**
   * Tests the realm, and module state with it, lasts from invoke to invoke.
   *
   * A module evaluates once per realm, so islands after the first pay for
   * no evaluation. Another main module invoked in between doesn't end it.
   * reset() does.
   */
  public function testRealmLastsFromOneInvokeToTheNext(): void {
    $this->registerCountingModules();
    self::assertSame('1:1', $this->runner->invoke('/m.js', '""'));
    self::assertSame('2:2', $this->runner->invoke('/m.js', '""'));

    $this->runner->addModule('/other.js', self::MODULE_RETURNING_A);
    self::assertSame('A', $this->runner->invoke('/other.js', '""'));
    self::assertSame('3:3', $this->runner->invoke('/m.js', '""'));

    $this->runner->reset();
    $this->registerCountingModules();
    self::assertSame('1:1', $this->runner->invoke('/m.js', '""'));
  }

  /**
   * Registers a main module counting renders in a module it imports.
   *
   * A helper, so registrations are byte-identical.
   */
  private function registerCountingModules(): void {
    $this->runner->addModule('/counter.js', <<<'JS'
    let started = 0;
    let finished = 0;
    export function begin() { started += 1; }
    export function finish() { finished += 1; }
    export function report() { return started + ":" + finished; }
    JS);
    $this->runner->addModule('/m.js', <<<'JS'
    import { begin, finish, report } from "/counter.js";
    export default function(payload) {
      begin();
      if (JSON.parse(payload) === "fail") {
        throw new Error("Render failed halfway.");
      }
      finish();
      return report();
    }
    JS);
  }

  /**
   * Tests a bare import loads the module its import map entry names.
   *
   * The module is the one at the URL, without its query, as the
   * browser would load it. Both its named and default exports reach
   * the importer, and importing it two ways gives the same instance.
   */
  public function testImportMapEntryLoadsModuleAtItsUrl(): void {
    $this->useMap(['imports' => ['lib' => '/target.js?v=9#top']]);
    $this->runner->addModule('/target.js', 'export const named = "N"; export default "D"; export const state = {};');
    $this->runner->addModule('/m.js', <<<'JS'
import def, { named, state } from "lib";
import { state as same } from "/target.js";
export default function() { return def + named + (state === same); }
JS);

    self::assertSame('DNtrue', $this->runner->invoke('/m.js', '""'));
  }

  /**
   * Tests an import map entry to a module without a default export loads.
   */
  public function testImportMapEntryToModuleWithoutDefaultLoads(): void {
    $this->useMap(['imports' => ['lib' => '/target.js']]);
    $this->runner->addModule('/target.js', 'export const only = "OK";');
    $this->runner->addModule(
      '/m.js',
      'import { only } from "lib"; export default function() { return only; }',
    );

    self::assertSame('OK', $this->runner->invoke('/m.js', '""'));
  }

  /**
   * Tests a "prefix/" import map entry loads a subtree, by the longest key.
   *
   * A module loaded that way resolves its own relative imports from its
   * URL.
   */
  public function testImportMapPrefixEntryLoadsSubtree(): void {
    $this->useMap([
      'imports' => [
        '@/lib/' => '/site/lib/',
        '@/lib/special/' => '/special/',
      ],
    ]);
    $this->runner->addModule('/site/lib/a.js', 'import { b } from "./b.js"; export const a = "A" + b;');
    $this->runner->addModule('/site/lib/b.js', 'export const b = "B";');
    $this->runner->addModule('/special/c.js', 'export const c = "C";');
    $this->runner->addModule(
      '/m.js',
      'import { a } from "@/lib/a.js"; import { c } from "@/lib/special/c.js"; export default function() { return a + c; }',
    );

    self::assertSame('ABC', $this->runner->invoke('/m.js', '""'));
  }

  /**
   * Tests a registered URL loads as it is, whatever the map says.
   *
   * URLs are how modules import each other, so an import map entry for one
   * mustn't reroute those imports.
   */
  public function testRegisteredUrlWinsOverImportMap(): void {
    $this->useMap(['imports' => ['/dep.js' => '/other.js']]);
    $this->runner->addModule('/dep.js', 'export default "D";');
    $this->runner->addModule('/other.js', 'export default "O";');
    $this->runner->addModule('/m.js', 'import dep from "/dep.js"; export default () => dep;');

    self::assertSame('D', $this->runner->invoke('/m.js', '""'));
  }

  /**
   * Tests an import through a blocked import map entry fails.
   *
   * A value that isn't a string blocks, and so does one without the "/" its
   * "prefix/" key ends in. A less specific key mustn't answer in its place.
   *
   * @param string $specifier
   *   What the module imports.
   */
  #[DataProvider('providerBlockedSpecifiers')]
  public function testImportThroughBlockedImportMapEntryFails(string $specifier): void {
    $this->useMap([
      'imports' => [
        'blocked' => NULL,
        'lib/' => '/lib/',
        'lib/x/' => NULL,
        'lib/y/' => '/lib/y',
      ],
    ]);
    $this->runner->addModule('/lib/x/a.js', 'export default "A";');
    $this->runner->addModule('/lib/y/a.js', 'export default "A";');
    $this->runner->addModule('/m.js', \sprintf('import a from %s; export default () => a;', \json_encode($specifier)));

    $this->assertScriptFails('/m.js', '""');
  }

  /**
   * Provides specifiers a blocked import map entry matches.
   *
   * @return array<string, array{0: string}>
   *   The specifiers.
   */
  public static function providerBlockedSpecifiers(): array {
    return [
      'null value' => ['blocked'],
      'blocked subtree' => ['lib/x/a.js'],
      'value missing its slash' => ['lib/y/a.js'],
    ];
  }

  /**
   * Tests a "prefix/" import map entry reaches nothing outside its subtree.
   *
   * As the HTML standard says, a specifier that backtracks above the
   * entry's address fails, though a module is registered where it leads.
   */
  public function testImportMapPrefixEntryStaysInItsSubtree(): void {
    $this->useMap(['imports' => ['lib/' => '/site/lib/']]);
    $this->runner->addModule('/site/outside.js', 'export default "O";');
    $this->runner->addModule('/m.js', 'import o from "lib/../outside.js"; export default () => o;');

    $this->assertScriptFails('/m.js', '""');
  }

  /**
   * Tests an import map entry's absolute URL loads the module registered there.
   *
   * The module is the one at the URL without its query. import.meta.resolve()
   * still answers with the URL itself, as the browser would.
   */
  public function testImportMapAbsoluteUrlLoadsModuleAtThatUrl(): void {
    $this->useMap(['imports' => ['cdn' => 'https://cdn.example.com/x.js?v=2']]);
    $this->runner->addModule('https://cdn.example.com/x.js', 'export default "X";');
    $this->runner->addModule(
      '/m.js',
      'import x from "cdn"; export default () => x + " " + import.meta.resolve("cdn");',
    );

    self::assertSame('X https://cdn.example.com/x.js?v=2', $this->runner->invoke('/m.js', '""'));
  }

  /**
   * Tests modules at absolute URLs import each other as in a browser.
   *
   * Relative imports resolve against the importer's URL, and an import may
   * name a module's absolute URL as it is.
   */
  public function testModulesAtAbsoluteUrlsImportRelatively(): void {
    $cdn = 'https://cdn.example.com';
    $this->runner->addModule("$cdn/lib/a.js", 'import b from "./b.js"; import c from "../c.js"; export default b + c;');
    $this->runner->addModule("$cdn/lib/b.js", 'export default "B";');
    $this->runner->addModule("$cdn/c.js", 'export default "C";');
    $this->runner->addModule('/m.js', "import a from \"$cdn/lib/a.js\"; export default () => a;");

    self::assertSame('BC', $this->runner->invoke('/m.js', '""'));
  }

  /**
   * Tests a module at a protocol-relative URL resolves within its host.
   *
   * Some CDN modules give URLs without a scheme.
   */
  public function testModulesAtProtocolRelativeUrlsResolveWithinTheirHost(): void {
    $cdn = '//cdn.example.com';
    $this->runner->addModule("$cdn/lib/a.js", 'import b from "./b.js"; export default () => b + " " + import.meta.resolve("./x.png");');
    $this->runner->addModule("$cdn/lib/b.js", 'export default "B";');

    self::assertSame("B $cdn/lib/x.png", $this->runner->invoke("$cdn/lib/a.js", '""'));
  }

  /**
   * Tests a module at an absolute URL sees that URL in import.meta.
   *
   * Canvas compiles asset imports to import.meta.resolve() calls, and
   * bundlers to new URL(..., import.meta.url). Both land in markup, so both
   * must give the browser's answer for a module on a CDN.
   */
  public function testImportMetaAtAbsoluteUrl(): void {
    $cdn = 'https://cdn.example.com';
    $this->runner->addModule("$cdn/assets/card.js", <<<'JS'
export default function() {
  return JSON.stringify([
    import.meta.url,
    new URL("./hero.webp", import.meta.url).href,
    import.meta.resolve("./hero.webp"),
    import.meta.resolve("../shared/font.woff2"),
    import.meta.resolve("/root.png"),
  ]);
}
JS);

    self::assertSame(
      [
        "$cdn/assets/card.js",
        "$cdn/assets/hero.webp",
        "$cdn/assets/hero.webp",
        "$cdn/shared/font.woff2",
        "$cdn/root.png",
      ],
      self::decodeList($this->runner->invoke("$cdn/assets/card.js", '""')),
    );
  }

  /**
   * Tests dot segments go from specifiers, as in a browser.
   *
   * A path that ends in one keeps its trailing slash, and a rooted
   * specifier loses them too, in imports and in import.meta.resolve().
   */
  public function testDotSegments(): void {
    $cdn = 'https://cdn.example.com';
    foreach (['' => '/src/m.js', $cdn => "$cdn/src/m.js"] as $origin => $url) {
      $this->runner->addModule("$origin/lib.js", 'export default "lib";');
      $this->runner->addModule($url, <<<'JS'
import lib from "/src/../lib.js";
export default () => JSON.stringify([
  lib,
  import.meta.resolve("./"),
  import.meta.resolve("../"),
  import.meta.resolve("/src/../logo.svg"),
  import.meta.resolve("./x/."),
]);
JS);
      self::assertSame(
        ['lib', "$origin/src/", "$origin/", "$origin/logo.svg", "$origin/src/x/"],
        self::decodeList($this->runner->invoke($url, '""')),
        $url,
      );
    }
  }

  /**
   * Tests a base may hold modules at absolute URLs.
   */
  public function testBaseHoldsModulesAtAbsoluteUrls(): void {
    $cdn = 'https://cdn.example.com';
    $modules = [
      "$cdn/lib/a.js" => 'import b from "./b.js"; export default () => b + import.meta.url;',
      "$cdn/lib/b.js" => 'export default "B";',
    ];
    [, $result] = $this->useBase($modules, ["$cdn/lib/a.js"], ['imports' => ['lib' => "$cdn/lib/a.js"]]);
    self::assertTrue($result);

    $this->runner->addModule('/m.js', 'import a from "lib"; export default () => a();');
    self::assertSame("B$cdn/lib/a.js", $this->runner->invoke('/m.js', '""'));
    self::assertSame("B$cdn/lib/a.js", $this->runner->invoke("$cdn/lib/a.js", '""'));
  }

  /**
   * Tests an import whose import map entry names no registered module fails.
   */
  public function testImportOfUnregisteredMapTargetFails(): void {
    $this->useMap(['imports' => ['lib' => '/missing.js']]);
    $this->runner->addModule('/m.js', 'import lib from "lib"; export default () => lib;');

    $this->assertScriptFails('/m.js', '""');
  }

  /**
   * Tests a bound name keeps its first callback, and reset() frees the name.
   *
   * This lets the renderer bind fetch() before every render for free. After
   * reset(), binding again has to take effect, or fetch() is undefined.
   */
  public function testBindingIsKeyedByNameUntilReset(): void {
    $module = 'export default function() { return globalThis.probe(); }';
    $this->bindProbeReturning('first');
    // Same name, so this second bind is ignored.
    $this->bindProbeReturning('second');
    $this->runner->addModule('/m.js', $module);
    self::assertSame('first', $this->runner->invoke('/m.js', '""'));

    $this->runner->reset();

    $this->bindProbeReturning('second');
    $this->runner->addModule('/m.js', $module);

    self::assertSame('second', $this->runner->invoke('/m.js', '""'));
  }

  /**
   * Tests a stack trace a serializer takes names the modules that called.
   *
   * Src/Fetch/Marshaler/marshal-fetch-request.js relies on this, so that
   * PHP can tell which modules' code made a fetch.
   */
  public function testSerializerStackNamesTheCallingModules(): void {
    $stack = NULL;
    $this->runner->bindFunction(
      'probe',
      static function (string $argument) use (&$stack): string {
        $stack = $argument;
        return '';
      },
      '((StackError) => () => String(new StackError().stack))(Error)',
      '(raw) => raw',
    );
    $this->runner->addModule('https://example.com/lib/absolute.js', 'export function call() { return globalThis.probe(); }');
    $this->runner->addModule('/dep.js', 'import { call } from "https://example.com/lib/absolute.js"; export function viaDep() { return call(); }');
    $this->runner->addModule(
      '/m.js',
      'import { viaDep } from "/dep.js"; export default function() { viaDep(); return "done"; }',
    );

    self::assertSame('done', $this->runner->invoke('/m.js', '""'));
    $frames = \explode("\n", (string) $stack);
    // Innermost first, each ending with its module URL, line and column.
    $at = static function (string $url) use ($frames): int {
      foreach ($frames as $index => $frame) {
        if (\preg_match('/^\s*at (?!async )(.*):\d+:\d+\)?$/', $frame, $matches) === 1 && ($matches[1] === $url || \str_ends_with($matches[1], " ($url"))) {
          return $index;
        }
      }
      self::fail("No frame of $url in:\n" . \implode("\n", $frames));
    };
    self::assertLessThan($at('/dep.js'), $at('https://example.com/lib/absolute.js'));
    self::assertLessThan($at('/m.js'), $at('/dep.js'));
  }

  /**
   * Tests a bound function is called with the `this` binding, then arguments.
   *
   * Src/Fetch/Marshaler/marshal-fetch-request.js relies on this: it reads
   * fetch()'s input and init as its second and third parameters.
   */
  public function testBoundFunctionIsPassedItsCallArguments(): void {
    $this->runner->bindFunction(
      'probe',
      static fn(string $argument): string => 'php saw ' . $argument,
      '(thisVal, first, second) => `${first},${second}`',
      '(raw) => raw',
    );
    $this->runner->addModule(
      '/m.js',
      'export default function() { return globalThis.probe("a", "b"); }',
    );

    self::assertSame('php saw a,b', $this->runner->invoke('/m.js', '""'));
  }

  /**
   * Tests a module imports itself by its file name, however it was reached.
   *
   * Canvas_ssr appends that import to every module to freeze its exports.
   * The import map may add a query string, which names the same module.
   */
  public function testRelativeSelfImportIsTheSameModule(): void {
    $this->useMap(['imports' => ['x' => '/a/x.js?v=1']]);
    $this->runner->addModule('/a/x.js', 'import * as self from "./x.js"; export const o = {}; export const same = () => self.o === o;');
    $this->runner->addModule('/m.js', 'import { o, same } from "x"; import { o as direct } from "/a/x.js"; export default () => `${o === direct}:${same()}`;');

    self::assertSame('true:true', $this->runner->invoke('/m.js', '""'));
  }

  /**
   * Tests a module can't replace or delete a bound function.
   *
   * Renderer binds fetch(). A component that replaced it could read every
   * later component's requests and answer them.
   */
  public function testBoundFunctionCannotBeReplacedOrDeleted(): void {
    $this->bindProbeReturning('from PHP');
    $this->runner->addModule('/tamper.js', <<<'JS'
export default function() {
  const forged = () => 'forged';
  try { globalThis.probe = forged; } catch {}
  try { Object.defineProperty(globalThis, 'probe', { value: forged }); } catch {}
  try { delete globalThis.probe; } catch {}
  return 'tampered';
}
JS);
    $this->runner->addModule('/m.js', 'export default function() { return globalThis.probe(); }');

    self::assertSame('tampered', $this->runner->invoke('/tamper.js', '""'));
    self::assertSame('from PHP', $this->runner->invoke('/m.js', '""'));
  }

  /**
   * Tests a binding is defined with the built-ins as they were at first.
   *
   * The realm setup module wraps Object.defineProperty(), so a global a
   * component defines can be deleted. A binding installed after it must
   * still be non-configurable.
   */
  public function testBindingIsDefinedWithTheOriginalBuiltIns(): void {
    $this->runner->addModule('/wrap.js', <<<'JS'
export default function() {
  const object = Object.defineProperty;
  const reflect = Reflect.defineProperty;
  Object.defineProperty = (o, k, d) => object(o, k, { ...d, configurable: true });
  Reflect.defineProperty = (o, k, d) => reflect(o, k, { ...d, configurable: true });
  return 'wrapped';
}
JS);
    self::assertSame('wrapped', $this->runner->invoke('/wrap.js', '""'));
    $this->runner->bindFunction('late', static fn(): string => 'late', '() => ""', '(raw) => raw');
    $this->runner->addModule('/late.js', <<<'JS'
export default function() {
  const { configurable, writable } = Object.getOwnPropertyDescriptor(globalThis, 'late');
  return `${configurable}/${writable}/${globalThis.late()}`;
}
JS);

    self::assertSame('false/false/late', $this->runner->invoke('/late.js', '""'));
  }

  /**
   * Tests replacing built-ins doesn't change what the runner does later.
   *
   * A runner's own code carries results, binding calls and import.meta
   * answers between components. If it called a built-in that a component
   * had replaced, that component could read or rewrite what later ones send
   * and get. Each replacement may throw, in a realm that's locked.
   */
  public function testReplacedBuiltInsDoNotReachTheRunner(): void {
    $this->useMap(['imports' => ['@/logo.svg' => '/files/logo.svg']]);
    $this->runner->bindFunction(
      'probe',
      static fn(string $argument): string => 'php saw ' . $argument,
      '(thisVal, first, second) => first + "," + second',
      '(raw) => raw',
    );
    $this->runner->addModule('/tamper.js', <<<'JS'
const forged = () => 'forged';
const define = Object.defineProperty;
const replace = (target, key, value) => {
  try { target[key] = value; } catch {}
  try { define(target, key, { value, configurable: true, writable: true }); } catch {}
};
const replaceEach = (target, keys, value) => {
  for (let i = 0; i < keys.length; i++) replace(target, keys[i], value);
};
export default function() {
  replace(Object.prototype, 'toJSON', forged);
  replaceEach(String.prototype, ['indexOf', 'slice', 'startsWith', 'endsWith', 'split', 'toLowerCase', 'toUpperCase'], forged);
  replace(RegExp.prototype, 'exec', () => null);
  replace(RegExp.prototype, 'test', () => false);
  replace(Array.prototype, Symbol.iterator, function* () { yield 'forged'; });
  replaceEach(Array.prototype, ['push', 'pop', 'join', 'sort'], forged);
  replaceEach(globalThis, ['JSON', 'String', 'Error', 'TypeError', 'Reflect', 'Object', 'Array'], forged);
  return 'tampered';
}
JS);
    $this->runner->addModule('/canvas/later.js', <<<'JS'
export default function() {
  return globalThis.probe("a", "b") + "|" + import.meta.resolve("@/logo.svg")
    + "|" + import.meta.resolve("./hero.webp");
}
JS);

    self::assertSame('tampered', $this->runner->invoke('/tamper.js', '""'));
    self::assertSame(
      'php saw a,b|/files/logo.svg|/canvas/hero.webp',
      $this->runner->invoke('/canvas/later.js', '""'),
    );
  }

  /**
   * Tests a bound function returns its value, not a promise of one.
   *
   * The main module doesn't await, since invoke() settles a returned promise
   * and would hide the difference. A binding that should look async wraps its
   * result in its deserializer, as fetch()'s does.
   */
  public function testBoundFunctionReturnsSynchronously(): void {
    $this->bindProbeReturning('now');
    $this->runner->addModule(
      '/m.js',
      'export default function() { const r = globalThis.probe(); return typeof r + ":" + r; }',
    );

    self::assertSame('string:now', $this->runner->invoke('/m.js', '""'));
  }

  /**
   * Tests a callback that throws makes the bound function throw where called.
   *
   * So a component can catch a refused call like any other error.
   */
  public function testBoundFunctionThrowsWhatItsCallbackThrows(): void {
    $this->runner->bindFunction(
      'probe',
      static fn(string $argument): string => throw new \RuntimeException('refused'),
      '() => ""',
      '(raw) => raw',
    );
    $this->runner->addModule(
      '/m.js',
      'export default function() { try { globalThis.probe(); return "returned"; } catch (e) { return "threw"; } }',
    );

    self::assertSame('threw', $this->runner->invoke('/m.js', '""'));
  }

  /**
   * Tests a callback that suspends its Fiber doesn't suspend the caller's.
   *
   * Drupal renders placeholders in Fibers, and an entity load or alias
   * lookup suspends one to batch with the others. If that reached the
   * caller's Fiber mid-invoke(), another Fiber could invoke the same
   * runner while this call was still inside it. Two Fibers interleave here,
   * each suspending in its callback.
   */
  public function testBoundFunctionSuspendingDoesNotSuspendCaller(): void {
    $this->runner->bindFunction(
      'probe',
      static function (string $argument): string {
        \Fiber::suspend();
        \Fiber::suspend();
        return 'php saw ' . $argument;
      },
      '(thisVal, first) => first',
      '(raw) => raw',
    );
    $this->runner->addModule(
      '/m.js',
      'export default function(name) { return globalThis.probe(name); }',
    );

    $fibers = [];
    foreach (['a', 'b'] as $name) {
      $fibers[$name] = new \Fiber(fn(): string => $this->runner->invoke('/m.js', $name));
    }
    foreach ($fibers as $fiber) {
      $fiber->start();
    }
    foreach ($fibers as $name => $fiber) {
      self::assertTrue($fiber->isTerminated(), "invoke() for $name suspended.");
      self::assertSame('php saw ' . $name, $fiber->getReturn());
    }
  }

  /**
   * Tests Node's globals and built-in modules are absent.
   *
   * Otherwise a component would render on some runners and not others. The
   * specifier is built at run time so only the runner can refuse it.
   */
  public function testNodeApisAreAbsent(): void {
    $this->runner->addModule('/m.js', <<<'JS'
      export default async function () {
        const globals = ['Buffer', 'clearImmediate', 'global', 'process', 'setImmediate']
          .filter((name) => name in globalThis);
        const imported = [];
        for (const name of ['fs', 'path', 'os', 'crypto', 'module']) {
          try {
            await import(['node', name].join(':'));
            imported.push(name);
          } catch {}
        }
        return JSON.stringify({ globals, imported });
      }
      JS);

    self::assertSame(
      '{"globals":[],"imported":[]}',
      $this->runner->invoke('/m.js', '""'),
    );
  }

  /**
   * Tests every main module sees only the supported web platform APIs.
   *
   * It sees no other host API.
   *
   * Not just the first: they must survive the runner replacing its realm.
   * SSR's dependencies, like drupal-canvas's JSON:API client, read URL,
   * TextEncoder and TextDecoder while they evaluate. Any other host API,
   * like fetch() before Renderer binds it, a timer or a Worker, would let a
   * component reach outside its render, and render on some runners and not
   * others. A runner may add globals of its own, which runnerGlobals()
   * names.
   */
  public function testOnlySupportedWebPlatformApisArePresentForEveryModule(): void {
    $ecmascript = \json_encode(self::ECMASCRIPT_GLOBALS, JSON_THROW_ON_ERROR);
    $report_globals = <<<JS
      const ecmascript = new Set($ecmascript);
      export default function () {
        const host = Object.getOwnPropertyNames(globalThis)
          .filter((name) => !ecmascript.has(name))
          .sort();
        const clock = [];
        for (let o = performance; o !== Object.prototype; o = Object.getPrototypeOf(o)) {
          clock.push(...Object.getOwnPropertyNames(o).filter((name) => name !== 'constructor'));
        }
        clock.sort();
        return JSON.stringify({ host, clock, now: typeof performance.now });
      }
      JS;
    $host = [...self::WEB_PLATFORM_GLOBALS, ...$this->runnerGlobals()];
    \sort($host);
    $expected = \json_encode([
      'host' => $host,
      'clock' => ['now', 'timeOrigin'],
      'now' => 'function',
    ], JSON_THROW_ON_ERROR);

    $this->runner->addModule('/first.js', $report_globals);
    self::assertSame($expected, $this->runner->invoke('/first.js', '""'));

    $this->runner->addModule('/second.js', $report_globals);
    self::assertSame($expected, $this->runner->invoke('/second.js', '""'));
    self::assertSame($expected, $this->runner->invoke('/first.js', '""'));
  }

  /**
   * Tests SharedArrayBuffer is absent, as on a page that isn't isolated.
   *
   * Browsers only give it to cross-origin isolated pages, which a Drupal
   * site's aren't, so components can't use it in the browser either. With
   * it, Atomics.wait() could block a realm without using CPU.
   */
  public function testSharedArrayBufferIsAbsent(): void {
    $this->runner->addModule('/shared.js', 'export default () => typeof SharedArrayBuffer;');

    self::assertSame('undefined', $this->runner->invoke('/shared.js', '""'));
  }

  /**
   * Tests FinalizationRegistry is absent, and WeakRef kept.
   *
   * A registry's callbacks run once the collector frees an object, which
   * may be during a later island than the one that registered it. A
   * WeakRef runs no code.
   */
  public function testFinalizationRegistryIsAbsent(): void {
    $this->runner->addModule('/finalization.js', 'export default () => `${typeof FinalizationRegistry}|${typeof WeakRef}`;');

    self::assertSame('undefined|function', $this->runner->invoke('/finalization.js', '""'));
  }

  /**
   * Tests import.meta.resolve() answers from the import map.
   *
   * Canvas compiles asset imports into import.meta.resolve() calls, and the
   * result lands in markup. It has to match the browser's answer exactly,
   * cache-busting query included.
   */
  public function testImportMetaResolveAnswersFromImportMap(): void {
    $this->useMap([
      'imports' => [
        '@/assets/logo.svg' => '/sites/default/files/canvas/assets/logo.svg?v=9',
        // A trailing-slash key maps a subtree. The longest matching prefix
        // wins, whatever the order.
        '@/fonts/' => '/sites/default/files/canvas/fonts/',
        '@/fonts/bold/' => '/sites/default/files/canvas/fonts-bold/',
      ],
      // Scopes are ignored, so this one mustn't change the answer.
      'scopes' => ['/canvas/assets/' => ['@/assets/logo.svg' => '/scoped.svg']],
    ]);
    $this->runner->addModule('/canvas/assets/card.js', <<<'JS'
export default function() {
  return JSON.stringify([
    import.meta.resolve("@/assets/logo.svg"),
    import.meta.resolve("@/fonts/body.woff2"),
    import.meta.resolve("@/fonts/bold/body.woff2"),
  ]);
}
JS);

    self::assertSame(
      [
        '/sites/default/files/canvas/assets/logo.svg?v=9',
        '/sites/default/files/canvas/fonts/body.woff2',
        '/sites/default/files/canvas/fonts-bold/body.woff2',
      ],
      self::decodeList($this->runner->invoke('/canvas/assets/card.js', '""')),
    );
  }

  /**
   * Tests a relative specifier resolves against the module's own URL.
   *
   * The answer is a rooted path, whatever internal URL the runner gave the
   * module.
   */
  public function testImportMetaResolveWalksPathsFromModuleUrl(): void {
    $this->runner->addModule('/canvas/assets/nested/card.js', <<<'JS'
export default function() {
  return JSON.stringify([
    import.meta.resolve("./hero.webp"),
    import.meta.resolve("../shared/font.woff2"),
    import.meta.resolve("/root.png"),
    import.meta.resolve("https://cdn.example.com/x.png"),
  ]);
}
JS);

    self::assertSame(
      [
        '/canvas/assets/nested/hero.webp',
        '/canvas/assets/shared/font.woff2',
        '/root.png',
        'https://cdn.example.com/x.png',
      ],
      self::decodeList($this->runner->invoke('/canvas/assets/nested/card.js', '""')),
    );
  }

  /**
   * Tests a rooted import map entry claims a resolved relative specifier.
   *
   * The import map is checked after resolving, as the HTML Standard says.
   * That's what lets an import map entry redirect a component's sibling
   * asset.
   */
  public function testImportMapIsConsultedWithResolvedSpecifier(): void {
    $this->useMap([
      'imports' => ['/canvas/assets/hero.webp' => '/sites/default/files/hero.webp?v=9'],
    ]);
    $this->runner->addModule(
      '/canvas/assets/card.js',
      'export default function() { return import.meta.resolve("./hero.webp"); }',
    );

    self::assertSame(
      '/sites/default/files/hero.webp?v=9',
      $this->runner->invoke('/canvas/assets/card.js', '""'),
    );
  }

  /**
   * Tests an unresolvable bare specifier throws, as it would in the browser.
   *
   * The browser couldn't load it either. Failing makes the renderer fall
   * back to CSR.
   */
  public function testUnresolvableBareSpecifierFails(): void {
    $this->useMap(['imports' => ['preact' => '/canvas/preact.js']]);
    $this->runner->addModule(
      '/m.js',
      'const url = import.meta.resolve("chart.js"); export default function() { return url; }',
    );

    $this->assertScriptFails('/m.js', '""');
  }

  /**
   * Tests another base's import map replaces the one before.
   *
   * Nothing of the earlier one is left behind.
   *
   * Otherwise an island could put a stale URL from the previous import map in
   * its markup.
   */
  public function testAnotherBasesImportMapReplacesThePrevious(): void {
    $resolve_logo = 'export default function() {'
      . ' try { return import.meta.resolve("@/assets/logo.svg"); }'
      . ' catch (e) { return "unresolved"; } }';

    $this->useMap(['imports' => ['@/assets/logo.svg' => '/first.svg']]);
    $this->runner->addModule('/m.js', $resolve_logo);
    self::assertSame('/first.svg', $this->runner->invoke('/m.js', '""'));

    $this->useMap(['imports' => ['@/assets/logo.svg' => '/second.svg']]);
    self::assertSame('/second.svg', $this->runner->invoke('/m.js', '""'));

    $this->useMap([]);
    self::assertSame('unresolved', $this->runner->invoke('/m.js', '""'));
  }

  /**
   * Tests providing import.meta.resolve does not shift source line numbers.
   *
   * A preamble added to the source mustn't move the lines in error stack
   * traces.
   */
  public function testImportMetaResolveDoesNotShiftSourceLineNumbers(): void {
    $this->runner->addModule('/canvas/assets/card.js', <<<'JS'
const logo = import.meta.resolve("./logo.svg");
export default function() {
  return new Error(logo).stack;
}
JS);

    // The Error is constructed on line 3 of the source above.
    self::assertStringContainsString(
      '/canvas/assets/card.js:3:',
      $this->runner->invoke('/canvas/assets/card.js', '""'),
    );
  }

  /**
   * Tests a promise the main module returns settles within the one invoke().
   *
   * RenderToStringAsync() needs this for suspending components. A runner
   * that returns before draining microtasks would break every component,
   * even synchronous ones.
   */
  public function testPromiseMainModuleReturnsIsSettled(): void {
    $this->runner->addModule(
      '/m.js',
      'export default async function() { return await Promise.resolve("settled"); }',
    );

    self::assertSame('settled', $this->runner->invoke('/m.js', '""'));
  }

  /**
   * Tests a module that never returns is ended rather than run forever.
   *
   * It's a runner failure, not a script failure: the same module could
   * finish on a less busy host. It's a TimeLimitException, so the renderer
   * can tell it from other runner failures. A pass takes as long as the
   * runner's limit, which is why createRunner() should set it low. A runner
   * with no limit hangs here, which is why subclasses are marked #[Large].
   *
   * The runner may have dropped its registrations, so it's given it all again.
   */
  public function testRunnerInterruptsWhatNeverFinishes(): void {
    $this->runner->addModule(
      '/m.js',
      'while (true) { /* never finishes */ } export default function() { return "unreachable"; }',
    );

    try {
      $this->runner->invoke('/m.js', '""');
      self::fail('The invoke finished.');
    }
    catch (TimeLimitException) {
      $this->addToAssertionCount(1);
    }

    $this->runner->addModule('/n.js', self::MODULE_RETURNING_A);
    self::assertSame('A', $this->runner->invoke('/n.js', '""'));
  }

  /**
   * Tests a module that keeps allocating is ended at the memory limit.
   *
   * Like the time limit, it's a runner failure, since earlier islands may
   * have used the memory. It's a MemoryLimitException, so the renderer can
   * tell it from other runner failures. The runner may have dropped its
   * registrations, so it's given them again.
   */
  public function testRunnerEndsWhatOutgrowsItsMemory(): void {
    $runner = $this->createRunnerToRunOutOfMemory();
    $runner->addModule('/grows.js', 'export default () => { const kept = []; for (;;) kept.push(new Array(1e6).fill(0)); };');

    try {
      $runner->invoke('/grows.js', '""');
      self::fail('The invoke finished.');
    }
    catch (MemoryLimitException) {
      $this->addToAssertionCount(1);
    }

    $runner->addModule('/n.js', self::MODULE_RETURNING_A);
    self::assertSame('A', $runner->invoke('/n.js', '""'));
  }

  /**
   * Tests a module throwing while it evaluates is a script failure.
   */
  public function testModuleThrowingWhileEvaluatingIsScriptFailure(): void {
    $this->runner->addModule(
      '/m.js',
      'throw new Error("Loading failed."); export default function() { return "A"; }',
    );

    $this->assertScriptFails('/m.js', '""');
  }

  /**
   * Tests a script failure keeps the registrations.
   */
  public function testScriptFailureKeepsRegistrations(): void {
    $this->useMap(['imports' => ['preact' => '/canvas/preact.js', 'dep' => '/dep.js']]);
    $this->runner->addModule('/dep.js', 'export const value = "D";');
    $this->runner->bindFunction('probe', static fn(): string => 'P', '() => ""', '(r) => r');
    $this->runner->addModule('/fails.js', 'export default function() { throw new Error("No."); }');
    $this->assertScriptFails('/fails.js', '""');

    $this->runner->addModule(
      '/m.js',
      'import { value } from "dep"; export default function() { return value + globalThis.probe() + import.meta.resolve("preact"); }',
    );
    self::assertSame('DP/canvas/preact.js', $this->runner->invoke('/m.js', '""'));
  }

  /**
   * Tests reset() forgets the import map, and the base brings it back.
   *
   * A runner keeps what it built from a base through reset(), and isn't
   * given the base again, so it keeps the base's import map too.
   */
  public function testResetForgetsTheImportMapUntilTheBaseIsUsed(): void {
    $module = 'export default function() { try { return import.meta.resolve("preact"); } catch (e) { return "unresolved"; } }';
    $id = \bin2hex(\random_bytes(8));
    $base = ['modules' => [], 'preload' => [], 'importMap' => ['imports' => ['preact' => '/canvas/preact.js']]];
    self::assertNotFalse($this->runner->useBase($id, static fn() => [$id, $base]));
    $this->runner->reset();
    $this->runner->addModule('/m.js', $module);
    self::assertSame('unresolved', $this->runner->invoke('/m.js', '""'));

    self::assertNotFalse($this->runner->useBase($id, static fn() => self::fail('The base was asked for again.')));
    self::assertSame('/canvas/preact.js', $this->runner->invoke('/m.js', '""'));
  }

  /**
   * Tests a base its runner couldn't take leaves the import map in force.
   */
  public function testImportMapStaysWhenNoBaseIsTaken(): void {
    $this->useMap(['imports' => ['preact' => '/canvas/preact.js']]);
    try {
      $this->runner->useBase('throws', static fn() => throw new \RuntimeException('No base.'));
    }
    catch (\RuntimeException) {
    }
    $this->runner->addModule('/m.js', 'export default () => import.meta.resolve("preact");');
    self::assertSame('/canvas/preact.js', $this->runner->invoke('/m.js', '""'));
  }

  /**
   * Tests a base changes nothing a render can see.
   *
   * A render that registers what the base has gets the same output with
   * or without it. Imports in the base go through the import map as a
   * render's would.
   */
  public function testBaseLeavesOutputUnchanged(): void {
    $modules = [
      '/components/m.js' => 'import { value } from "dep"; export default () => value + "A";',
      '/components/dep.js' => 'export const value = "D";',
    ];
    $this->useBase($modules, ['/components/m.js'], ['imports' => ['dep' => '/components/dep.js']]);

    foreach ($modules as $path => $source) {
      $this->runner->addModule($path, $source);
    }
    self::assertSame('DA', $this->runner->invoke('/components/m.js', '""'));
  }

  /**
   * Tests a base's modules to compile evaluate where no render sees them.
   *
   * What a module does as it evaluates lasts the realm, so one that isn't
   * on the page would change islands it has nothing to do with. Each
   * evaluates only when a render imports it.
   */
  public function testModulesToCompileDoNotEvaluateForRenders(): void {
    $modules = [
      '/components/library.js' => 'globalThis.fromLibrary = "L";',
      '/components/unplaced.js' => 'globalThis.fromUnplaced = "U"; export default () => "U";',
      '/components/placed.js' => 'import "/components/library.js"; export default () => `${globalThis.fromLibrary}:${typeof globalThis.fromUnplaced}`;',
    ];
    [, $result] = $this->useBase($modules, ['/components/library.js'], compile: ['/components/unplaced.js', '/components/placed.js']);
    self::assertTrue($result);

    self::assertSame('L:undefined', $this->runner->invoke('/components/placed.js', '""'));
    self::assertSame('U', $this->runner->invoke('/components/unplaced.js', '""'));
    self::assertSame('L:string', $this->runner->invoke('/components/placed.js', '""'));
  }

  /**
   * Tests a render the base doesn't cover renders as without it.
   */
  public function testBaseNotCoveringRenderIsIgnored(): void {
    $this->useBase(['/other.js' => 'export default () => "O";'], ['/other.js']);

    $this->runner->addModule('/m.js', self::MODULE_RETURNING_A);

    self::assertSame('A', $this->runner->invoke('/m.js', '""'));
  }

  /**
   * Tests a base that can't be built from leaves renders as without it.
   *
   * It's only a cache, so a runner that can't build from one renders anyway,
   * each time it's asked.
   */
  public function testBaseThatCannotBeBuiltIsIgnored(): void {
    $modules = [
      '/components/fine.js' => 'export default () => "F";',
      '/components/broken.js' => 'export const = ;',
    ];
    [$id] = $this->useBase($modules, \array_keys($modules));

    foreach (['first', 'after a reset'] as $when) {
      $this->runner->addModule('/components/fine.js', 'export default () => "F";');
      self::assertSame('F', $this->runner->invoke('/components/fine.js', '""'), $when);
      $this->runner->reset();
      $this->runner->useBase($id, static fn() => [$id, ['modules' => $modules, 'preload' => \array_keys($modules), 'importMap' => []]]);
    }
  }

  /**
   * Tests a base whose preload modules all load is in use, without failures.
   */
  public function testCleanBaseIsInUse(): void {
    [, $result] = $this->useBase(['/components/fine.js' => 'export default () => "F";'], ['/components/fine.js']);

    self::assertTrue($result);
  }

  /**
   * Tests a base is asked for at most once per id, through reset().
   */
  public function testBaseIsAskedForOncePerId(): void {
    $base = ['modules' => ['/components/fine.js' => 'export default () => "F";'], 'preload' => ['/components/fine.js'], 'importMap' => []];
    $id = \bin2hex(\random_bytes(8));
    $gets = 0;
    $getDefinition = static function () use ($id, $base, &$gets): array {
      $gets++;
      return [$id, $base];
    };

    self::assertTrue($this->runner->useBase($id, $getDefinition));
    $this->runner->reset();
    self::assertTrue($this->runner->useBase($id, $getDefinition));
    self::assertSame(1, $gets);
  }

  /**
   * Tests what's built from a base is kept under the id $getDefinition returns.
   *
   * The base can change after its id is read, and then comes with its own,
   * so a runner never keeps what it built from one base under another's id.
   */
  public function testBuildIsKeptUnderTheReturnedId(): void {
    $base = ['modules' => ['/components/fresh.js' => 'export default () => "N";'], 'preload' => ['/components/fresh.js'], 'importMap' => []];
    $stale = \bin2hex(\random_bytes(8));
    $fresh = \bin2hex(\random_bytes(8));

    self::assertTrue($this->runner->useBase($stale, static fn() => [$fresh, $base]));
    $this->runner->reset();
    self::assertTrue($this->runner->useBase($fresh, static fn() => self::fail('The base was asked for again.')));
    self::assertSame('N', $this->runner->invoke('/components/fresh.js', '""'));
  }

  /**
   * Tests a preload module of the base in use runs without registering.
   *
   * It's invoked by its URL, as Renderer does.
   */
  public function testPreloadModuleRunsWithoutRegistering(): void {
    $base = [
      'modules' => [
        '/components/m.js' => 'import { value } from "dep"; export default () => value + "A";',
        '/components/dep.js' => 'export const value = "D";',
        '/components/unresolved.js' => 'import x from "missing"; export default () => x;',
      ],
      // A preload module that fails to load is left out, and the rest are
      // kept.
      'preload' => ['/components/unresolved.js', '/components/m.js'],
      'importMap' => ['imports' => ['dep' => '/components/dep.js']],
    ];
    $id = \bin2hex(\random_bytes(8));
    self::assertNotFalse($this->runner->useBase($id, static fn() => [$id, $base]));
    self::assertSame('DA', $this->runner->invoke('/components/m.js', '""'));
  }

  /**
   * Tests only preload modules that can't load are listed as unloadable.
   *
   * Renderer doesn't invoke those, so one that would render must not be
   * listed. A preload module needing a binding may fail in the build, which
   * has none, yet render. A list is never empty, which would read as FALSE.
   */
  public function testUnloadableModulesAreOnesThatCantLoad(): void {
    $modules = [
      '/components/fine.js' => 'export default () => "F";',
      '/components/unresolved.js' => 'import x from "missing"; export default () => x;',
      '/components/broken.js' => 'export const = ;',
      '/components/bound.js' => 'export const got = globalThis.host(); export default () => got;',
    ];
    [, $result] = $this->useBase($modules, \array_keys($modules));

    self::assertNotFalse($result);
    self::assertNotSame([], $result);
    $unloadable = $result === TRUE ? [] : $result;
    self::assertSame([], \array_diff($unloadable, ['/components/unresolved.js', '/components/broken.js']));
    foreach ($unloadable as $main_module) {
      $this->assertScriptFails($main_module, '""');
    }
    $this->runner->bindFunction('host', static fn(): string => '"B"', '() => ""', '(s) => JSON.parse(s)');
    self::assertSame('B', $this->runner->invoke('/components/bound.js', '""'));
    self::assertSame('F', $this->runner->invoke('/components/fine.js', '""'));
  }

  /**
   * Tests only modules to compile that can't load are listed as unloadable.
   *
   * A runner needn't check them, but one that does lists none that would
   * render. None evaluates in the build, so one needing a binding loads.
   */
  public function testUnloadableModulesToCompileAreOnesThatCantLoad(): void {
    [, $result] = $this->useBase(self::MODULES_TO_COMPILE, [], compile: \array_keys(self::MODULES_TO_COMPILE));

    self::assertNotFalse($result);
    self::assertNotSame([], $result);
    $unloadable = $result === TRUE ? [] : $result;
    self::assertSame([], \array_diff($unloadable, self::UNLOADABLE_MODULES_TO_COMPILE));
    foreach (self::UNLOADABLE_MODULES_TO_COMPILE as $main_module) {
      $this->assertScriptFails($main_module, '""');
    }
    $this->runner->bindFunction('host', static fn(): string => '"B"', '() => ""', '(s) => JSON.parse(s)');
    self::assertSame('B', $this->runner->invoke('/compiled/bound.js', '""'));
    self::assertSame('F', $this->runner->invoke('/compiled/fine.js', '""'));
  }

  /**
   * Uses a base under a new id, then again after a reset.
   *
   * @param array<string, string> $modules
   *   The modules, in registration order.
   * @param list<string> $preload
   *   Its preload modules.
   * @param array<string, mixed> $import_map
   *   Its import map. After the reset, the runner has it only from what it
   *   kept.
   * @param list<string> $compile
   *   Its modules to compile.
   *
   * @return array{0: string, 1: bool|list<string>}
   *   The id, and what the runner returned after the reset.
   */
  protected function useBase(array $modules, array $preload, array $import_map = [], array $compile = []): array {
    $base = ['modules' => $modules, 'preload' => $preload, 'compile' => $compile, 'importMap' => $import_map];
    $id = \bin2hex(\random_bytes(8));
    $this->runner->useBase($id, static fn() => [$id, $base]);
    $this->runner->reset();

    return [$id, $this->runner->useBase($id, static fn() => self::fail('The base was asked for again.'))];
  }

  /**
   * Puts an import map in force with a base of no modules.
   *
   * A base is the only way to give a runner an import map.
   *
   * @param array<string, mixed> $import_map
   *   The import map.
   */
  private function useMap(array $import_map): void {
    $id = 'map-' . \hash('sha256', \serialize($import_map));
    self::assertNotFalse($this->runner->useBase($id, static fn() => [$id, ['modules' => [], 'preload' => [], 'importMap' => $import_map]]));
  }

  /**
   * Asserts an invoke fails with a ScriptException.
   *
   * @param string $main_module
   *   The main module's URL.
   * @param string $payload
   *   The payload.
   */
  private function assertScriptFails(string $main_module, string $payload): void {
    try {
      $this->runner->invoke($main_module, $payload);
    }
    catch (ScriptException) {
      $this->addToAssertionCount(1);
      return;
    }
    self::fail('The invoke was expected to fail with a ScriptException.');
  }

  /**
   * Decodes a JSON list of strings a module returned.
   *
   * @param string $json
   *   What invoke() returned.
   *
   * @return array<int, string>
   *   The decoded list.
   */
  private static function decodeList(string $json): array {
    $decoded = \json_decode($json, TRUE, 512, JSON_THROW_ON_ERROR);
    self::assertIsArray($decoded);
    self::assertContainsOnlyString($decoded);

    return \array_values($decoded);
  }

  /**
   * Binds globalThis.probe() to a PHP callback returning a fixed string.
   *
   * The serializer ignores the arguments.
   *
   * @param string $result
   *   What the bound function returns to JS.
   */
  private function bindProbeReturning(string $result): void {
    $this->runner->bindFunction(
      'probe',
      static fn(string $argument): string => $result,
      '() => ""',
      '(raw) => raw',
    );
  }

}
