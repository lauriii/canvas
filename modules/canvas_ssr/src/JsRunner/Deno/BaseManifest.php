<?php

declare(strict_types=1);

namespace Drupal\canvas_ssr\JsRunner\Deno;

/**
 * What a base cache holds.
 *
 * That's its module URLs, the import map's entries it was built under, and
 * the preload modules that couldn't load when it was built.
 *
 * It's written last when a base cache is built, so a base cache that has
 * one is complete.
 *
 * @see \Drupal\canvas_ssr\JsRunner\Deno\BaseCaches
 */
final class BaseManifest {

  /**
   * The manifest's file name in a base cache.
   */
  public const string FILE = 'manifest.json';

  /**
   * Constructs a BaseManifest.
   *
   * @param array<string, true> $urls
   *   The module URLs the base cache has, as keys.
   * @param list<string> $unloadable
   *   The preload modules whose imports couldn't load.
   * @param array<string, mixed> $imports
   *   The base's import map entries, which are in force while it's in use.
   */
  public function __construct(
    public readonly array $urls,
    public readonly array $unloadable,
    public readonly array $imports,
  ) {}

  /**
   * Returns the manifest of no base cache.
   */
  public static function none(): self {
    return new self([], [], []);
  }

  /**
   * Reads a base cache's manifest.
   *
   * @param string $base_cache
   *   The base cache.
   *
   * @return self|null
   *   The manifest, or NULL when the base cache has none it can read: it's
   *   incomplete, or gone.
   */
  public static function read(string $base_cache): ?self {
    $json = @\file_get_contents($base_cache . '/' . self::FILE);
    $manifest = \is_string($json) ? \json_decode($json, TRUE) : NULL;
    if (!\is_array($manifest) || !\is_array($manifest['urls'] ?? NULL) || !\is_array($manifest['imports'] ?? NULL)) {
      return NULL;
    }
    $unloadable = $manifest['unloadable'] ?? [];

    return new self(
      \array_fill_keys(\array_filter($manifest['urls'], 'is_string'), TRUE),
      \is_array($unloadable) ? \array_values(\array_filter($unloadable, 'is_string')) : [],
      $manifest['imports'],
    );
  }

  /**
   * Returns the manifest as the JSON its file holds.
   */
  public function toJson(): string {
    return \json_encode([
      'urls' => \array_keys($this->urls),
      'unloadable' => $this->unloadable,
      // An object even when empty, as an import map's entries are.
      'imports' => (object) $this->imports,
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
  }

}
