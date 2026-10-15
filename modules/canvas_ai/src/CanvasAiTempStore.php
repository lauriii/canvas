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
   * Key prefix for the checkpoints a conversation's next turn resumes from.
   *
   * Keyed by the client-supplied conversation ID, in a key space of its own
   * for the same reason as AGENT_STATE_KEY_PREFIX. The entry holds at most
   * two checkpoints, keyed by request ID: the one the last turn resumed from
   * and the one it wrote.
   */
  private const CONVERSATION_STATE_KEY_PREFIX = 'conversation_state_';

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
   * Gets the agent state a paused chat turn parked.
   *
   * @param string $job_id
   *   The job ID identifying the chat turn.
   *
   * @return array{agent_id: string, state: array}|null
   *   The ID of the agent that parked the state and the state as written by
   *   its ::toArray(), or NULL when the turn is not paused.
   */
  public function getStoredAgentState(string $job_id): ?array {
    $record = $this->tempStore->get(self::AGENT_STATE_KEY_PREFIX . $job_id);
    if (!isset($record['agent_id'], $record['state'])) {
      return NULL;
    }
    return ['agent_id' => $record['agent_id'], 'state' => $record['state']];
  }

  /**
   * Stores the agent state of a paused chat turn.
   *
   * @param string $job_id
   *   The job ID identifying the chat turn.
   * @param string $agent_id
   *   The ID of the agent that parked the state. Only that agent can resume
   *   it: the state carries its chat history.
   * @param array $state
   *   The state, as returned by the agent's ::toArray().
   *
   * @throws \Drupal\Core\TempStore\TempStoreException
   */
  public function setStoredAgentState(string $job_id, string $agent_id, array $state): void {
    $this->tempStore->set(self::AGENT_STATE_KEY_PREFIX . $job_id, [
      'agent_id' => $agent_id,
      'state' => $state,
    ]);
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

  /**
   * Gets the checkpoint a conversation's next turn resumes from.
   *
   * @param string $conversation_id
   *   The conversation ID identifying the chat session.
   * @param string $request_id
   *   The request ID of the turn whose checkpoint to resume: the turn the
   *   client last accepted.
   *
   * @return array{agent_id: string, state: array}|null
   *   The ID of the agent that ran that turn and the state as written by its
   *   ::toArray(), or NULL when the conversation holds no checkpoint for it.
   */
  public function getStoredConversationState(string $conversation_id, string $request_id): ?array {
    $record = $this->getConversationCheckpoints($conversation_id)[$request_id] ?? NULL;
    if (!isset($record['agent_id'], $record['state'])) {
      return NULL;
    }
    return ['agent_id' => $record['agent_id'], 'state' => $record['state']];
  }

  /**
   * Stores the checkpoint a turn ended with, for the conversation's next turn.
   *
   * The conversation keeps two checkpoints at most: the one this turn resumed
   * from, which stays for as long as the client still names it, and this
   * turn's own. Every other checkpoint is dropped. A turn the user stopped
   * may still end on the server and write its checkpoint here; the client
   * never names that turn, so the checkpoint is never resumed and goes on
   * the next write.
   *
   * @param string $conversation_id
   *   The conversation ID identifying the chat session.
   * @param string $request_id
   *   The request ID of the turn that wrote the state.
   * @param string $agent_id
   *   The ID of the agent that ran the turn. Only that agent resumes the
   *   state: the history describes work the others never did.
   * @param array $state
   *   The state, as returned by the agent's ::toArray().
   * @param string $resumed_request_id
   *   The request ID of the turn this turn resumed from, or an empty string
   *   when it started from the client transcript.
   *
   * @throws \Drupal\Core\TempStore\TempStoreException
   */
  public function setStoredConversationState(string $conversation_id, string $request_id, string $agent_id, array $state, string $resumed_request_id = ''): void {
    $checkpoints = $this->getConversationCheckpoints($conversation_id);
    $stored = [
      $request_id => ['agent_id' => $agent_id, 'state' => $state],
    ];
    if ($resumed_request_id !== $request_id && isset($checkpoints[$resumed_request_id])) {
      $stored[$resumed_request_id] = $checkpoints[$resumed_request_id];
    }
    $this->tempStore->set(self::CONVERSATION_STATE_KEY_PREFIX . $conversation_id, $stored);
  }

  /**
   * Removes every checkpoint a conversation holds.
   *
   * @param string $conversation_id
   *   The conversation ID identifying the chat session.
   *
   * @throws \Drupal\Core\TempStore\TempStoreException
   */
  public function deleteStoredConversationState(string $conversation_id): void {
    $this->tempStore->delete(self::CONVERSATION_STATE_KEY_PREFIX . $conversation_id);
  }

  /**
   * Reads the checkpoints a conversation holds, keyed by request ID.
   *
   * @param string $conversation_id
   *   The conversation ID identifying the chat session.
   *
   * @return array<string, array>
   *   The checkpoints, empty when the conversation holds none.
   */
  private function getConversationCheckpoints(string $conversation_id): array {
    $record = $this->tempStore->get(self::CONVERSATION_STATE_KEY_PREFIX . $conversation_id);
    return \is_array($record) ? $record : [];
  }

}
