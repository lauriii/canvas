<?php

declare(strict_types=1);

namespace Drupal\canvas_ssr\JsRunner\Deno;

use Drupal\canvas_ssr\JsRunner\ModuleUrl;

use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Seeds registered modules into a runner cache or base cache.
 *
 * A Deno process can then import them.
 *
 * Deno has no addModule(), but it reads remote modules from a cache on disk
 * keyed by URL, and with --cached-only never fetches. So each source is
 * seeded into the runner cache, or a base cache, at its cache URL: its
 * module URL as a path on an origin nothing listens on. A rooted path is
 * that path, and an absolute URL goes under ABSOLUTE_PREFIX, scheme first,
 * so "https://cdn.example.com/x.js" is "/_url/https/cdn.example.com/x.js".
 *
 * Sources are seeded unchanged but for a one-line preamble. Relative imports
 * resolve by Deno's own rules to the right cache URL. A rooted import from
 * a module at an absolute URL resolves on the cache origin instead of the
 * module's, and ".." can climb out of its host, which the browser resolves
 * differently; nothing Canvas builds imports that way. Bare specifiers reach
 * Deno through the Deno import map, which this derives from the SSR import
 * map. Nothing here parses imports, and the Deno process needs no
 * permissions. An import of something never seeded fails fast instead of
 * fetching.
 *
 * Deno evaluates each URL once per realm, so islands on a page share
 * evaluated modules. The catch is that changing a module already seeded
 * needs a new Deno process; this reports such rewrites.
 *
 * Modules can also come from a base cache, which has its own origin. The
 * Deno import map sends imports of a base module to the base origin, and
 * the base's own imports resolve there too.
 *
 * It has no Drupal dependencies.
 *
 * @see \Drupal\canvas_ssr\JsRunner\Deno\DenoRunner
 */
final class ModuleSeeder {

  /**
   * The origin seeded modules are addressed under in a runner cache.
   *
   * Nothing listens on port 1, and with --cached-only the Deno process never
   * tries.
   */
  public const ORIGIN = 'http://127.0.0.1:1';

  /**
   * The origin a base cache's modules are addressed under.
   *
   * A separate origin gets its own directory, so a runner cache can link it
   * in with one symlink.
   */
  public const BASE_ORIGIN = 'http://127.0.0.1:2';

  /**
   * Where absolute module URLs go on a cache origin, scheme first.
   *
   * No rooted module URL may start with it.
   */
  public const string ABSOLUTE_PREFIX = '/_url/';

  /**
   * Registered sources, keyed by module URL.
   *
   * @var array<string, string>
   */
  private array $sources = [];

  /**
   * The SSR import map's entries, keyed by specifier.
   *
   * @var array<string, mixed>
   */
  private array $imports = [];

  /**
   * The keys of $imports, most specific first.
   *
   * @var list<string>
   */
  private array $keys = [];

  /**
   * Whether a key of $imports is a URL.
   *
   * If one is, registered URLs affect the Deno import map.
   */
  private bool $hasUrlKeys = FALSE;

  /**
   * The Deno import map, once derived.
   *
   * @var array<string, string|null>|null
   */
  private ?array $denoImportMap = NULL;

  /**
   * Module URLs registered or changed since the last seeding.
   *
   * @var array<string, true>
   */
  private array $pending = [];

  /**
   * What was last seeded under each module URL, keyed by module URL.
   *
   * @var array<string, string>
   */
  private array $seeded = [];

  /**
   * Whether a seeded module has been seeded again since takeRewritten().
   */
  private bool $rewritten = FALSE;

  /**
   * The directory modules are seeded into.
   */
  private readonly string $directory;

  /**
   * Whether the directory and its legacy name have been made.
   */
  private bool $prepared = FALSE;

  /**
   * Constructs a ModuleSeeder.
   *
   * @param string $cacheDirectory
   *   The runner cache, or the base cache being built.
   * @param string $origin
   *   The origin to seed modules under: ORIGIN for renders, BASE_ORIGIN
   *   when building a base cache.
   * @param array<string, true> $base
   *   The module URLs the linked base cache has, as keys. Modules registered
   *   under them aren't seeded.
   */
  public function __construct(
    private readonly string $cacheDirectory,
    private readonly string $origin,
    private readonly array $base = [],
  ) {
    $this->directory = $cacheDirectory . self::originPath($origin);
  }

  /**
   * Returns where Deno caches an origin's modules, within a cache directory.
   *
   * @param string $origin
   *   An http origin with a port.
   *
   * @return string
   *   The path, starting with "/", to add to a runner cache or base cache.
   */
  private static function originPath(string $origin): string {
    return '/remote/http/' . self::originDirectory($origin);
  }

  /**
   * Names the directory Deno caches an origin's modules in.
   *
   * The directory is under remote/http.
   *
   * @param string $origin
   *   An http origin with a port.
   *
   * @return string
   *   HOST_port_N, as Deno names it since 2.9.7.
   *
   * @see self::linkLegacyOriginDirectory()
   */
  public static function originDirectory(string $origin): string {
    return \sprintf('%s_port_%d', ...self::hostAndPort($origin));
  }

  /**
   * Links one cache directory's modules for an origin into another's.
   *
   * @param string $from
   *   The cache directory that has the modules, such as a base cache.
   * @param string $to
   *   The cache directory to link them into, which has nothing under the
   *   origin yet.
   * @param string $origin
   *   The origin.
   *
   * @throws \RuntimeException
   *   When the link can't be made.
   */
  public static function linkOrigin(string $from, string $to, string $origin): void {
    $path = self::originPath($origin);
    if (!@\mkdir(\dirname($to . $path), 0777, TRUE) || !@\symlink($from . $path, $to . $path)) {
      throw new \RuntimeException(\sprintf('Failed to link "%s" into "%s".', $from . $path, $to));
    }
    self::linkLegacyOriginDirectory($to, $origin);
  }

  /**
   * Checks an origin linked by linkOrigin() still leads somewhere.
   *
   * @param string $cache_directory
   *   The cache directory the origin may be linked into.
   * @param string $origin
   *   The origin.
   *
   * @return bool
   *   FALSE if the link is there but what it points to was deleted.
   */
  public static function originLinkIntact(string $cache_directory, string $origin): bool {
    $link = $cache_directory . self::originPath($origin);

    return !\is_link($link) || \is_dir($link);
  }

  /**
   * Adds the name Deno before 2.9.7 looks for an origin's modules under.
   *
   * Deno 2.9.7 renamed HOST_PORTn to HOST_port_n. Older releases are past
   * their end of life, but the old name costs one symlink, to the new one
   * beside it. Failing to add it fails only those releases, so it's ignored.
   *
   * @param string $cache_directory
   *   A runner cache or base cache.
   * @param string $origin
   *   The origin whose directory it names.
   */
  private static function linkLegacyOriginDirectory(string $cache_directory, string $origin): void {
    $legacy = \dirname($cache_directory . self::originPath($origin)) . '/' . \sprintf('%s_PORT%d', ...self::hostAndPort($origin));
    if (!\is_link($legacy)) {
      @\symlink(self::originDirectory($origin), $legacy);
    }
  }

  /**
   * Splits an origin into the host and port its directory is named for.
   *
   * @param string $origin
   *   An http origin.
   *
   * @return array{0: string, 1: int}
   *   The host, and the port, which defaults to 80.
   */
  private static function hostAndPort(string $origin): array {
    $parts = \parse_url($origin);

    return [
      \is_array($parts) && \is_string($parts['host'] ?? NULL) ? $parts['host'] : '127.0.0.1',
      \is_array($parts) && \is_int($parts['port'] ?? NULL) ? $parts['port'] : 80,
    ];
  }

  /**
   * Returns the cache URL of a module URL on a cache origin.
   *
   * @param string $origin
   *   The cache origin.
   * @param string $url
   *   The module URL: a rooted path or an absolute URL.
   *
   * @return string
   *   The cache URL, escaped the way Deno escapes it, so it names the same
   *   file.
   */
  public static function url(string $origin, string $url): string {
    $path = \preg_replace('#^([a-zA-Z][a-zA-Z\d+\-.]*)://#', self::ABSOLUTE_PREFIX . '$1/', $url, 1) ?? $url;
    // What the URL spec escapes in a path, plus "?", "#" and "\".
    return $origin . \preg_replace_callback(
      '/[\x00-\x20"#<>?`{}\\\\\x7F-\xFF]/',
      static fn (array $match): string => \sprintf('%%%02X', \ord($match[0])),
      $path,
    );
  }

  /**
   * Registers a module's source, replacing any previous one.
   *
   * @param string $url
   *   The module URL it's registered under.
   * @param string $source
   *   Its source.
   *
   * @throws \InvalidArgumentException
   *   When $url isn't a module URL, or is a rooted path under
   *   ABSOLUTE_PREFIX.
   */
  public function setSource(string $url, string $source): void {
    // Most calls are repeats. Comparing the strings is cheaper than hashing,
    // and the same string compares without reading it.
    if (($this->sources[$url] ?? NULL) === $source) {
      return;
    }
    if (!ModuleUrl::is($url) || \str_starts_with($url, self::ABSOLUTE_PREFIX)) {
      throw new \InvalidArgumentException(\sprintf(
        'Deno registers modules under rooted paths and absolute URLs only, and none under "%s", so "%s" cannot be registered.',
        self::ABSOLUTE_PREFIX,
        $url,
      ));
    }
    // A URL key can't take over a registered URL, so the Deno import map
    // may need an import map entry for it. So may an absolute URL, which
    // an import may name as it is.
    if (($this->hasUrlKeys || !\str_starts_with($url, '/')) && !isset($this->sources[$url])) {
      $this->denoImportMap = NULL;
    }
    $this->sources[$url] = $source;
    $this->pending[$url] = TRUE;
  }

  /**
   * Sets the SSR import map the Deno import map is derived from.
   *
   * @param array<string, mixed> $imports
   *   The SSR import map's entries, keyed by specifier.
   *
   * @see \Drupal\canvas_ssr\JsRunner\RunnerInterface::useBase()
   */
  public function setSsrImports(array $imports): void {
    if ($imports === $this->imports) {
      return;
    }
    $this->imports = $imports;
    $this->keys = \array_values(\array_filter(
      \array_map('strval', \array_keys($imports)),
      static fn (string $key): bool => $key !== '',
    ));
    // Descending order puts the longest matching prefix first.
    \rsort($this->keys, SORT_STRING);
    $this->hasUrlKeys = \array_filter(
      $this->keys,
      static fn (string $key): bool => ModuleUrl::is($key),
    ) !== [];
    $this->denoImportMap = NULL;
  }

  /**
   * Seeds whatever is pending and returns a main module's cache URL.
   *
   * @param string $main_module
   *   The main module's URL.
   *
   * @return string
   *   The cache URL for the Deno process to import.
   *
   * @throws \RuntimeException
   *   When the URL is neither registered nor in the base cache, or the
   *   cache cannot be written.
   */
  public function mainModuleUrl(string $main_module): string {
    if (!isset($this->sources[$main_module]) && !isset($this->base[$main_module])) {
      throw new \RuntimeException(\sprintf('No module is registered under "%s".', $main_module));
    }
    $this->seed();

    return $this->locate($main_module);
  }

  /**
   * Returns the Deno import map.
   *
   * It's what a Deno process needs to import these modules.
   *
   * It loads what the SSR import map does, as RunnerInterface describes.
   * Each import map entry points at the cache URL of the module its URL
   * names, so a change to a URL's query alone leaves it the same. Import
   * maps don't chain, so a prefix entry gets an exact entry for each base
   * module under it, and a base or registered module a URL key covers gets
   * one too. So does each absolute module URL, which an import may name as
   * it is.
   *
   * @return array<string, string|null>
   *   The map's imports, sorted by key so equal maps compare equal. A null
   *   blocks a specifier.
   */
  public function denoImportMap(): array {
    if ($this->denoImportMap !== NULL) {
      return $this->denoImportMap;
    }
    $imports = [];
    foreach ($this->keys as $key) {
      $mapped = self::urlFor($key, $this->imports[$key]);
      if ($mapped === NULL) {
        $this->mapTo($imports, $key, NULL);
      }
      elseif (!\str_ends_with($key, '/')) {
        $this->mapTo($imports, $key, $this->locate($mapped));
      }
      else {
        $this->mapTo($imports, $key, self::url($this->origin, $mapped));
        foreach ($this->base as $url => $_) {
          $url = (string) $url;
          $specifier = $key . \substr($url, \strlen($mapped));
          if (\str_starts_with($url, $mapped) && $this->matchingKey($specifier) === $key) {
            $this->mapTo($imports, $specifier, self::url(self::BASE_ORIGIN, $url));
          }
        }
      }
    }
    // Registered modules load as they are, whatever a URL key says.
    if ($this->hasUrlKeys) {
      foreach ($this->sources as $url => $_) {
        $url = (string) $url;
        if (!isset($this->base[$url]) && $this->matchingKey($url) !== NULL) {
          $this->mapTo($imports, $url, self::url($this->origin, $url));
        }
      }
    }
    // An import may name an absolute module URL as it is.
    foreach ([$this->sources, $this->base] as $modules) {
      foreach ($modules as $url => $_) {
        $url = (string) $url;
        if (!\str_starts_with($url, '/')) {
          $imports[$url] = $this->locate($url);
        }
      }
    }
    // Send imports of base modules to the base origin. Last, so they win.
    if ($this->origin !== self::BASE_ORIGIN) {
      foreach ($this->base as $url => $_) {
        $imports[self::url($this->origin, (string) $url)] = self::url(self::BASE_ORIGIN, (string) $url);
      }
    }
    \ksort($imports, SORT_STRING);

    return $this->denoImportMap = $imports;
  }

  /**
   * Returns the module URL an import map entry loads.
   *
   * @param string $key
   *   The import map entry's key.
   * @param mixed $url
   *   The import map entry's URL.
   *
   * @return string|null
   *   The module URL, ending in "/" for a prefix entry, or NULL if the entry
   *   is blocked.
   */
  private static function urlFor(string $key, mixed $url): ?string {
    if (!\is_string($url) || (\str_ends_with($key, '/') && !\str_ends_with($url, '/'))) {
      return NULL;
    }
    $url = \preg_replace('/[?#].*$/s', '', $url) ?? $url;

    // Anything else names no module, so it fails like a blocked entry.
    return ModuleUrl::is($url) ? $url : NULL;
  }

  /**
   * Returns the key of the SSR import map that matches a specifier, if any.
   *
   * @param string $specifier
   *   The specifier.
   *
   * @return string|null
   *   The exact key, else the longest prefix key that starts it.
   */
  private function matchingKey(string $specifier): ?string {
    foreach ($this->keys as $key) {
      if ($key === $specifier || (\str_ends_with($key, '/') && \str_starts_with($specifier, $key))) {
        return $key;
      }
    }

    return NULL;
  }

  /**
   * Adds an import map entry to the Deno import map.
   *
   * It goes under each key Deno may look a specifier up by.
   *
   * @param array<string, string|null> $imports
   *   The Deno import map's entries.
   * @param string $specifier
   *   A specifier, as imports name it.
   * @param string|null $target
   *   The cache URL, or NULL to block it.
   */
  private function mapTo(array &$imports, string $specifier, ?string $target): void {
    if (!ModuleUrl::is($specifier)) {
      $imports[$specifier] = $target;
      return;
    }
    // Deno resolves a module's relative imports to cache URLs, so a URL is
    // looked up by its cache URL. An absolute one may also be imported as it
    // is.
    $imports[self::url($this->origin, $specifier)] = $target;
    if (!\str_starts_with($specifier, '/')) {
      $imports[$specifier] = $target;
    }
  }

  /**
   * Returns whether a seeded module was seeded again since the last call.
   *
   * If so, the realm may have the old version, since Deno evaluates each
   * URL once per realm.
   *
   * @return bool
   *   TRUE when a module was rewritten.
   */
  public function takeRewritten(): bool {
    $rewritten = $this->rewritten;
    $this->rewritten = FALSE;

    return $rewritten;
  }

  /**
   * Returns every module URL this cache has seeded.
   *
   * A base cache's manifest lists these.
   *
   * @return list<string>
   *   The module URLs.
   */
  public function seededUrls(): array {
    return \array_map('strval', \array_keys($this->seeded));
  }

  /**
   * Replaces cache URLs with module URLs, in results and stack traces.
   *
   * @param string $text
   *   Whatever the Deno process returned or threw.
   *
   * @return string
   *   The same text, with cache URLs replaced by module URLs.
   */
  public function restoreUrls(string $text): string {
    return self::restoreUrlsOf($this->origin, $text);
  }

  /**
   * Replaces the cache URLs of an origin, and the base's, with module URLs.
   *
   * @param string $origin
   *   The origin: ORIGIN for renders.
   * @param string $text
   *   Whatever the Deno process returned, threw or passed to a binding.
   *
   * @return string
   *   The same text, with cache URLs replaced by module URLs.
   */
  public static function restoreUrlsOf(string $origin, string $text): string {
    $origins = \preg_quote($origin, '#') . '|' . \preg_quote(self::BASE_ORIGIN, '#');
    $text = \preg_replace(
      '#(?:' . $origins . ')' . \preg_quote(self::ABSOLUTE_PREFIX, '#') . '([a-zA-Z][a-zA-Z\d+\-.]*)/#',
      '$1://',
      $text,
    ) ?? $text;

    return \strtr($text, [
      $origin . '/' => '/',
      self::BASE_ORIGIN . '/' => '/',
    ]);
  }

  /**
   * Replaces cache URLs with module URLs in the frames of a stack trace.
   *
   * Only where a frame names its module: after "at", and a function name
   * and bracket if there is one, at the start of a line. Cache URLs
   * elsewhere in the text are left, since they aren't the runner's naming
   * of a module but something a component wrote, such as a URL to fetch.
   *
   * @param string $origin
   *   The origin: ORIGIN for renders.
   * @param string $text
   *   Text that may hold a stack trace, as it is or as a JSON string.
   *
   * @return string
   *   The same text, with its frames' cache URLs replaced by module URLs.
   */
  public static function restoreFrameUrls(string $origin, string $text): string {
    $origins = \preg_quote($origin, '#') . '|' . \preg_quote(self::BASE_ORIGIN, '#');
    // A line starts after a newline, or after its escape in a JSON string.
    return \preg_replace_callback(
      '#((?:\n|\\\\n)[ \t]*at (?:[^\n\\\\]*? \()?)((?:' . $origins . ')/[^\s)"\\\\]*)#',
      static fn (array $matches): string => $matches[1] . self::restoreUrlsOf($origin, $matches[2]),
      $text,
    ) ?? $text;
  }

  /**
   * Returns a module URL's cache URL, on the base origin or this cache's.
   *
   * @param string $url
   *   The module URL.
   *
   * @return string
   *   Its cache URL.
   */
  private function locate(string $url): string {
    return self::url(isset($this->base[$url]) ? self::BASE_ORIGIN : $this->origin, $url);
  }

  /**
   * Returns the preamble for a module that mentions import.meta.
   *
   * Deno's import.meta.resolve() uses the Deno import map, not the SSR
   * import map, so this swaps in runner.js's resolver, bound to the module
   * URL. A module at an absolute URL also gets that URL as import.meta.url,
   * so new URL(..., import.meta.url) gives the browser's answer. One at a
   * rooted path keeps its cache URL, which is absolute, as a base URL must
   * be; restoreUrls() turns it back into the path in output. It has no
   * newline, so line numbers don't change.
   *
   * @param string $url
   *   The module URL.
   *
   * @return string
   *   The preamble.
   */
  private static function preamble(string $url): string {
    $quoted = \json_encode($url, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $resolve = 'import.meta.resolve = (s) => globalThis.__canvasSsrResolveImportMeta(s, ' . $quoted . ');';

    return \str_starts_with($url, '/') ? $resolve : 'import.meta.url = ' . $quoted . '; ' . $resolve;
  }

  /**
   * Makes the directory modules are seeded into, under both of its names.
   *
   * Seed() calls this. Building a base cache calls it directly, so the
   * directory a runner cache links to exists even if nothing was seeded.
   *
   * @throws \RuntimeException
   *   When the directory cannot be created.
   */
  public function prepare(): void {
    if ($this->prepared) {
      return;
    }
    if (!\is_dir($this->directory) && !@\mkdir($this->directory, 0777, TRUE) && !\is_dir($this->directory)) {
      throw new \RuntimeException(\sprintf(
        'Failed to create the Deno module cache directory "%s". Set the runner\'s runner caches root to a directory the web server may write.',
        $this->directory,
      ));
    }
    self::linkLegacyOriginDirectory($this->cacheDirectory, $this->origin);
    $this->prepared = TRUE;
  }

  /**
   * Seeds every pending module that the cache doesn't already have.
   *
   * Deno stores a cached response's metadata in a comment at the end of the
   * file, so each module gets one. mainModuleUrl() calls this. Building a
   * base cache calls it directly, to seed modules no preload module
   * imports.
   *
   * @throws \RuntimeException
   *   When the cache directory cannot be created or written.
   */
  public function seed(): void {
    if ($this->pending === []) {
      return;
    }
    $this->prepare();

    foreach ($this->pending as $module_url => $_) {
      $module_url = (string) $module_url;
      unset($this->pending[$module_url]);
      $source = $this->sources[$module_url];
      // The import map sends base modules to the base cache, so skip them.
      if (isset($this->base[$module_url]) || ($this->seeded[$module_url] ?? NULL) === $source) {
        continue;
      }
      $url = self::url($this->origin, $module_url);
      $metadata = \json_encode([
        'headers' => [
          'cache-control' => 'public, max-age=31536000, immutable',
          'content-type' => 'text/javascript',
        ],
        'url' => $url,
        'time' => \time(),
      ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
      $body = \str_contains($source, 'import.meta') ? self::preamble($module_url) . $source : $source;

      // Deno names each file for the sha256 of its cache URL's path.
      // dumpFile() writes atomically, so a crash can't leave half a module.
      $file = $this->directory . '/' . \hash('sha256', \substr($url, \strlen($this->origin)));
      try {
        (new Filesystem())->dumpFile($file, $body . "\n// denoCacheMetadata=" . $metadata);
      }
      catch (IOException $e) {
        throw new \RuntimeException(\sprintf(
          'Failed to seed the module registered under "%s" into the Deno module cache at "%s".',
          $module_url,
          $this->directory,
        ), 0, $e);
      }
      if (isset($this->seeded[$module_url])) {
        $this->rewritten = TRUE;
      }
      $this->seeded[$module_url] = $source;
    }
  }

}
