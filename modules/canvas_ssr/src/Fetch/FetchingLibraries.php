<?php

declare(strict_types=1);

namespace Drupal\canvas_ssr\Fetch;

use Drupal\canvas_ssr\JsModules\AllProvidedModules;
use Drupal\canvas_ssr\JsModules\SsrImportMap;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\DestructableInterface;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;

/**
 * The libraries that fetch for the components that import them.
 *
 * Such a library may call fetch() after the component that asked it to has
 * returned, or after awaiting something. No component's code is on the call
 * stack then, though a component gets what was fetched, and may keep it.
 * The library's own code is, so the fetch counts for the components that
 * import one of these.
 *
 * A component may import one through other modules the SSR import map
 * names, as a Canvas CLI codebase's own "@/lib" modules are once pushed.
 * So those count as importing it too.
 *
 * @see \Drupal\canvas_ssr\Renderer::recordFetch()
 */
final class FetchingLibraries implements CacheTagsInvalidatorInterface, DestructableInterface {

  /**
   * The specifiers of the libraries, and what an importer's source mentions.
   *
   * A library that exports more than what fetches counts only for a
   * module whose source mentions the export that does. NULL means any
   * module that imports it.
   */
  private const array SPECIFIERS = [
    'swr' => NULL,
    '@drupal-api-client/json-api-client' => NULL,
    // It exports JsonApiClient beside what most components import.
    'drupal-canvas' => 'JsonApiClient',
    // Its hook gives the client the framework renderer made for the island.
    'drupal-canvas/react' => 'useJsonApiClient',
  ];

  /**
   * Whether each provided module imports one of the libraries, by module URL.
   *
   * Worked out for every provided module at once and cached, since it
   * reads their sources, which a render otherwise doesn't.
   *
   * @var array<string, bool>|null
   */
  private ?array $reaches = NULL;

  /**
   * The SSR import map $reaches was worked out for.
   *
   * @var array<string, mixed>|null
   */
  private ?array $reachesFor = NULL;

  /**
   * The cache tags of what $reaches was worked out from.
   *
   * @var list<string>
   */
  private array $reachesTags = [];

  public function __construct(
    private readonly SsrImportMap $ssrImportMap,
    private readonly AllProvidedModules $providedModules,
    private readonly CacheBackendInterface $cache,
  ) {}

  /**
   * Returns the module URLs of the libraries.
   *
   * @return list<string>
   *   What the SSR import map resolves each one's specifier to.
   */
  public function moduleUrls(): array {
    $urls = [];
    foreach (\array_keys(self::SPECIFIERS) as $specifier) {
      $url = $this->ssrImportMap->resolve($specifier);
      if ($url !== NULL) {
        $urls[] = $url;
      }
    }

    return $urls;
  }

  /**
   * Tells whether a component may fetch through one of the libraries.
   *
   * @param list<string> $specifiers
   *   The specifiers the component imports.
   * @param string $source
   *   The component's source.
   *
   * @return bool
   *   TRUE if it imports one, itself or through modules the SSR import map
   *   names, and mentions the export that fetches if only one does. Whether
   *   the mention is a use of it isn't checked, so this errs towards TRUE.
   */
  public function mayFetchFor(array $specifiers, string $source): bool {
    if (self::imports($specifiers, $source)) {
      return TRUE;
    }
    $reaches = $this->reaches();
    foreach ($specifiers as $specifier) {
      $url = $this->ssrImportMap->resolve($specifier);
      if ($url !== NULL && ($reaches[$url] ?? FALSE)) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Returns whether each provided module imports one of the libraries.
   *
   * @return array<string, bool>
   *   Whether it does, by module URL.
   */
  private function reaches(): array {
    $import_map = $this->ssrImportMap->get();
    if ($this->reaches !== NULL && $import_map === $this->reachesFor) {
      return $this->reaches;
    }
    $this->reachesFor = $import_map;
    $cid = 'canvas_ssr:fetching_libraries:' . \hash('xxh128', \serialize($import_map));
    $cached = $this->cache->get($cid);
    if ($cached !== FALSE && \is_array($cached->data)) {
      $this->reachesTags = \array_values($cached->tags ?? []);
      return $this->reaches = $cached->data;
    }
    $this->reachesTags = Cache::mergeTags(
      $this->ssrImportMap->getCacheTags(),
      $this->providedModules->getCacheability()->getCacheTags(),
    );
    $this->reaches = self::reachTable($this->providedModules->sources(), $this->ssrImportMap->resolve(...));
    $this->cache->set($cid, $this->reaches, Cache::PERMANENT, $this->reachesTags);

    return $this->reaches;
  }

  /**
   * {@inheritdoc}
   *
   * Forgets the table when what it was worked out from changes, as when a
   * request pushes and then renders. The import map may stay the same.
   */
  public function invalidateTags(array $tags): void {
    if (\array_intersect($tags, $this->reachesTags) !== []) {
      $this->destruct();
    }
  }

  /**
   * {@inheritdoc}
   *
   * Forgets the table, at the end of the request, for a PHP process that
   * keeps its services.
   */
  public function destruct(): void {
    $this->reaches = NULL;
    $this->reachesFor = NULL;
    $this->reachesTags = [];
  }

  /**
   * Tells whether a module imports one of the libraries itself.
   *
   * @param list<string> $specifiers
   *   The specifiers the module imports.
   * @param string $source
   *   The module's source.
   *
   * @return bool
   *   TRUE if it does, and mentions the export that fetches if only one
   *   does.
   */
  public static function imports(array $specifiers, string $source): bool {
    foreach ($specifiers as $specifier) {
      if (!\array_key_exists($specifier, self::SPECIFIERS)) {
        continue;
      }
      $export = self::SPECIFIERS[$specifier];
      if ($export === NULL || \str_contains($source, $export)) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Works out which modules import one of the libraries.
   *
   * @param array<string, string> $sources
   *   The modules' sources, by module URL.
   * @param callable(string): ?string $resolve
   *   Resolves a bare specifier to a module URL.
   *
   * @return array<string, bool>
   *   Whether each does, itself or through the modules it imports.
   */
  public static function reachTable(array $sources, callable $resolve): array {
    // A library that exports more than what fetches may import what does,
    // as drupal-canvas imports the JSON:API client. It counts only for a
    // module that mentions the export, so it doesn't reach the client for
    // its importers.
    $exports_more = [];
    foreach (self::SPECIFIERS as $specifier => $export) {
      $url = $export === NULL ? NULL : $resolve($specifier);
      if ($url !== NULL) {
        $exports_more[$url] = TRUE;
      }
    }
    $reaches = [];
    $imported_by = [];
    foreach ($sources as $url => $source) {
      $url = (string) $url;
      if (isset($exports_more[$url])) {
        $reaches[$url] = FALSE;
        continue;
      }
      // Anything shaped like an import, since a wrong match only errs
      // towards TRUE, and a library's source may be too large to parse.
      // Only import() takes a template literal, so text in one, like "from
      // `drupal-canvas/react`" in a message, isn't taken for an import.
      \preg_match_all('~\b(?:from|import)\s*(?:(["\'])([^"\'\n]+)\1|\(\s*(?:/\*.*?\*/\s*)?(["\'`])([^"\'`\n]+)\3)~s', $source, $matches);
      $specifiers = \array_values(\array_unique(\array_filter([...$matches[2], ...$matches[4]], static fn (string $specifier): bool => $specifier !== '')));
      $reaches[$url] = self::imports($specifiers, $source) || self::reExportsAll($source);
      foreach ($specifiers as $specifier) {
        $imported = \str_starts_with($specifier, '.') ? self::relative($specifier, $url) : $resolve($specifier);
        if ($imported !== NULL) {
          $imported_by[$imported][] = $url;
        }
      }
    }
    // Whatever imports a module that does, does too. Working back from
    // those that do reaches every importer, whatever cycles they're in.
    $pending = \array_keys(\array_filter($reaches));
    while ($pending !== []) {
      foreach ($imported_by[\array_pop($pending)] ?? [] as $importer) {
        if (!$reaches[$importer]) {
          $reaches[$importer] = TRUE;
          $pending[] = $importer;
        }
      }
    }

    return $reaches;
  }

  /**
   * Tells whether a module re-exports all of a library that exports more.
   *
   * Its importers then get the export that fetches without the module
   * mentioning it.
   *
   * @param string $source
   *   The module's source.
   *
   * @return bool
   *   TRUE if it does.
   */
  private static function reExportsAll(string $source): bool {
    foreach (self::SPECIFIERS as $specifier => $export) {
      if ($export !== NULL && \preg_match('~\bexport\s*\*[^;"\'`]*from\s*["\'`]' . \preg_quote($specifier, '~') . '["\'`]~', $source) === 1) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Resolves a relative specifier against the module that imports it.
   *
   * @param string $specifier
   *   The specifier.
   * @param string $url
   *   The importing module's URL.
   *
   * @return string|null
   *   The module URL, or NULL if the specifier isn't a usable address.
   */
  private static function relative(string $specifier, string $url): ?string {
    try {
      return SsrImportMap::moduleUrl((string) UriResolver::resolve(new Uri($url), new Uri($specifier)));
    }
    catch (\InvalidArgumentException) {
      return NULL;
    }
  }

}
