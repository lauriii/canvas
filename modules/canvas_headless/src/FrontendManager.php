<?php

declare(strict_types=1);

namespace Drupal\canvas_headless;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\StorageInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Coordinates ordered frontend URLs and synchronized component associations.
 *
 * The first entry is the default. Whole-list edits require the hash of the
 * ordered URLs; association-only changes do not invalidate that hash. Every
 * write reads authoritative configuration while holding the same short-lived
 * lock, without relying on configuration caches warmed earlier in the request.
 */
final class FrontendManager {

  private const string LOCK_KEY = 'canvas_headless_frontends';

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    #[Autowire(service: 'lock')]
    private readonly LockBackendInterface $lock,
    #[Autowire(service: 'config.storage.active')]
    private readonly StorageInterface $storage,
  ) {}

  /**
   * Returns the current ordered list and its URL-list hash.
   *
   * @return array{frontends: list<array{url: string, components: list<string>}>, frontendUrlsHash: string}
   *   The list and hash from the same configuration read.
   */
  public function get(): array {
    $stored = $this->storage->read('canvas_headless.settings')['frontends'] ?? [];
    $frontends = [];
    foreach (\is_array($stored) ? $stored : [] as $item) {
      if (\is_array($item) && \is_string($item['url'] ?? NULL)) {
        $frontends[] = [
          'url' => $item['url'],
          'components' => self::normalizeComponentIds($item['components'] ?? []),
        ];
      }
    }
    return self::snapshot($frontends);
  }

  /**
   * Replaces the ordered URLs, preserving the latest associations.
   *
   * @param list<mixed> $items
   *   Requested frontend objects containing URLs only. Associations are kept
   *   from stored configuration.
   * @param string $frontend_urls_hash
   *   The URL-list hash loaded before editing.
   *
   * @return array{frontends: list<array{url: string, components: list<string>}>, frontendUrlsHash: string}
   *   The saved list and hash.
   */
  public function replace(array $items, string $frontend_urls_hash): array {
    $frontends = $this->write(function (array $current) use ($items, $frontend_urls_hash): array {
      if ($current['frontendUrlsHash'] !== $frontend_urls_hash) {
        throw new ConflictHttpException('The frontend list has changed. Refresh your browser and try again.');
      }
      $existing_frontends = [];
      foreach ($current['frontends'] as $frontend) {
        $configured_frontend = FrontendUrl::fromConfig($frontend['url']);
        $existing_frontends[$configured_frontend === NULL ? $frontend['url'] : $configured_frontend->baseUrl] = $frontend;
      }
      $frontends = [];
      $seen_origins = [];
      foreach ($items as $delta => $item) {
        $url = \is_array($item) ? ($item['url'] ?? NULL) : NULL;
        if (!\is_string($url)) {
          throw new UnprocessableEntityHttpException(\sprintf('Frontend %d must be an object with a "url" string.', $delta));
        }
        // FrontendUrl accepts exactly the set of URLs the config schema
        // accepts, so a list that passes here also passes validation on save.
        $frontend = self::validateUrl($url);
        // Two entries for the same base URL would be the same frontend listed
        // twice; the canonical form catches spellings that differ only in
        // case.
        if (isset($seen_origins[$frontend->baseUrl])) {
          throw new UnprocessableEntityHttpException(\sprintf('"%s" is already in the list.', $url));
        }
        $seen_origins[$frontend->baseUrl] = TRUE;
        $frontends[] = [
          'url' => $url,
          'components' => $existing_frontends[$frontend->baseUrl]['components'] ?? [],
        ];
      }
      return $frontends;
    });
    return self::snapshot($frontends);
  }

  /**
   * Registers a canonical URL or atomically moves it to the default position.
   *
   * @return array{url: string, changed: bool}
   *   The canonical URL and whether anything changed.
   */
  public function add(string $url, bool $default = FALSE): array {
    $frontend = self::validateUrl($url);
    $changed = FALSE;
    $this->write(function (array $current) use ($frontend, $default, &$changed): array {
      $frontends = $current['frontends'];
      foreach ($frontends as $delta => $item) {
        if (FrontendUrl::fromConfig($item['url'])?->baseUrl === $frontend->baseUrl) {
          if ($default && $delta !== 0) {
            array_splice($frontends, $delta, 1);
            array_unshift($frontends, $item);
            $changed = TRUE;
          }
          return $frontends;
        }
      }
      $item = ['url' => $frontend->baseUrl, 'components' => []];
      if ($default) {
        array_unshift($frontends, $item);
      }
      else {
        $frontends[] = $item;
      }
      $changed = TRUE;
      return $frontends;
    });
    return ['url' => $frontend->baseUrl, 'changed' => $changed];
  }

  /**
   * Removes a canonical URL.
   *
   * Missing URLs succeed without changes. Removing an entry only drops its
   * associations, never component entities, content, or tokens.
   *
   * @return array{url: string, removed: bool, newDefault?: string|null}
   *   The canonical URL and whether it was removed. If the default was
   *   removed, includes the new default URL or NULL when none remains.
   */
  public function remove(string $url): array {
    $frontend = self::validateUrl($url);
    $removed = FALSE;
    $was_default = FALSE;
    $frontends = $this->write(function (array $current) use ($frontend, &$removed, &$was_default): array {
      $frontends = $current['frontends'];
      foreach ($frontends as $delta => $item) {
        if (FrontendUrl::fromConfig($item['url'])?->baseUrl === $frontend->baseUrl) {
          $removed = TRUE;
          $was_default = $delta === 0;
          array_splice($frontends, $delta, 1);
          break;
        }
      }
      return $frontends;
    });
    $result = ['url' => $frontend->baseUrl, 'removed' => $removed];
    if ($was_default) {
      $result['newDefault'] = $frontends[0]['url'] ?? NULL;
    }
    return $result;
  }

  /**
   * Updates associations without restoring a concurrently removed frontend.
   *
   * @param \Drupal\canvas_headless\FrontendUrl $frontend
   *   The synchronized frontend.
   * @param list<string> $component_ids
   *   The synchronized Canvas component IDs.
   */
  public function replaceComponents(FrontendUrl $frontend, array $component_ids): void {
    $this->write(function (array $current) use ($frontend, $component_ids): array {
      $frontends = $current['frontends'];
      foreach ($frontends as &$item) {
        if (FrontendUrl::fromConfig($item['url'])?->baseUrl === $frontend->baseUrl) {
          $item['components'] = $component_ids;
          break;
        }
      }
      return $frontends;
    });
  }

  /**
   * Reads, mutates, and saves configuration under one shared lock.
   *
   * @param callable(array{frontends: list<array{url: string, components: list<string>}>, frontendUrlsHash: string}): list<array{url: string, components: list<string>}> $mutate
   *   Computes the new list from fresh configuration.
   *
   * @return list<array{url: string, components: list<string>}>
   *   The saved list.
   */
  private function write(callable $mutate): array {
    if (!$this->lock->acquire(self::LOCK_KEY)) {
      $this->lock->wait(self::LOCK_KEY, 5);
      if (!$this->lock->acquire(self::LOCK_KEY)) {
        throw new ServiceUnavailableHttpException(1, 'Another frontend update is in progress. Try again.');
      }
    }
    try {
      $current = $this->get();
      $frontends = $mutate($current);
      if ($frontends !== $current['frontends']) {
        // A request may have warmed config caches before acquiring the lock.
        // Seed the editable config from authoritative storage, not that cache.
        $this->configFactory->reset('canvas_headless.settings');
        $stored = $this->storage->read('canvas_headless.settings');
        $this->configFactory->getEditable('canvas_headless.settings')
          ->initWithData($stored === FALSE ? [] : $stored)
          ->set('frontends', $frontends)
          ->save();
      }
      return $frontends;
    }
    finally {
      $this->lock->release(self::LOCK_KEY);
    }
  }

  /**
   * Pairs a frontend list with its URL-list hash.
   *
   * @param list<array{url: string, components: list<string>}> $frontends
   *   The ordered frontend list.
   *
   * @return array{frontends: list<array{url: string, components: list<string>}>, frontendUrlsHash: string}
   *   The list and its hash.
   */
  private static function snapshot(array $frontends): array {
    return [
      'frontends' => $frontends,
      'frontendUrlsHash' => hash('sha256', json_encode(array_column($frontends, 'url'), JSON_THROW_ON_ERROR)),
    ];
  }

  /**
   * Validates and canonicalizes a frontend URL using the existing rules.
   */
  private static function validateUrl(string $url): FrontendUrl {
    $frontend = FrontendUrl::fromConfig($url);
    if ($frontend === NULL) {
      throw new UnprocessableEntityHttpException(\sprintf('"%s" is not a valid frontend URL. It must be an absolute http:// or https:// URL with no credentials, query, fragment, dot path segments, or trailing slash.', $url));
    }
    return $frontend;
  }

  /**
   * Normalizes stored Canvas component IDs for the response.
   *
   * @return list<string>
   *   The normalized component IDs.
   */
  private static function normalizeComponentIds(mixed $components): array {
    return \is_array($components) ? array_values(array_filter($components, \is_string(...))) : [];
  }

}
