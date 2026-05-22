<?php

namespace Drupal\canvas_ai_agents_test\EventSubscriber;

use Drupal\ai\Event\PreGenerateResponseEvent;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai\OperationType\Chat\ChatOutput;
use Drupal\ai_agents\Event\AgentRequestEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Mocks sub-agent AI responses during orchestrator tests.
 *
 * When the orchestrator invokes a Canvas sub-agent as a tool, this subscriber
 * intercepts the resulting AI call and returns a canned success response,
 * preventing any real model calls for the sub-agents.
 */
class SubAgentResponseMockSubscriber implements EventSubscriberInterface {

  /**
   * Canvas AI sub-agent IDs whose AI calls should be mocked.
   */
  private const SUB_AGENT_IDS = [
    'canvas_component_agent',
    'canvas_page_builder_agent',
    'canvas_template_builder_agent',
    'canvas_title_generation_agent',
    'canvas_metadata_generation_agent',
  ];

  /**
   * Canned response returned for every intercepted sub-agent call.
   */
  private const MOCK_RESPONSE = 'I have successfully implemented the requested changes.';

  /**
   * Agent ID captured from the most recent AgentRequestEvent.
   */
  private ?string $invokedAgentId = NULL;

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      AgentRequestEvent::EVENT_NAME => 'onAgentRequest',
      PreGenerateResponseEvent::EVENT_NAME => 'onPreGenerate',
    ];
  }

  /**
   * Tracks which agent is about to make an AI call.
   */
  public function onAgentRequest(AgentRequestEvent $event): void {
    $this->invokedAgentId = $event->getAgentId();
  }

  /**
   * Injects a mock response when a sub-agent is about to call the AI model.
   */
  public function onPreGenerate(PreGenerateResponseEvent $event): void {
    if (!\in_array($this->invokedAgentId, self::SUB_AGENT_IDS, TRUE)) {
      return;
    }

    $event->setForcedOutputObject(
      new ChatOutput(
        new ChatMessage('assistant', self::MOCK_RESPONSE),
        self::MOCK_RESPONSE,
        [],
      )
    );

    $this->invokedAgentId = NULL;
  }

}
