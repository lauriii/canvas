<?php

declare(strict_types=1);

namespace Drupal\canvas_ssr\JsRunner;

/**
 * Runs JS modules for SSR.
 *
 * A runner drives one way of running JavaScript, like a Deno process. It
 * supplies the web platform APIs modules need before any module evaluates,
 * and never reaches into Drupal itself: bindings carry whatever Drupal
 * access a caller needs.
 *
 * A runner keeps two kinds of state. Its registrations are what it's given:
 * the registered modules, the bindings, and the base in use with its import
 * map.
 * They last until reset(). Its realm is the global object and the modules
 * evaluated in it, which it keeps from one invoke to the next so modules
 * evaluate once and islands can see what earlier ones left. A script
 * failure keeps the realm. A runner must replace it after a runner failure,
 * and may at other times for its own reasons; the registrations outlive it,
 * and modules just evaluate again when imported.
 *
 * Every module is registered under a module URL: the URL the browser loads
 * it from, either a rooted path, starting with "/", or an absolute URL. A
 * "/", "./" or "../" specifier resolves against the importing module's URL
 * as in a browser, and the import map takes every other specifier to one.
 * So a runner can resolve imports with URL rules and the map alone, and
 * modules can be registered in any order. A module's import.meta.resolve()
 * gives what the browser would, though a runner may load it from elsewhere.
 * So does import.meta.url for a module at an absolute URL. For one at a
 * rooted path it's an absolute URL with that path, since a runner can't know
 * the site's origin.
 *
 * The import map comes with the base in use, so the map and the modules a
 * base evaluated can never disagree. With no base in use, the map is empty,
 * as in a browser page without one. Renderer never invokes without a base,
 * but tests may. Imports resolve as in a browser, against the map's
 * "imports": an exact key, else the longest key ending in "/" that starts the
 * specifier. Scopes are ignored. A registered URL is loaded as it is,
 * whatever the map says. An import map entry's URL loads the module
 * registered under it, without its query or fragment:
 * "https://cdn.example.com/x.js?v=1" loads "https://cdn.example.com/x.js". A
 * matching import map entry whose module isn't registered fails the import,
 * as does a blocked one, like a null. import.meta.resolve() returns the URL
 * as it is, query and all. Canvas compiles asset imports to such calls, and
 * the result goes straight into the markup, so the map must give the exact
 * URL the browser would get.
 *
 * @see \Drupal\canvas_ssr\JsRunner\ModuleUrl
 * @see \Drupal\canvas_ssr\Renderer
 * @see \Drupal\canvas_ssr\JsRunner\RunnerGuard
 * @see \Drupal\canvas_ssr\JsRunner\RunnerSelector
 *
 * @phpstan-type Base array{modules: array<string, string>, preload: list<string>, compile?: list<string>, importMap: array<string, mixed>}
 */
interface RunnerInterface {

  /**
   * Checks whether this runner can run on this system.
   *
   * Renderer calls this only when it renders, so a check may touch files.
   * Something that would stop every render on this system, like a directory
   * the runner can't use, belongs here.
   *
   * @return bool
   *   TRUE if usable. FALSE means SSR falls back to CSR.
   */
  public function isAvailable(): bool;

  /**
   * Registers a module's source under a module URL.
   *
   * The registration lasts until reset(), whatever the implementation does
   * to its realm in between, unless a call fails other than with a
   * ScriptException. RunnerGuard relies on this: it forwards each module
   * once and skips repeats, and resets after such a failure.
   *
   * Registering the same source again must do nothing. Registering different
   * source under the same URL must replace it. RunnerConformanceTestBase
   * checks both.
   *
   * @param string $url
   *   The module URL to register under. Relative imports in it resolve
   *   against this.
   * @param string $source
   *   The module's JS source.
   *
   * @see \Drupal\canvas_ssr\JsRunner\RunnerGuard::addModule()
   * @see \Drupal\Tests\canvas_ssr\Unit\JsRunner\RunnerConformanceTestBase
   */
  public function addModule(string $url, string $source): void;

  /**
   * Installs a PHP callback as a global function in the realm.
   *
   * Binding a name that's already bound does nothing, so callers bind before
   * every render. That also restores bindings after a reset().
   *
   * Only strings cross between PHP and JS (see ADR 5). Two self-contained JS
   * function expressions (not modules) convert each way. The serializer gets
   * the call's `this` first, then the call's arguments in order. The
   * serializers are shared by every runner, so the order is part of the
   * contract.
   *
   * The serializer runs on the call's own stack. In what it makes of the
   * call, a stack trace it took names each frame's module by its module
   * URL, as "at <url>:<line>:<column>" or "at <function> (<url>:<line>:
   * <column>)", innermost first, by the time the callback gets it.
   *
   * @param string $name
   *   The global function name to bind, e.g. "fetch".
   * @param callable $callback
   *   PHP callback receiving the serialized argument and returning a string.
   * @param string $serialize_js
   *   JS function expression turning `this` and the call's arguments into
   *   that string.
   * @param string $deserialize_js
   *   JS function expression turning the returned string into the JS result.
   */
  public function bindFunction(
    string $name,
    callable $callback,
    string $serialize_js,
    string $deserialize_js,
  ): void;

  /**
   * Imports a main module and calls its default export with a payload.
   *
   * Importing it loads and evaluates it and whatever it imports that the
   * realm hasn't yet. If the default export returns a promise, the runner
   * must settle it within this call. The framework renderers use
   * renderToStringAsync(), so a component that suspends to fetch data only
   * resolves if the job queue runs to completion. A runner that can't do
   * this must report itself unavailable.
   *
   * The runner also runs the job queue dry between importing the main
   * module and calling its default export. A module evaluates once in a
   * realm, so a promise chain its evaluation started and didn't await
   * would otherwise run during the first call, and not during later ones.
   *
   * Nothing calls the runner again until this returns. RunnerGuard
   * refuses a call from inside a binding's callback, so a runner needn't
   * handle one.
   *
   * A script failure keeps the realm and the registrations, as a throw in
   * a browser keeps its page. After any other failure the runner must
   * replace the realm, and may have dropped the registrations, and
   * RunnerGuard resets.
   *
   * @param string $main_module
   *   The main module's URL. It's registered, or a module of the base in
   *   use.
   * @param string $payload
   *   The payload, as JSON, passed to the main module's default export.
   *
   * @return string
   *   What the default export returned, or its promise settled to, as a
   *   string.
   *
   * @throws \Drupal\canvas_ssr\JsRunner\Exception\ScriptException
   *   A script failure: the JS itself failed, so the same inputs fail the
   *   same way. Every registration stays.
   * @throws \Drupal\canvas_ssr\JsRunner\Exception\TimeLimitException
   *   A runner failure: the invoke went past the runner's time limit.
   * @throws \Drupal\canvas_ssr\JsRunner\Exception\MemoryLimitException
   *   A runner failure: the invoke went past the runner's memory limit.
   * @throws \Throwable
   *   Any other runner failure. After every runner failure, the runner may
   *   have dropped its registrations, so the caller gives them again.
   */
  public function invoke(string $main_module, string $payload): string;

  /**
   * Returns the runner to the state it had when constructed.
   *
   * Every registration goes: modules, bindings, and the base in use with
   * its import map. So does the realm. Bases the runner keeps, and its
   * caches, may stay, so using a base again is cheap. A failed invoke()
   * doesn't need this.
   */
  public function reset(): void;

  /**
   * Makes renders start from the base under an id, under its import map.
   *
   * A base is the modules nearly every render on this site registers, the
   * preload modules that evaluate the libraries among them, the modules
   * only to compile, and the import map in force with them. A runner builds
   * from it once and keeps what it builds, so renders register only what
   * the base lacks.
   *
   * Renderer only renders with a base in use, and falls back to CSR
   * otherwise. So a runner takes any base it's given, unless it can't build
   * from it.
   *
   * A base never changes output. A render registers the modules it uses
   * that the base in use lacks. A URL names one source, so a render never
   * registers other source at a base module's URL, and a runner may answer
   * that URL from the base. So a stale base costs time, not correctness.
   *
   * While a base is in use, its modules count as registered: invoke() can
   * name one of its modules without registering anything, and its
   * import map is in force. It stays in use until another id is used, or
   * reset(). If this returns FALSE, the base and map in force stay as they
   * were.
   *
   * A runner keeps what it built from a base through reset(), so
   * $getDefinition is called at most once per id on a runner. It may keep it
   * across requests too.
   *
   * Call this at the start of every render. RunnerGuard makes repeats
   * cheap.
   *
   * @param string $id
   *   Names the base, its import map included. The same id always means the
   *   same base, so a runner can keep what it built across requests.
   * @param \Closure $getDefinition
   *   Called, before this returns, only if the runner hasn't built from the
   *   base under $id. Returns array{0: string, 1: Base}: the base's id and
   *   the base. The runner uses that id, which differs from $id when the
   *   base changed since $id was read, so a runner never keeps what it built
   *   from one base under another's id. The base is:
   *   - modules: module URL to source. Sources are valid UTF-8.
   *   - preload: the modules to import, in order, to evaluate the libraries
   *     the way renders would, into the realm renders start from. Their
   *     imports resolve through the import map. A preload module that fails
   *     is left out, and fails again in the render that imports it.
   *   - compile: optional, the modules a runner may load and compile, and
   *     check they load, but must not evaluate in the realm renders start
   *     from. A component's module evaluates only in the request that
   *     renders it, and what it does as it evaluates lasts the realm. A
   *     runner that compiles by evaluating, as by importing, does it where
   *     no render sees it.
   *   - importMap: the SSR import map, in the shape Canvas attaches to an
   *     island. A runner keeps it with what it built, since this isn't
   *     called again.
   *   What it throws reaches the caller.
   *
   * @return bool|non-empty-list<string>
   *   TRUE if the base is in use and every preload module, and every module
   *   to compile the runner checked, loaded. FALSE if
   *   the runner couldn't take it, as when building from it takes too long.
   *   Renderer then falls back to CSR. A runner that may take the base later
   *   says so with hasPendingBase(), and buildPendingBase() builds from it
   *   after the response. Then the fallback is cached until the base changes
   *   or Renderer::BASE_PENDING_TAG is invalidated, and otherwise briefly.
   *   Otherwise, the unloadable modules among those: each imports a module
   *   nothing provides, or one that doesn't compile, so invoking it fails in
   *   every realm. Renderer falls back to CSR for those without invoking,
   *   since invoking would only fail. A module that only throws while it
   *   evaluates isn't listed: a runner may build from the base without the
   *   bindings a render has. A runner may list fewer, or none, but only
   *   modules that failed to load. Never an empty list, which would read as
   *   FALSE.
   *
   * @see \Drupal\canvas_ssr\JsModules\BaseModules
   */
  public function useBase(string $id, \Closure $getDefinition): bool|array;

  /**
   * Says whether a base useBase() couldn't take is waiting to be built.
   *
   * It's asked at the end of every request, page cache hits included, so it
   * must be cheap: no more than a file check.
   *
   * @return bool
   *   TRUE if buildPendingBase() may have something to do, now or later.
   */
  public function hasPendingBase(): bool;

  /**
   * Builds from a base useBase() couldn't take, after the response.
   *
   * Called once the response is sent, when hasPendingBase() said so. It may
   * take longer than a render could wait, and may decide it isn't time to
   * try again yet. It doesn't put the base in use.
   *
   * @param string $id
   *   The id of the site's base now, as useBase() takes it.
   * @param \Closure $getDefinition
   *   As useBase() takes it, and called only if the runner tries a build.
   *
   * @return array{since: int, due: int, error: string}|bool|null
   *   TRUE once the runner can take the base under $id, whether this built
   *   it or something else did. The caller then invalidates
   *   Renderer::BASE_PENDING_TAG. If it failed again: since when it's been
   *   failing, when it's tried next, and why, for the status report. NULL
   *   if nothing was tried.
   *
   * @see \Drupal\canvas_ssr\EventSubscriber\PendingBaseSubscriber
   */
  public function buildPendingBase(string $id, \Closure $getDefinition): array|bool|null;

  /**
   * Returns why this runner isn't available.
   *
   * The status report shows it as it is, so it names the runner and says
   * how to fix it, if it can.
   *
   * @return string|null
   *   The reason, once isAvailable() returned FALSE. NULL if it's available,
   *   hasn't been checked, or has nothing to say.
   */
  public function unavailableReason(): ?string;

  /**
   * Returns what this runner can't enforce on this host.
   *
   * The status report shows each as it is, so it names the runner, says
   * what's weaker for it, and how to fix it, if it can. It's asked of an
   * available runner, once per request, so it should be cheap.
   *
   * @return list<string>
   *   Each limitation, as sentences, or none if the runner enforces every
   *   limit it has.
   */
  public function limitations(): array;

}
