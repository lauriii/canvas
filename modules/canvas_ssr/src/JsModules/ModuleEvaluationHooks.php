<?php

declare(strict_types=1);

namespace Drupal\canvas_ssr\JsModules;

use Drupal\canvas_ssr\JsModules\Provider\SsrOnlyModuleProvider;
use Psr\Log\LoggerInterface;

/**
 * Adds realm setup's hooks around every module's evaluation.
 *
 * They deep-freeze the module's exports, and keep what a library changed.
 *
 * Islands share a realm, so a component that mutated a library's exports,
 * like a method on an exported class's prototype, would change every
 * island rendered after it. The appended lines run once the module has
 * evaluated, and pass its namespace to the realm setup module's
 * freezeExports(). That first tames the module's configured properties, as
 * docs/CONFIGURATION.md documents.
 *
 * A chunk, a provided module that no import map entry names, only hands
 * its exports over to be frozen by the next library that evaluates: the
 * one, usually, that imports it and re-exports them. So that library may
 * still mutate them, and its properties to tame are found through it. A
 * chunk that evaluates once a component's code may have run is frozen
 * before any component gets it, without waiting for a library.
 *
 * preact core's exports are only marked, and the framework renderer freezes
 * them once preact/compat has added to them. Its `options` is never frozen:
 * preact/hooks, preact/compat and the renderer set it, and
 * preact-render-to-string sets it for every render.
 *
 * The same lines tell the realm setup module when the module has
 * evaluated. What a library did to the globals, console or preact's
 * options by then is kept from island to island, while what a render does
 * to them is undone. Only a library may: a component's module throws if it
 * changed them as it evaluated, and the change is undone. In the browser,
 * a component's module evaluates only on a page it's on, but what it did
 * here would last for every island after. A statement before the module's
 * own code, on its first line so line numbers don't change, says it's
 * about to evaluate, and whether it's a component's.
 *
 * Every module canvas_ssr registers goes through this, in the base and in
 * a render alike, so a module's source is the same in both.
 *
 * @see js/realm-setup.js
 */
final class ModuleEvaluationHooks {

  /**
   * Modules whose freezing is deferred, by specifier.
   *
   * Each has the exports never to freeze.
   */
  private const array DEFERRED = ['preact' => ['options']];

  /**
   * What a component's module calls with each import() it makes.
   *
   * The appended lines import it from the realm setup module, for the
   * calls that ComponentModules wraps.
   *
   * @see \Drupal\canvas_ssr\ComponentInfo\ComponentModules::rewrite()
   */
  public const string TRACK_IMPORT = '__canvasSsrTrackImport';

  /**
   * Constructs a ModuleEvaluationHooks.
   *
   * @param \Drupal\canvas_ssr\JsModules\TamedProperties $tamedProperties
   *   The properties to tame.
   * @param \Psr\Log\LoggerInterface $logger
   *   Where module entries the import map lacks are reported.
   */
  public function __construct(
    private readonly TamedProperties $tamedProperties,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Appends the freezing lines to modules.
   *
   * @param array<string, string> $sources
   *   Sources, by module URL.
   * @param array<mixed> $import_map
   *   The SSR import map, which module entries resolve through.
   * @param bool $provided
   *   Whether these are provided modules. One the import map doesn't name
   *   is a chunk.
   * @param bool $component
   *   Whether these are components' modules, which may not change the
   *   globals, console or preact's options as they evaluate. The modules
   *   canvas_ssr writes itself are neither, and need no check.
   *
   * @return array<string, string>
   *   The sources, each with its lines appended. The realm setup module's
   *   is unchanged, since it does the freezing.
   */
  public function apply(array $sources, array $import_map, bool $provided = FALSE, bool $component = FALSE): array {
    $imports = \is_array($import_map['imports'] ?? NULL) ? $import_map['imports'] : [];
    $named = self::named($imports);
    $tamed = [];
    foreach ($this->tamedProperties->modules() as $specifier => $paths) {
      $url = SsrImportMap::resolveIn($imports, $specifier);
      if ($url === NULL) {
        $this->logger->notice('The tamed property entries for module {specifier} are skipped, since the SSR import map has no such module.', ['specifier' => $specifier]);
        continue;
      }
      // Several specifiers can name one module.
      foreach ($paths as $path => $properties) {
        $tamed[$url][$path] = \array_values(\array_unique([...$tamed[$url][$path] ?? [], ...$properties]));
      }
    }
    $deferred = [];
    foreach (self::DEFERRED as $specifier => $exempt) {
      $url = SsrImportMap::resolveIn($imports, $specifier);
      if ($url !== NULL) {
        $deferred[$url] = $exempt;
      }
    }

    foreach ($sources as $url => $source) {
      if ($url === SsrOnlyModuleProvider::REALM_SETUP_PATH) {
        continue;
      }
      $chunk = $provided && !self::isNamed($url, $named);
      $sources[$url] = self::withFirstLine($source, $component) . ($chunk ? self::chunkLines($url) : self::lines($url, $tamed[$url] ?? [], $deferred[$url] ?? NULL, $component));
    }

    return $sources;
  }

  /**
   * Returns the module URLs an import map's entries give.
   *
   * @param array<mixed> $imports
   *   The import map's imports.
   *
   * @return list<string>
   *   The module URLs, a prefix entry's ending in "/".
   */
  private static function named(array $imports): array {
    $named = [];
    foreach ($imports as $address) {
      if (\is_string($address)) {
        $named[] = SsrImportMap::moduleUrl($address);
      }
    }

    return $named;
  }

  /**
   * Puts the statement that says a module is evaluating before its code.
   *
   * It goes after a hashbang, which must come first.
   *
   * @param string $source
   *   The source.
   * @param bool $component
   *   Whether it's a component's module, which may not change globals,
   *   console or preact's options as it evaluates.
   *
   * @return string
   *   The source with the statement, and no more lines.
   */
  private static function withFirstLine(string $source, bool $component): string {
    $statement = 'const __canvasSsrEvaluation = __canvasSsrBeginEvaluation(' . ($component ? 'true' : '') . ');';
    if (\str_starts_with($source, '#!')) {
      $end = \strpos($source, "\n");
      return $end === FALSE ? $source . "\n" . $statement : \substr($source, 0, $end + 1) . $statement . \substr($source, $end + 1);
    }

    return $statement . $source;
  }

  /**
   * Returns the lines to append to a module.
   *
   * @param string $url
   *   The module URL.
   * @param array<string, list<string>> $tamed
   *   Its properties to tame.
   * @param list<string>|null $defer_exempting
   *   For a module whose freezing is deferred, its exports never to freeze.
   * @param bool $component
   *   Whether it's a component's module, whose import() calls are tracked.
   *
   * @return string
   *   The lines, starting on a new line.
   */
  private static function lines(string $url, array $tamed, ?array $defer_exempting, bool $component): string {
    return \sprintf(
      "\nimport { beginEvaluation as __canvasSsrBeginEvaluation, endEvaluation as __canvasSsrEndEvaluation, freezeExports as __canvasSsrFreezeExports%s } from %s;\nimport * as __canvasSsrExports from %s;\n__canvasSsrEndEvaluation(__canvasSsrEvaluation);\n__canvasSsrFreezeExports(__canvasSsrExports, %s, %s);\n",
      $component ? ', trackImport as ' . self::TRACK_IMPORT : '',
      self::json(SsrOnlyModuleProvider::REALM_SETUP_SPECIFIER),
      self::json(self::selfSpecifier($url)),
      self::json((object) $tamed),
      self::json($defer_exempting),
    );
  }

  /**
   * Returns the lines to append to a chunk.
   *
   * @param string $url
   *   The module URL.
   *
   * @return string
   *   The lines, starting on a new line.
   */
  private static function chunkLines(string $url): string {
    return \sprintf(
      "\nimport { beginEvaluation as __canvasSsrBeginEvaluation, endEvaluation as __canvasSsrEndEvaluation, holdExports as __canvasSsrHoldExports } from %s;\nimport * as __canvasSsrExports from %s;\n__canvasSsrEndEvaluation(__canvasSsrEvaluation);\n__canvasSsrHoldExports(__canvasSsrExports);\n",
      self::json(SsrOnlyModuleProvider::REALM_SETUP_SPECIFIER),
      self::json(self::selfSpecifier($url)),
    );
  }

  /**
   * Returns the specifier a module imports itself by: its file name.
   *
   * It names the module whatever query string the import that reached it
   * had.
   *
   * @param string $url
   *   The module URL.
   *
   * @return string
   *   The specifier.
   */
  private static function selfSpecifier(string $url): string {
    return './' . \substr($url, \strrpos($url, '/') + 1);
  }

  /**
   * Returns whether an import map entry names a module.
   *
   * @param string $url
   *   The module URL.
   * @param list<string> $named
   *   The module URLs the import map's entries give, a prefix entry's
   *   ending in "/".
   *
   * @return bool
   *   Whether one names it.
   */
  private static function isNamed(string $url, array $named): bool {
    foreach ($named as $address) {
      if ($address === $url || (\str_ends_with($address, '/') && \str_starts_with($url, $address))) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Encodes a value as JSON for JS source.
   *
   * @param mixed $value
   *   The value.
   *
   * @return string
   *   The JSON.
   */
  private static function json(mixed $value): string {
    return \json_encode($value, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
  }

}
