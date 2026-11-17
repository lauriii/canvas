<?php

declare(strict_types=1);

namespace Drupal\canvas_ssr\JsParser;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\DestructableInterface;
use Drupal\Core\Lock\LockBackendInterface;

/**
 * Keeps parse results between requests, failures included.
 *
 * It's one cache item for all of them, read once and written once at the
 * end of a request that changed it. Results are keyed by a hash of the
 * source, so they're never stale and need no cache tags.
 *
 * At most MAX_ITEMS are kept, and the least recently used is evicted
 * first. To spare a write whenever one is found, recency is counted in
 * generations, not requests. The generation goes up with each eviction,
 * and finding an item only marks it used if it was last used in an
 * earlier generation. So until the cache is full, finding an item writes
 * nothing, and after that, at most once per item per eviction.
 *
 * Concurrent requests merge rather than overwrite, and the generation
 * never falls.
 *
 * @see \Drupal\canvas_ssr\JsParser\JsModuleParser
 */
final class ParsedModuleCache implements DestructableInterface {

  /**
   * The cache id. Bump the version whenever what's stored changes.
   */
  private const string CID = 'canvas_ssr.parsed_modules:3';

  /**
   * The most results kept.
   */
  public const int MAX_ITEMS = 100;

  /**
   * Whether the cache item has been read this request.
   */
  private bool $loaded = FALSE;

  /**
   * The generation, as of this request.
   */
  private int $generation = 0;

  /**
   * The results, keyed by source hash.
   *
   * @var array<string, array{imports: list<array{0: string, 1: int, 2: int}>|null, directives: array<int, string>, dynamic: list<array{0: int, 1: int}>, used: int}>
   */
  private array $items = [];

  /**
   * The hashes of results this request stored or marked used, as keys.
   *
   * @var array<string, true>
   */
  private array $changed = [];

  /**
   * Constructs a ParsedModuleCache.
   *
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache
   *   The cache. It should outlive cache clears, as the file parsing bin
   *   does, since what's in it can't go stale.
   * @param \Drupal\Core\Lock\LockBackendInterface $lock
   *   Locks the write at the end of the request.
   */
  public function __construct(
    private readonly CacheBackendInterface $cache,
    private readonly LockBackendInterface $lock,
  ) {}

  /**
   * Finds a source's parse result.
   *
   * @param string $hash
   *   The source's sha256 hash.
   *
   * @return \Drupal\canvas_ssr\JsParser\ParsedModule|false|null
   *   The parsed module, FALSE if the source is known not to parse, or NULL
   *   if nothing is kept for it.
   */
  public function lookup(string $hash): ParsedModule|false|null {
    $this->load();
    if (!isset($this->items[$hash])) {
      return NULL;
    }
    $item = &$this->items[$hash];
    if ($item['used'] < $this->generation) {
      $item['used'] = $this->generation;
      $this->changed[$hash] = TRUE;
    }

    return $item['imports'] === NULL ? FALSE : new ParsedModule($item['imports'], $item['directives'], $item['dynamic']);
  }

  /**
   * Keeps a source's parse result.
   *
   * @param string $hash
   *   The source's sha256 hash.
   * @param \Drupal\canvas_ssr\JsParser\ParsedModule|null $module
   *   The parsed module, or NULL if the source failed to parse.
   */
  public function store(string $hash, ?ParsedModule $module): void {
    $this->load();
    $this->items[$hash] = [
      'imports' => $module?->getImports(),
      'directives' => $module?->getDirectives() ?? [],
      'dynamic' => $module?->getDynamicImports() ?? [],
      'used' => $this->generation,
    ];
    $this->changed[$hash] = TRUE;
  }

  /**
   * Writes what this request changed, at the end of the request.
   *
   * It merges with what other requests wrote meanwhile, even when the item
   * was created or cleared since this request read it. Every result is
   * right for its hash, so merging can't make the cache wrong.
   */
  public function destruct(): void {
    $changed = \array_intersect_key($this->items, $this->changed);
    if ($changed === []) {
      return;
    }
    $lock_name = self::CID . ':' . self::class;
    if (!$this->lock->acquire($lock_name)) {
      // Another request is writing. What this one found or parsed will be
      // found or parsed again.
      return;
    }

    $cached = $this->cache->get(self::CID);
    [$generation, $items] = self::unpack($cached !== FALSE ? $cached->data : NULL);
    $generation = \max($generation, $this->generation);
    foreach ($changed as $hash => $item) {
      // It's used now, even if this request read an earlier generation.
      $item['used'] = $generation;
      unset($items[$hash]);
      $items[$hash] = $item;
    }
    // Least recently used first, and on a tie, the one written longest ago.
    // The sort is stable, and what this request changed is written last.
    \uasort($items, static fn(array $a, array $b): int => $a['used'] <=> $b['used']);
    if (\count($items) > self::MAX_ITEMS) {
      $items = \array_slice($items, -self::MAX_ITEMS, NULL, TRUE);
      $generation++;
      // What this request changed it just used, so it starts the new
      // generation as used.
      foreach (\array_intersect_key($items, $changed) as $hash => $item) {
        $items[$hash]['used'] = $generation;
      }
    }
    $this->cache->set(self::CID, ['generation' => $generation, 'items' => $items], Cache::PERMANENT);
    $this->lock->release($lock_name);

    $this->changed = [];
  }

  /**
   * Reads the cache item, once per request.
   */
  private function load(): void {
    if ($this->loaded) {
      return;
    }
    $this->loaded = TRUE;
    $cached = $this->cache->get(self::CID);
    if ($cached !== FALSE) {
      [$this->generation, $this->items] = self::unpack($cached->data);
    }
  }

  /**
   * Reads the generation and items from what's cached.
   *
   * @param mixed $data
   *   The cached data, if any.
   *
   * @return array{0: int, 1: array<string, array{imports: list<array{0: string, 1: int, 2: int}>|null, directives: array<int, string>, dynamic: list<array{0: int, 1: int}>, used: int}>}
   *   The generation and the items, keyed by hash.
   */
  private static function unpack(mixed $data): array {
    if (!\is_array($data) || !\is_int($data['generation'] ?? NULL) || !\is_array($data['items'] ?? NULL)) {
      return [0, []];
    }

    return [$data['generation'], $data['items']];
  }

}
