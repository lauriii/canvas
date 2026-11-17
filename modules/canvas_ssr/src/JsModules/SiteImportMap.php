<?php

declare(strict_types=1);

namespace Drupal\canvas_ssr\JsModules;

use Drupal\canvas\Entity\AssetLibrary;
use Drupal\canvas\Render\ImportMapResponseAttachmentsProcessor;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheableDependencyInterface;
use Drupal\Core\Cache\CacheTagsChecksumInterface;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;

/**
 * The site import map: Canvas's global import map, which every island ships.
 *
 * Canvas gives each island the global map plus scopes for its components.
 * Only the global part matters to SSR, and it's the same for every island,
 * so it's read once per request. AstroIslandSsr leaves an island whose map
 * differs to the browser. SsrImportMap builds the SSR import map from this
 * one.
 *
 * The map is built from the global asset library, which a push can change
 * while a render is running. So the library's cache tag is read before the
 * map. The checksum service keeps what it read for the request, and stamps
 * every later cache write with it. Anything built from this map and cached
 * then goes stale at once if the push landed in between, instead of
 * outliving it.
 *
 * @see \Drupal\canvas\GlobalImports::getImportMap()
 * @see \Drupal\canvas_ssr\Element\AstroIslandSsr
 * @see \Drupal\canvas_ssr\JsModules\SsrImportMap
 */
final class SiteImportMap implements CacheableDependencyInterface, CacheTagsInvalidatorInterface {

  /**
   * The global asset library's cache tag.
   */
  public const string ASSET_LIBRARY_TAG = 'config:canvas.asset_library.' . AssetLibrary::GLOBAL_ID;

  /**
   * The map, once read.
   *
   * @var array<string, mixed>|null
   */
  private ?array $importMap = NULL;

  /**
   * Constructs a SiteImportMap.
   *
   * @param \Closure(): \Drupal\canvas\GlobalImports $globalImports
   *   Returns Canvas's GlobalImports. A closure, since every cache tag
   *   invalidation builds this service.
   * @param \Drupal\Core\Cache\CacheTagsChecksumInterface $checksum
   *   The cache tags checksum service.
   */
  public function __construct(
    private readonly \Closure $globalImports,
    private readonly CacheTagsChecksumInterface $checksum,
  ) {}

  /**
   * Returns the map.
   *
   * It's the same array until the asset library's tag is invalidated, so
   * callers can tell it hasn't changed with a cheap ===.
   *
   * @return array<string, mixed>
   *   The map, in the shape Canvas attaches to an island.
   */
  public function get(): array {
    if ($this->importMap !== NULL) {
      return $this->importMap;
    }
    // Before the map, so cache writes can't carry a newer checksum than
    // the map they're built from.
    $this->checksum->getCurrentChecksum($this->getCacheTags());

    return $this->importMap = ($this->globalImports)()->getImportMap();
  }

  /**
   * Returns the map's imports, the import map entries outside any scope.
   *
   * @return array<string, mixed>
   *   URLs keyed by specifier.
   */
  public function imports(): array {
    $imports = $this->get()[ImportMapResponseAttachmentsProcessor::GLOBAL_IMPORTS] ?? [];

    return \is_array($imports) ? $imports : [];
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheTags(): array {
    return [self::ASSET_LIBRARY_TAG];
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheContexts(): array {
    // Its URLs start with the base path.
    return ['url.site'];
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheMaxAge(): int {
    return Cache::PERMANENT;
  }

  /**
   * Drops the map when the asset library changes in this request.
   *
   * @param string[] $tags
   *   The cache tags being invalidated.
   */
  public function invalidateTags(array $tags): void {
    if (\in_array(self::ASSET_LIBRARY_TAG, $tags, TRUE)) {
      $this->importMap = NULL;
    }
  }

}
