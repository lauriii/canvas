<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_ssr\Kernel;

use Drupal\canvas_ssr\JsModules\ProvidedModule;
use Drupal\canvas_ssr\JsModules\ProvidedModules;
use Drupal\canvas_ssr\JsModules\Provider\ModuleProviderInterface;
use Drupal\Core\Cache\CacheableMetadata;

/**
 * Provides a library built as a bundler builds one, with a chunk.
 *
 * Its library imports a class from a chunk, which no import map entry
 * names, adds to the class's prototype, and re-exports it. Another library
 * uses a class from its own chunk, which it doesn't re-export. A third
 * hands out instances of a class it doesn't export.
 *
 * It also provides modules for components to import during a render:
 * three that read drupalSettings at module scope, and some that await at the
 * top level, after calling the global __ssrTestStarted(), if a render set
 * it.
 * And four libraries that, as they evaluate, add a global, or a hook to
 * console or to preact's options.
 */
final class ChunkedLibraryProvider implements ModuleProviderInterface {

  /**
   * The specifier the library is imported by.
   */
  public const string SPECIFIER = '@canvas-ssr-test/chunked';

  /**
   * The specifier the other library is imported by.
   */
  public const string OTHER_SPECIFIER = '@canvas-ssr-test/other';

  /**
   * The specifier the library that hands out instances is imported by.
   */
  public const string MAKER_SPECIFIER = '@canvas-ssr-test/maker';

  /**
   * {@inheritdoc}
   */
  public function getModules(): ProvidedModules {
    $modules = [
      '/_canvas_ssr/test/chunked/entry.js' => [
        <<<'JS'
        import { Thing } from './chunk.js';
        Thing.prototype.added = 'added';
        export { Thing };
        JS,
        [self::SPECIFIER],
      ],
      '/_canvas_ssr/test/chunked/chunk.js' => ['export class Thing {}', []],
      '/_canvas_ssr/test/other/entry.js' => [
        <<<'JS'
        import { Hidden } from './hidden.js';
        export const other = new Hidden().name;
        JS,
        [self::OTHER_SPECIFIER],
      ],
      '/_canvas_ssr/test/other/hidden.js' => ['export class Hidden { get name() { return "added"; } }', []],
      '/_canvas_ssr/test/maker/entry.js' => [
        'class Made { label() { return "made"; } } export function make() { return new Made(); }',
        [self::MAKER_SPECIFIER],
      ],
      '/_canvas_ssr/test/settings/site-reader.js' => [
        'export const read = `${typeof drupalSettings.canvasData.v0.baseUrl}:${"pageTitle" in drupalSettings.canvasData.v0}`;',
        ['@canvas-ssr-test/site-reader'],
      ],
      '/_canvas_ssr/test/settings/page-reader.js' => [
        'export const read = drupalSettings.canvasData.v0.pageTitle;',
        ['@canvas-ssr-test/page-reader'],
      ],
      '/_canvas_ssr/test/settings/translations-reader.js' => [
        'export const read = typeof drupalSettings.canvasData.v0.translations;',
        ['@canvas-ssr-test/translations-reader'],
      ],
    ];
    // A library whose chunk a component reaches before any island has
    // imported the library.
    $modules['/_canvas_ssr/test/reached/entry.js'] = [
      'import { Part } from "./part.js"; export const reached = new Part().name;',
      ['@canvas-ssr-test/reached'],
    ];
    $modules['/_canvas_ssr/test/reached/part.js'] = ['export class Part { get name() { return "part"; } }', []];
    // A library that adds to what its chunk exports, for a
    // component to import by a computed specifier.
    $modules['/_canvas_ssr/test/adder/entry.js'] = [
      'import { Base } from "./base.js"; Base.prototype.added = "added"; export { Base };',
      ['@canvas-ssr-test/adder'],
    ];
    $modules['/_canvas_ssr/test/adder/base.js'] = ['export class Base {}', []];
    // Libraries that change what islands share as they evaluate, as a
    // library may and a component's module may not.
    $modules['/_canvas_ssr/test/shared/registry.js'] = [
      'globalThis.__ssrTestRegistry ??= { count: 0 }; export const loaded = true;',
      ['@canvas-ssr-test/registry'],
    ];
    $modules['/_canvas_ssr/test/shared/config.js'] = [
      'globalThis.__ssrTestConfig = { theme: "light" }; export const loaded = true;',
      ['@canvas-ssr-test/config'],
    ];
    $modules['/_canvas_ssr/test/shared/console.js'] = [
      'console.__ssrTestKept = () => "kept"; export const loaded = true;',
      ['@canvas-ssr-test/console'],
    ];
    $modules['/_canvas_ssr/test/shared/options-hook.js'] = [
      <<<'JS'
      import { options } from "preact";
      const previous = options.vnode;
      options.vnode = (vnode) => {
        if (vnode.props?.["data-ssr-test-hook"] === "") {
          vnode.props["data-ssr-test-hook"] = "hooked";
        }
        previous?.(vnode);
      };
      export const loaded = true;
      JS,
      ['@canvas-ssr-test/options-hook'],
    ];
    // Libraries a component imports only while its module awaits, so they
    // evaluate between its first line and its last.
    $modules['/_canvas_ssr/test/late/global.js'] = [
      'globalThis.__ssrTestLate = "global"; export const kept = () => globalThis.__ssrTestLate;',
      ['@canvas-ssr-test/late-global'],
    ];
    $modules['/_canvas_ssr/test/late/console.js'] = [
      'console.__ssrTestLate = () => "console"; export const kept = () => console.__ssrTestLate();',
      ['@canvas-ssr-test/late-console'],
    ];
    $modules['/_canvas_ssr/test/late/options.js'] = [
      'import { options } from "preact"; options.__ssrTestLate = () => "options"; export const kept = () => options.__ssrTestLate();',
      ['@canvas-ssr-test/late-options'],
    ];
    $modules['/_canvas_ssr/test/late/plain.js'] = [
      'export const kept = () => typeof console.__ssrTestEarly;',
      ['@canvas-ssr-test/late-plain'],
    ];
    // The same, for a component to import by a computed specifier, so they
    // evaluate among its modules and not before them.
    $modules['/_canvas_ssr/test/computed/global.js'] = [
      'globalThis.__ssrTestComputed = "global"; export const kept = () => globalThis.__ssrTestComputed;',
      ['@canvas-ssr-test/computed-global'],
    ];
    $modules['/_canvas_ssr/test/computed/plain.js'] = [
      'export const kept = () => typeof console.__ssrTestEarly;',
      ['@canvas-ssr-test/computed-plain'],
    ];
    // A library that changes what islands share, then awaits, and one that
    // evaluates meanwhile when a module imports both.
    $modules['/_canvas_ssr/test/awaits/changer.js'] = [
      'globalThis.__ssrTestAwaits = 1; console.__ssrTestAwaits = 1; await null; await null; const read = () => `${typeof globalThis.__ssrTestAwaits}:${typeof console.__ssrTestAwaits}`; export const after = read(); export const kept = read;',
      ['@canvas-ssr-test/awaits-changer'],
    ];
    $modules['/_canvas_ssr/test/awaits/sibling.js'] = [
      'export const sibling = true;',
      ['@canvas-ssr-test/awaits-sibling'],
    ];
    // A library nothing else imports, so the first island that imports it
    // has its libraries evaluated.
    $modules['/_canvas_ssr/test/once.js'] = [
      'export const once = true;',
      ['@canvas-ssr-test/once'],
    ];
    // A library that throws as it evaluates.
    $modules['/_canvas_ssr/test/throws.js'] = [
      'throw new Error("Thrown while evaluating."); export const unreachable = true;',
      ['@canvas-ssr-test/throws'],
    ];
    // Libraries that leave something running that changes them.
    $modules['/_canvas_ssr/test/later/library.js'] = [
      'Promise.resolve().then(() => { globalThis.__ssrTestLibraryLater = 1; }); export const kept = () => typeof globalThis.__ssrTestLibraryLater;',
      ['@canvas-ssr-test/library-later'],
    ];
    $modules['/_canvas_ssr/test/later/library-b.js'] = [
      'Promise.resolve().then(() => { globalThis.__ssrTestLibraryLaterB = 1; }); export const kept = () => typeof globalThis.__ssrTestLibraryLaterB;',
      ['@canvas-ssr-test/library-later-b'],
    ];
    // A library that calls back what it was given when another says so,
    // and one that says so and then awaits.
    $modules['/_canvas_ssr/test/signal.js'] = [
      'let callback = null; export function onStarted(given) { callback = given; } export function started() { callback?.(); }',
      ['@canvas-ssr-test/signal'],
    ];
    $modules['/_canvas_ssr/test/slow/slow-d.js'] = [
      'import { started } from "@canvas-ssr-test/signal"; started(); for (let i = 0; i < 1000; i++) { await null; } export const done = true;',
      ['@canvas-ssr-test/slow-d'],
    ];
    // A library that leaves something running that changes its own state.
    $modules['/_canvas_ssr/test/settles.js'] = [
      'let current = "pending"; (async () => { for (let i = 0; i < 1000; i++) await null; current = "done"; })(); export const state = () => current;',
      ['@canvas-ssr-test/settles'],
    ];
    // A library for a component to name in an import() it never calls.
    $modules['/_canvas_ssr/test/unreached.js'] = [
      'globalThis.__ssrTestUnreached = 1; export const loaded = true;',
      ['@canvas-ssr-test/unreached'],
    ];
    foreach (['slow-a', 'slow-b', 'slow-c', 'slow-e'] as $name) {
      $modules["/_canvas_ssr/test/slow/$name.js"] = [
        'globalThis.__ssrTestStarted?.(); for (let i = 0; i < 1000; i++) { await null; } export const done = true;',
        ["@canvas-ssr-test/$name"],
      ];
    }
    $provided = [];
    foreach ($modules as $url => [$source, $specifiers]) {
      $provided[] = new ProvidedModule(url: $url, read: static fn(): string => $source, specifiers: $specifiers);
    }

    return new ProvidedModules($provided, new CacheableMetadata());
  }

}
