<?php

declare(strict_types=1);

namespace Drupal\canvas_ai;

use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai\OperationType\GenericType\ImageFile;
use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Helper service for Canvas AI chat history management.
 */
class CanvasAiChatHelper {

  /**
   * Constructs a new CanvasAiChatHelper object.
   */
  public function __construct(
    protected ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Filters the chat history.
   *
   * @param array $conversation_history
   *   The entire conversation history after the current user message has been
   *   removed.
   *
   * @return \Drupal\ai\OperationType\Chat\ChatMessage[]
   *   The filtered chat history suitable for agent->setChatHistory().
   */
  public function getFilteredChatHistory(array $conversation_history): array {
    $max_messages = (int) $this->configFactory->get('canvas_ai.settings')->get('chat_history_max_messages');

    // 0 means no history: every request starts with a clean slate.
    if ($max_messages === 0) {
      return [];
    }

    // Negative values mean no limit; otherwise keep only the last N entries.
    if ($max_messages > 0) {
      $conversation_history = array_slice($conversation_history, -$max_messages);
    }

    $messages = [];
    foreach ($conversation_history as $message) {
      if (!empty($message['files'])) {
        $images = [];
        foreach ($message['files'] as $file_info) {
          if (!empty($file_info['src']) && preg_match('/^data:(image\/(?:jpeg|png));base64,(.+)$/i', $file_info['src'], $matches)) {
            $mime_type = $matches[1];
            $binary = base64_decode($matches[2], TRUE);
            if ($binary !== FALSE) {
              $images[] = new ImageFile($binary, $mime_type, 'temp');
            }
          }
        }
        $messages[] = new ChatMessage($message['role'], $message['text'], $images);
      }
      else {
        // Only messages carrying a 'text' key are included in the chat history.
        // This explicitly limits history to user input and the orchestrator
        // agent's final response, both of which the front-end stores under
        // 'text'. Sub-agent progress updates are stored under 'html' and are
        // intentionally excluded: models such as Anthropic Claude produce
        // verbose reasoning output inside sub-agent responses, and including
        // that content in the history would rapidly exhaust the model's context
        // window on longer conversations.
        //
        // @see ui/src/components/aiExtension/AiWizard.tsx
        if (!empty($message['text'])) {
          $messages[] = new ChatMessage($message['role'] === 'user' ? 'user' : 'assistant', $message['text']);
        }
      }
    }

    return $messages;
  }

}
