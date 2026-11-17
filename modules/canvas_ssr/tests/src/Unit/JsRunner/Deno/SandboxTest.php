<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_ssr\Unit\JsRunner\Deno;

// cspell:ignore queuing

use Drupal\canvas_ssr\JsRunner\Deno\DenoProcess;
use Drupal\canvas_ssr\JsRunner\Deno\DenoRunner;
use Drupal\canvas_ssr\JsRunner\Deno\ModuleSeeder;
use Drupal\Tests\canvas_ssr\Traits\WarmsUpDenoTrait;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Large;

/**
 * Tests component code cannot read the filesystem or reach the network.
 *
 * SSR runs editor-written code on the web server. Each case is a component
 * trying something a browser wouldn't allow, through the same interface a
 * render uses. Each must fail.
 *
 * The tests check the secret didn't come back, not for a particular error,
 * since different layers refuse in different ways.
 *
 * @see docs/adr/0009-deno-runs-with-no-permissions.md
 */
#[CoversClass(DenoRunner::class)]
#[CoversClass(DenoProcess::class)]
#[Large]
#[Group('canvas_ssr')]
final class SandboxTest extends UnitTestCase {

  use DenoBinaryTrait;
  use WarmsUpDenoTrait;

  /**
   * A file every Linux and macOS system has, which no component should read.
   */
  private const SECRET_FILE = '/etc/passwd';

  /**
   * Globals Deno defines that runner.js deletes, besides WebGPU's GPU*.
   *
   * They're host APIs, which components don't get.
   */
  private const HOST_GLOBALS = [
    'AbortController', 'AbortSignal', 'Blob', 'BroadcastChannel', 'Buffer',
    'ByteLengthQueuingStrategy', 'Cache', 'CacheStorage', 'CloseEvent',
    'CompressionStream', 'CountQueuingStrategy', 'Crypto', 'CryptoKey',
    'CustomEvent', 'DOMMatrix', 'DOMMatrixReadOnly', 'DOMPoint',
    'DOMPointReadOnly', 'DOMQuad', 'DOMRect', 'DOMRectReadOnly',
    'DecompressionStream', 'Deno', 'ErrorEvent', 'Event', 'EventSource',
    'EventTarget', 'File', 'FileReader', 'FormData', 'ImageBitmap',
    'ImageBitmapRenderingContext', 'ImageData', 'Location', 'Lock',
    'LockManager', 'MessageChannel', 'MessageEvent', 'MessagePort', 'Navigator',
    'NavigatorUAData', 'OffscreenCanvas', 'Performance', 'PerformanceEntry',
    'PerformanceMark', 'PerformanceMeasure', 'PerformanceObserver',
    'PerformanceObserverEntryList', 'ProgressEvent', 'PromiseRejectionEvent',
    'QuotaExceededError', 'ReadableByteStreamController', 'ReadableStream',
    'ReadableStreamBYOBReader', 'ReadableStreamBYOBRequest',
    'ReadableStreamDefaultController', 'ReadableStreamDefaultReader', 'Request',
    'Response', 'Storage', 'SubtleCrypto', 'TextDecoderStream',
    'TextEncoderStream', 'TransformStream', 'TransformStreamDefaultController',
    'URLPattern', 'WebAssembly', 'WebSocket', 'Window', 'Worker',
    'WritableStream', 'WritableStreamDefaultController',
    'WritableStreamDefaultWriter', 'alert', 'caches', 'clearImmediate',
    'clearInterval', 'clearTimeout', 'close', 'closed', 'confirm',
    'createImageBitmap', 'crypto', 'fetch', 'global', 'localStorage',
    'location', 'name', 'navigator', 'onbeforeunload', 'onerror', 'onload',
    'onunhandledrejection', 'onunload', 'process', 'prompt', 'reportError',
    'self', 'sessionStorage', 'setImmediate', 'setInterval', 'setTimeout',
    'structuredClone',
  ];

  /**
   * ECMAScript globals runner.js deletes.
   *
   * Browsers withhold SharedArrayBuffer. FinalizationRegistry's callbacks
   * would run in whatever island the collector frees an object in.
   */
  private const WITHHELD_GLOBALS = ['SharedArrayBuffer', 'FinalizationRegistry'];

  /**
   * The runner under test.
   */
  private DenoRunner $runner;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->runner = new DenoRunner(self::denoBinary());
    if (!$this->runner->isAvailable()) {
      self::markTestSkipped('Deno reports itself unavailable in this environment.');
    }
    if (!\is_readable(self::SECRET_FILE)) {
      self::markTestSkipped(\sprintf(
        '%s is not readable by this process, so a component failing to read it proves nothing.',
        self::SECRET_FILE,
      ));
    }
  }

  /**
   * Returns components that try to escape the sandbox.
   *
   * @return array<string, array{string}>
   *   The module source to register, keyed by what it attempts.
   */
  public static function escapes(): array {
    $secret = \json_encode(self::SECRET_FILE, JSON_THROW_ON_ERROR);
    $file_url = \json_encode('file://' . self::SECRET_FILE, JSON_THROW_ON_ERROR);

    return [
      'a static import of a file URL' => [
        \sprintf('import secret from %s; export default () => secret;', $file_url),
      ],
      'a re-export from a file URL' => [
        \sprintf(
          'export * from %s; import * as s from %s; export default () => JSON.stringify(s);',
          $file_url,
          $file_url,
        ),
      ],
      'a dynamic import of a file URL' => [
        \sprintf('export default async () => JSON.stringify(await import(%s));', $file_url),
      ],
      'a dynamic import built at run time, out of any static check\'s sight' => [
        \sprintf(
          'export default async () => JSON.stringify(await import(["file:/", "", %s].join("") ));',
          \json_encode(\ltrim(self::SECRET_FILE, '/'), JSON_THROW_ON_ERROR),
        ),
      ],
      'a node:fs read' => [
        \sprintf(
          'export default async () => (await import("node:fs")).readFileSync(%s, "utf8");',
          $secret,
        ),
      ],
      "Deno's own filesystem API" => [
        \sprintf('export default async () => await Deno.readTextFile(%s);', $secret),
      ],
      'a fetch of a file URL' => [
        \sprintf('export default async () => await (await fetch(%s)).text();', $file_url),
      ],
      'a fetch of a remote URL' => [
        'export default async () => await (await fetch("https://example.com/")).text();',
      ],
      'a static import of a remote module' => [
        'import x from "https://example.com/x.js"; export default () => x;',
      ],
      'an npm: specifier' => [
        'import fs from "npm:fs-extra"; export default () => String(fs);',
      ],
      'a relative path out of the module graph' => [
        'import x from "../../../../etc/passwd"; export default () => x;',
      ],
      'an import of a cache URL nothing was seeded under' => [
        \sprintf(
          'export default async () => JSON.stringify(await import(["%s", "/etc/passwd"].join("")));',
          ModuleSeeder::ORIGIN,
        ),
      ],
      'a fetch of the runner caches\' origin' => [
        \sprintf(
          'export default async () => await (await fetch("%s/")).text();',
          ModuleSeeder::ORIGIN,
        ),
      ],
    ];
  }

  /**
   * Tests an escape attempt gets nothing out.
   *
   * @param string $source
   *   The component module's source.
   */
  #[DataProvider('escapes')]
  public function testComponentCannotEscapeTheSandbox(string $source): void {
    $this->runner->addModule('/component.js', $source);

    $secret = \file_get_contents(self::SECRET_FILE);
    self::assertIsString($secret);

    try {
      $rendered = $this->runner->invoke('/component.js', '""');
    }
    catch (\Throwable $failure) {
      // Most cases end here, and the renderer falls back to CSR. The error
      // message mustn't hold the secret either.
      self::assertStringNotContainsString(
        \substr($secret, 0, 32),
        $failure->getMessage(),
      );
      return;
    }

    self::assertStringNotContainsString(\substr($secret, 0, 32), $rendered);
  }

  /**
   * Tests a component sees no Deno namespace, while loading or rendering.
   *
   * It's a server-only API, which a browser doesn't offer.
   */
  public function testDenoNamespaceIsAbsent(): void {
    $this->runner->addModule(
      '/component.js',
      'const loading = typeof Deno; export default () => [loading, typeof Deno, "Deno" in globalThis].join(" ");',
    );

    self::assertSame('undefined undefined false', $this->runner->invoke('/component.js', '""'));
  }

  /**
   * Tests a component can't unhook the runner's handlers.
   *
   * Deno keeps the global object's listeners under a symbol-keyed global
   * any component can read, and exits on an unhandled rejection or an
   * uncaught error unless a listener stops it. So a component that dropped
   * one of runner.js's listeners would end the process at a later island's,
   * and one that added its own would see later islands'. This fails too if
   * a new Deno keeps its listeners elsewhere, where runner.js can't lock
   * them.
   */
  public function testComponentCannotUnhookTheRejectionHandler(): void {
    $this->runner->addModule('/tamper.js', <<<'JS'
      const attempts = {
        'replace the listeners': (data) => { data.listeners = {}; },
        'delete the entry': (data) => { delete data.listeners.unhandledrejection; },
        'empty the entry': (data) => { data.listeners.unhandledrejection.length = 0; },
        'make it once': (data) => { data.listeners.unhandledrejection[0].options.once = true; },
        'swap its callback': (data) => { data.listeners.unhandledrejection[0].callback = () => {}; },
        'stop new entries': (data) => { Object.preventExtensions(data.listeners); },
        'give it a prototype': (data) => { Object.setPrototypeOf(data.listeners, null); },
        'add a listener': () => { globalThis.addEventListener('unhandledrejection', () => {}); },
        'delete the error entry': (data) => { delete data.listeners.error; },
        'empty the error entry': (data) => { data.listeners.error.length = 0; },
        'make the error entry once': (data) => { data.listeners.error[0].options.once = true; },
        'swap the error callback': (data) => { data.listeners.error[0].callback = () => {}; },
        'add an error listener': () => { globalThis.addEventListener('error', () => {}); },
      };
      export default () => {
        const data = Object.getOwnPropertySymbols(globalThis)
          .map((key) => globalThis[key])
          .find((value) => typeof value === 'object' && value !== null && 'listeners' in value);
        if (data === undefined) return 'no listeners found';
        const worked = [];
        for (const [name, attempt] of Object.entries(attempts)) {
          try {
            attempt(data);
            if (Object.isExtensible(data.listeners) && data.listeners.unhandledrejection?.length === 1 && data.listeners.error?.length === 1) continue;
            worked.push(name);
          }
          catch {}
        }
        return worked.join(', ') || 'none worked';
      };
      JS);
    $this->runner->addModule('/rejects.js', 'export default () => { Promise.reject(new Error("unhandled")); queueMicrotask(() => { throw new Error("uncaught"); }); return "rejected"; };');
    $this->runner->addModule('/later.js', 'export default () => "rendered";');

    self::assertSame('none worked', $this->runner->invoke('/tamper.js', '""'));
    self::assertSame('rejected', $this->runner->invoke('/rejects.js', '""'));
    self::assertSame('rendered', $this->runner->invoke('/later.js', '""'));
  }

  /**
   * Tests every node: builtin the Deno in use has is blocked.
   *
   * An import map can't block a whole scheme, so the list is explicit. A new
   * Deno could add a module; this asks the binary and names what's missing.
   *
   * @see \Drupal\canvas_ssr\JsRunner\Deno\DenoProcess::NODE_BUILTINS
   */
  public function testEveryNodeBuiltinIsBlocked(): void {
    $binary = self::denoBinary();
    $output = [];
    $status = 1;
    \exec(\sprintf(
      '%s eval --quiet %s 2>&1',
      \escapeshellarg($binary),
      \escapeshellarg('import { builtinModules } from "node:module"; console.log(JSON.stringify(builtinModules));'),
    ), $output, $status);
    self::assertSame(0, $status, \implode("\n", $output));
    $builtins = \json_decode(\implode('', $output), TRUE, 512, JSON_THROW_ON_ERROR);
    $builtins = \array_map(static fn(string $name): string => (string) \preg_replace('/^node:/', '', $name), $builtins);

    self::assertSame([], \array_values(\array_diff($builtins, DenoProcess::NODE_BUILTINS)));
  }

  /**
   * Tests importing runner.js, or anything beside it, gets nothing.
   *
   * Without permissions, Deno still lets code import what's already
   * loaded, which includes runner.js and anything it imports. So it's one
   * file that imports and exports nothing, and a component can't reach the
   * protocol.
   */
  public function testImportingTheRunnerScriptGetsNothing(): void {
    $directory = \dirname(__DIR__, 5) . '/src/JsRunner/Deno/js';
    $scripts = self::paths($directory . '/*.js');
    self::assertSame([$directory . '/runner.js'], $scripts);
    $source = \file_get_contents($scripts[0]);
    self::assertIsString($source);
    self::assertDoesNotMatchRegularExpression('/^\s*(import|export)\b/m', $source);

    $url = \json_encode('file://' . \realpath($scripts[0]), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    $this->runner->addModule('/component.js', <<<JS
      export default async () => {
        try {
          return Object.keys(await import($url)).join(',') || 'nothing';
        } catch {
          return 'refused';
        }
      };
      JS);

    self::assertContains($this->runner->invoke('/component.js', '""'), ['nothing', 'refused']);
  }

  /**
   * Tests every global Deno defines is either supported or known.
   *
   * Runner.js deletes every global it doesn't list, so a global a new Deno
   * adds is deleted too. That's right for a host API, but wrong for a new
   * ECMAScript global. So this asks the binary, and names what runner.js
   * neither keeps nor HOST_GLOBALS or WITHHELD_GLOBALS lists. Add each
   * to runner.js's lists or to HOST_GLOBALS.
   */
  public function testEveryGlobalDenoDefinesIsClassified(): void {
    $output = [];
    $status = 1;
    \exec(\sprintf(
      '%s eval --quiet %s 2>&1',
      \escapeshellarg(self::denoBinary()),
      \escapeshellarg('console.log(JSON.stringify(Object.getOwnPropertyNames(globalThis)));'),
    ), $output, $status);
    self::assertSame(0, $status, \implode("\n", $output));
    $defined = \json_decode(\implode('', $output), TRUE, 512, JSON_THROW_ON_ERROR);

    $this->runner->addModule('/component.js', 'export default () => JSON.stringify(Object.getOwnPropertyNames(globalThis));');
    $kept = \json_decode($this->runner->invoke('/component.js', '""'), TRUE, 512, JSON_THROW_ON_ERROR);

    $unknown = \array_filter(
      \array_diff($defined, $kept, self::HOST_GLOBALS, self::WITHHELD_GLOBALS),
      static fn(string $name): bool => !\str_starts_with($name, 'GPU'),
    );
    self::assertSame([], \array_values($unknown));
  }

  /**
   * Tests storage fails even if a component could reach it.
   *
   * Runner.js deletes localStorage and the Cache API, which Deno keeps on
   * disk in the DENO_DIR, where a component could otherwise fill the disk.
   * As a second layer, DenoProcess puts a file where Deno makes its storage
   * directory. This runs a plain script, which still has both APIs, against
   * a DENO_DIR holding that file. It fails if a new Deno stores elsewhere.
   *
   * @see \Drupal\canvas_ssr\JsRunner\Deno\DenoProcess::STORAGE_DIRECTORY
   */
  public function testStorageFailsInADenoDirWithTheBlocker(): void {
    $deno_dir = \sys_get_temp_dir() . '/canvas-ssr-storage-' . \bin2hex(\random_bytes(8));
    \mkdir($deno_dir, 0700);
    try {
      $blocker = (new \ReflectionClassConstant(DenoProcess::class, 'STORAGE_DIRECTORY'))->getValue();
      \file_put_contents($deno_dir . '/' . $blocker, '');
      $script = <<<'JS'
        const results = [];
        try {
          localStorage.setItem('k', 'v');
          results.push('localStorage stored');
        }
        catch {
          results.push('localStorage failed');
        }
        try {
          const cache = await caches.open('c');
          await cache.put('https://example.com/', new Response('v'));
          results.push('caches stored');
        }
        catch {
          results.push('caches failed');
        }
        console.log(results.join(', '));
        JS;
      $output = [];
      $status = 1;
      \exec(\sprintf(
        'DENO_DIR=%s %s eval --quiet %s 2>&1',
        \escapeshellarg($deno_dir),
        \escapeshellarg(self::denoBinary()),
        \escapeshellarg($script),
      ), $output, $status);

      self::assertSame(0, $status, \implode("\n", $output));
      self::assertSame(['localStorage failed, caches failed'], $output);
    }
    finally {
      \exec('rm -rf ' . \escapeshellarg($deno_dir));
    }
  }

}
