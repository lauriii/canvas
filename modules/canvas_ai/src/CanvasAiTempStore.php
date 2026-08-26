<?php

namespace Drupal\canvas_ai;

use Drupal\Core\TempStore\PrivateTempStore;
use Drupal\Core\TempStore\PrivateTempStoreFactory;

/**
 * Service for managing auto save functionality for Canvas AI.
 */
class CanvasAiTempStore {

  /**
   * Storage key for current layout data of the page.
   */
  public const CURRENT_LAYOUT_KEY = 'current_layout';

  /**
   * Key prefix for the serialized agent state, keyed by job ID.
   *
   * Keeps the client-supplied job IDs in a key space of their own, so they
   * cannot collide with the other keys in this collection.
   */
  private const AGENT_STATE_KEY_PREFIX = 'agent_state_';

  /**
   * The private tempstore object.
   *
   * @var \Drupal\Core\TempStore\PrivateTempStore
   */
  protected PrivateTempStore $tempStore;

  /**
   * Constructs a new CanvasAiTempStore object.
   *
   * @param \Drupal\Core\TempStore\PrivateTempStoreFactory $tempStoreFactory
   *   The tempstore factory.
   */
  public function __construct(
    PrivateTempStoreFactory $tempStoreFactory,
  ) {
    $this->tempStore = $tempStoreFactory->get('canvas_ai');
  }

  /**
   * Sets the data in the tempstore.
   *
   * @param string $key
   *   The key for storing data.
   * @param string $data
   *   The data to store in the tempstore.
   *
   * @throws \Drupal\Core\TempStore\TempStoreException
   */
  public function setData(string $key, string $data): void {
    $this->tempStore->set($key, $data);
  }

  /**
   * Gets the data from the tempstore.
   *
   * @param string $key
   *   The key to retrieve data for.
   *
   * @return string|null
   *   The data, or NULL if not set.
   */
  public function getData(string $key): ?string {
    return $this->tempStore->get($key);
  }

  /**
   * Removes specific data from the tempstore.
   *
   * @param string $key
   *   The key to remove data for.
   *
   * @throws \Drupal\Core\TempStore\TempStoreException
   */
  public function deleteData(string $key): void {
    $this->tempStore->delete($key);
  }

  /**
   * Gets the serialized agent state for a chat turn.
   *
   * @param string $job_id
   *   The job ID identifying the chat turn.
   *
   * @return array|null
   *   The state as written by the agent's ::toArray(), or NULL when the turn
   *   is not paused.
   */
  public function getStoredAgentState(string $job_id): ?array {
    $state = $this->tempStore->get(self::AGENT_STATE_KEY_PREFIX . $job_id);
    return \is_array($state) ? $state : NULL;
  }

  /**
   * Stores the serialized agent state for a chat turn.
   *
   * @param string $job_id
   *   The job ID identifying the chat turn.
   * @param array $state
   *   The state, as returned by the agent's ::toArray().
   *
   * @throws \Drupal\Core\TempStore\TempStoreException
   */
  public function setStoredAgentState(string $job_id, array $state): void {
    $this->tempStore->set(self::AGENT_STATE_KEY_PREFIX . $job_id, $state);
  }

  /**
   * Removes the serialized agent state for a chat turn.
   *
   * @param string $job_id
   *   The job ID identifying the chat turn.
   *
   * @throws \Drupal\Core\TempStore\TempStoreException
   */
  public function deleteStoredAgentState(string $job_id): void {
    $this->tempStore->delete(self::AGENT_STATE_KEY_PREFIX . $job_id);
  }

}
