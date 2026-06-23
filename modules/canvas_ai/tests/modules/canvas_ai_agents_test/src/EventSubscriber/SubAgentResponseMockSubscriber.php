<?php

declare(strict_types=1);

namespace Drupal\canvas_ai_agents_test\EventSubscriber;

use Drupal\ai\Event\PreGenerateResponseEvent;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai\OperationType\Chat\ChatOutput;
use Drupal\ai_agents\Event\AgentRequestEvent;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Mocks sub-agent AI responses during orchestrator tests.
 *
 * When the orchestrator invokes a Canvas sub-agent as a tool, this subscriber
 * intercepts the resulting AI call and returns a canned success response,
 * preventing any real model calls for the sub-agents.
 *
 * Mocking is opt-in: it is only active when the test entity's ai_agent field
 * targets the orchestrator. Tests that target a sub-agent directly are left
 * unaffected so the sub-agent executes normally and its tool outputs can be
 * asserted.
 */
final class SubAgentResponseMockSubscriber implements EventSubscriberInterface {

  /**
   * Route names on which the mock lifecycle runs.
   *
   * @var string[]
   */
  private const TEST_ROUTES = [
    'ai_agents_test.group_ajax',
    'ai_agents_test.run_test',
  ];

  /**
   * Agent ID of the orchestrator that delegates to sub-agents.
   */
  private const ORCHESTRATOR_AGENT_ID = 'canvas_ai_orchestrator';

  /**
   * Canvas AI sub-agent IDs whose AI calls should be mocked.
   *
   * @var string[]
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
   * Whether sub-agent mocking is active for the current request.
   *
   * Defaults to FALSE. Set to TRUE only when the test targets the orchestrator,
   * which means sub-agent calls should be mocked.
   */
  private bool $mockingEnabled = FALSE;

  /**
   * Constructs a SubAgentResponseMockSubscriber.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      KernelEvents::REQUEST => 'onRequest',
      AgentRequestEvent::EVENT_NAME => 'onAgentRequest',
      PreGenerateResponseEvent::EVENT_NAME => 'onPreGenerate',
    ];
  }

  /**
   * Enables mocking when the current test targets the orchestrator.
   *
   * Resets state on every main request so that successive tests in a group
   * run do not bleed into each other.
   */
  public function onRequest(RequestEvent $event): void {
    if (!$event->isMainRequest()) {
      return;
    }

    $this->mockingEnabled = FALSE;
    $this->invokedAgentId = NULL;

    $request = $event->getRequest();
    if (!\in_array($request->attributes->get('_route'), self::TEST_ROUTES, TRUE)) {
      return;
    }

    $testId = $request->attributes->get('test_id');
    if (!$testId) {
      return;
    }

    /** @var \Drupal\ai_agents_test\Entity\AgentTest|null $test */
    $test = $this->entityTypeManager
      ->getStorage('ai_agents_test')
      ->load($testId);

    if (!$test) {
      return;
    }

    if (($test->ai_agent->target_id ?? NULL) === self::ORCHESTRATOR_AGENT_ID) {
      $this->mockingEnabled = TRUE;
    }
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
    if (!$this->mockingEnabled) {
      return;
    }

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
