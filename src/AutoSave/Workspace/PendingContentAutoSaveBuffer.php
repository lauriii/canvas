<?php

declare(strict_types=1);

namespace Drupal\canvas\AutoSave\Workspace;

use Drupal\canvas\CanvasServiceProvider;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueStoreInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Key-value rows for pending edits and workspace staging metadata.
 *
 * Holds two kinds of rows, both durable (no expiry: an unflushed edit or a
 * conflict-detection marker must never silently evaporate):
 * - full pending edits queued by DeferredAutoSaveFlusher until they are
 *   flushed to workspace revisions at kernel terminate, and
 * - metadata tombstones (client_id, original_hash, conflict retention) that
 *   remain after a flush, because workspace revisions cannot record them.
 *
 * Rows are removed when the corresponding staging is published or discarded.
 *
 * @see \Drupal\canvas\AutoSave\Workspace\WorkspaceAutoSave::deleteEntity()
 */
final class PendingContentAutoSaveBuffer {

  private const string COLLECTION = 'canvas_auto_save_pending';

  public function __construct(
    // Staging bookkeeping must resolve identically in every workspace.
    // @see \Drupal\canvas\CanvasServiceProvider::registerWorkspaceInvariantKeyValueFactory()
    #[Autowire(service: CanvasServiceProvider::STAGING_KEY_VALUE_SERVICE)]
    private readonly KeyValueFactoryInterface $keyValueFactory,
  ) {}

  private function store(): KeyValueStoreInterface {
    return $this->keyValueFactory->get(self::COLLECTION);
  }

  /**
   * @return array<string, mixed>|null
   */
  public function get(string $key): ?array {
    $value = $this->store()->get($key);
    return \is_array($value) ? $value : NULL;
  }

  /**
   * @param array<string, mixed> $row
   */
  public function set(string $key, array $row): void {
    $this->store()->set($key, $row);
  }

  public function delete(string $key): void {
    $this->store()->delete($key);
  }

  public function has(string $key): bool {
    return $this->store()->has($key);
  }

  /**
   * @return array<string, array<string, mixed>>
   */
  public function getAll(): array {
    $all = $this->store()->getAll();
    /** @var array<string, array<string, mixed>> $out */
    $out = [];
    foreach ($all as $key => $value) {
      if (\is_array($value)) {
        $out[$key] = $value;
      }
    }
    return $out;
  }

  public function deleteAll(): void {
    $this->store()->deleteAll();
  }

}
