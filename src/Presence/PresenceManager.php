<?php

declare(strict_types=1);

namespace Drupal\canvas\Presence;

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\KeyValueStore\KeyValueExpirableFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueStoreExpirableInterface;

/**
 * Tracks who has an auto-saved entity open in the Canvas editor.
 *
 * Each browser tab is a session: one expirable key-value row in its target's
 * collection, keyed by the client instance ID. The target is the entity's
 * auto-save entry, so presence and conflict detection agree on what "the same
 * item" is. A session that stops sending heartbeats expires after the TTL.
 *
 * @see \Drupal\canvas\AutoSave\AutoSaveManager::getAutoSaveKey()
 * @see \Drupal\canvas\Controller\ApiPresenceController
 *
 * @phpstan-type PresenceSession array{uid: int, selection: list<string>, active: bool}
 *
 * @internal
 */
final class PresenceManager {

  /**
   * Seconds a session lives without a heartbeat.
   *
   * The client sends a heartbeat every 5 seconds while visible and every 30
   * seconds while hidden.
   */
  public const int TTL = 45;

  private const string COLLECTION_PREFIX = 'canvas.presence.';

  /**
   * The maximum length of a key-value collection name.
   *
   * @see \Drupal\Core\KeyValueStore\DatabaseStorage::schemaDefinition()
   */
  private const int MAX_COLLECTION_LENGTH = 128;

  public function __construct(
    private readonly KeyValueExpirableFactoryInterface $keyValueExpirableFactory,
  ) {}

  /**
   * Writes or refreshes a session.
   *
   * @param list<string> $selection
   *   The UUIDs of the components the session has selected.
   */
  public function join(EntityInterface $target, string $client_instance_id, int $uid, array $selection, bool $active): void {
    $this->store($target)->setWithExpire($client_instance_id, [
      'uid' => $uid,
      'selection' => $selection,
      'active' => $active,
    ], self::TTL);
  }

  /**
   * Removes a session, but only when it belongs to the given user.
   */
  public function leave(EntityInterface $target, string $client_instance_id, int $uid): void {
    $store = $this->store($target);
    $session = $store->get($client_instance_id);
    if (\is_array($session) && $session['uid'] === $uid) {
      $store->delete($client_instance_id);
    }
  }

  /**
   * Gets the live sessions on a target.
   *
   * @return array<string, PresenceSession>
   *   Sessions keyed by client instance ID.
   */
  public function getSessions(EntityInterface $target): array {
    /** @var array<string, PresenceSession> */
    return $this->store($target)->getAll();
  }

  private function store(EntityInterface $target): KeyValueStoreExpirableInterface {
    $collection = self::COLLECTION_PREFIX . AutoSaveManager::getAutoSaveKey($target);
    if (\strlen($collection) > self::MAX_COLLECTION_LENGTH) {
      $collection = self::COLLECTION_PREFIX . \hash('sha256', $collection);
    }
    return $this->keyValueExpirableFactory->get($collection);
  }

}
