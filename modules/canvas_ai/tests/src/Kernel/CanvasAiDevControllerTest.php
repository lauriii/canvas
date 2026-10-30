<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_ai\Kernel;

use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai_agents\Entity\AiAgent;
use Drupal\ai_agents\PluginBase\AiAgentEntityWrapper;
use Drupal\ai_agents\PluginInterfaces\AiAgentInterface;
use Drupal\ai_agents\PluginManager\AiAgentManager;
use Drupal\canvas_ai\CanvasAiPermissions;
use Drupal\canvas_ai\CanvasAiTempStore;
use Drupal\canvas_dev_ai\Controller\CanvasDevAiBuilder;
use Drupal\Core\Asset\AttachedAssets;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Extension\ModuleInstallerInterface;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\canvas\Kernel\Traits\RequestTrait;
use Drupal\Tests\canvas_ai\Kernel\Traits\CanvasAiDevHopTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Tests the canvas_dev_ai AI controller's access, settings and error paths.
 *
 * @see \Drupal\Tests\canvas_ai\Kernel\Agents\CanvasComponentAgentEndToEndTest
 */
#[Group('canvas_ai')]
#[CoversClass(CanvasDevAiBuilder::class)]
#[RunTestsInSeparateProcesses]
final class CanvasAiDevControllerTest extends CanvasKernelTestBase {

  use CanvasAiDevHopTrait;
  use RequestTrait;
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'canvas_ai',
    'ai',
    'ai_agents',
    'ai_test',
    'key',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // canvas_dev_ai_install() offers an ai_agent entity from canvas_ai's
    // config/install as a Tool, so that must be installed before
    // canvas_dev_ai is.
    $this->installConfig(['canvas_ai', 'ai', 'ai_agents', 'ai_test']);
    $this->installEntitySchema('user');
    // Uninstalling any module fires user_module_uninstall(), which deletes from
    // the users_data table. Kernel tests do not create it unless asked.
    $this->installSchema('user', ['users_data']);
    $this->installEntitySchema('path_alias');
    $this->setUpCurrentUser(permissions: [CanvasAiPermissions::USE_CANVAS_AI]);
    // The echoai provider reads the ai_mock_provider_result table before the
    // file fixtures.
    $this->installEntitySchema('ai_mock_provider_result');
    // The controller instantiates the default chat provider before running the
    // agent, so every hop needs one even when the agent is mocked.
    $this->config('ai.settings')
      ->set('default_providers.chat', ['provider_id' => 'echoai', 'model_id' => 'gpt-test'])
      ->save();
  }

  /**
   * Tests that the `aiDevMode` flag follows the module install state.
   */
  public function testAiDevModeFlagFollowsInstallState(): void {
    $this->assertArrayNotHasKey('aiDevMode', $this->alterJsSettings()['canvas']);

    $this->container->get(ModuleInstallerInterface::class)->install(['canvas_dev_ai']);
    $this->refreshContainer();
    $this->assertTrue($this->alterJsSettings()['canvas']['aiDevMode']);

    $this->container->get(ModuleInstallerInterface::class)->uninstall(['canvas_dev_ai']);
    $this->refreshContainer();
    $this->assertArrayNotHasKey('aiDevMode', $this->alterJsSettings()['canvas']);
  }

  /**
   * Tests that the controller rejects a request with an invalid CSRF token.
   */
  public function testControllerRejectsInvalidCsrfToken(): void {
    $this->container->get(ModuleInstallerInterface::class)->install(['canvas_dev_ai']);
    $this->refreshContainer();

    $request = Request::create('/admin/api/canvas/ai-dev', 'POST');
    $request->headers->set('X-CSRF-Token', 'invalid-token');

    $this->expectException(AccessDeniedHttpException::class);
    $this->expectExceptionMessage('Invalid CSRF token');
    $this->request($request);
  }

  /**
   * A determineSolvability() failure clears the stored agent state.
   *
   * Both the turn's state and the conversation's checkpoints go: the next turn
   * seeds the agent from the client transcript rather than from a history
   * that stops before the failed turn.
   *
   * @see \Drupal\canvas_dev_ai\Controller\CanvasDevAiBuilder::render()
   */
  public function testDetermineSolvabilityFailureClearsStoredAgentState(): void {
    $this->container->get(ModuleInstallerInterface::class)->install(['canvas_dev_ai']);
    $this->refreshContainer();
    $this->setUpAiDevHops();

    // Seed the state a previous hop would have parked, so deletion is
    // observable. The controller resumes it through the agent's fromArray().
    // It is parked under the main agent, which the tool-less hop below
    // resolves; any other agent would be rejected before the agent runs.
    $temp_store = $this->container->get(CanvasAiTempStore::class);
    $temp_store->setStoredAgentState('test-request', 'canvas_agent', ['looped' => FALSE]);
    self::assertNotNull($temp_store->getStoredAgentState('test-request'));
    $temp_store->setStoredConversationState('test-conversation', 'turn-1', 'canvas_agent', ['looped' => 1]);

    $agent = $this->createMock(AiAgentEntityWrapper::class);
    $agent->method('determineSolvability')
      ->willThrowException(new \Exception('The provider exploded.'));
    // The turn selects no Tool, so the shipped main agent is the one invoked.
    $agent_manager = $this->createMock(AiAgentManager::class);
    $agent_manager->method('hasDefinition')
      ->with('canvas_agent')
      ->willReturn(TRUE);
    $agent_manager->method('createInstance')
      ->with('canvas_agent')
      ->willReturn($agent);
    $this->container->set('plugin.manager.ai_agents', $agent_manager);

    $response = $this->hop([
      'messages' => [['role' => 'user', 'text' => 'Make a red button']],
    ]);

    // The turn failed, the frontend must not send another hop, and the
    // half-serialized state is gone so the next turn starts clean.
    self::assertSame([
      'status' => FALSE,
      'message' => 'The provider exploded.',
      'should_continue' => FALSE,
      'progress' => '',
    ], $response);
    self::assertNull($temp_store->getStoredAgentState('test-request'));
    self::assertNull($temp_store->getStoredConversationState('test-conversation', 'turn-1'));
  }

  /**
   * A not-solvable response gives the expected error and forgets the turn.
   *
   * Outside max-loop exhaustion, the error is the one the provider reported,
   * or a generic message when there is none. The conversation's checkpoints
   * go with the turn: what the agent did before giving up is not a history
   * the next turn should resume from.
   *
   * @see \Drupal\canvas_dev_ai\Controller\CanvasDevAiBuilder::getNotSolvableMessage()
   * @see \Drupal\Tests\canvas_ai\Kernel\Agents\DrupalCanvasPageAgentEndToEndTest::testMaxLoopsOutcomeIsReported()
   * @see \Drupal\Tests\canvas_ai\Kernel\Agents\CanvasComponentAgentEndToEndTest::testMaxLoopsWithoutAConfiguredMessageUsesTheDefault()
   */
  #[DataProvider('providerNotSolvableResponse')]
  public function testNotSolvableResponseGivesExpectedError(?string $last_error, string $expected_message): void {
    $this->container->get(ModuleInstallerInterface::class)->install(['canvas_dev_ai']);
    $this->refreshContainer();
    $this->setUpAiDevHops();

    $temp_store = $this->container->get(CanvasAiTempStore::class);
    $temp_store->setStoredConversationState('test-conversation', 'turn-1', 'canvas_agent', ['looped' => 1]);

    // The agent gave up on loop 1, well inside the agent's max_loops of 50.
    $agent = $this->createMock(AiAgentEntityWrapper::class);
    $agent->method('determineSolvability')->willReturn(AiAgentInterface::JOB_NOT_SOLVABLE);
    $agent->method('isFinished')->willReturn(TRUE);
    $agent->method('toArray')->willReturn(['looped' => 1]);
    $agent->method('getLastError')->willReturn($last_error);
    $agent->method('getAiAgentEntity')->willReturnCallback(
      fn () => AiAgent::load('drupal_canvas_page_agent'),
    );
    $agent_manager = $this->createMock(AiAgentManager::class);
    $agent_manager->method('hasDefinition')->willReturn(TRUE);
    $agent_manager->method('createInstance')->willReturn($agent);
    $this->container->set('plugin.manager.ai_agents', $agent_manager);

    $response = $this->hop([
      'messages' => [['role' => 'user', 'text' => 'Add a hero']],
    ]);

    self::assertFalse($response['status']);
    self::assertSame($expected_message, $response['message']);
    self::assertFalse($response['should_continue']);
    self::assertNull($temp_store->getStoredConversationState('test-conversation', 'turn-1'));
  }

  /**
   * Data provider for testNotSolvableResponseGivesExpectedError().
   */
  public static function providerNotSolvableResponse(): array {
    return [
      'provider error is shown' => [
        'The model is overloaded. Please try again later.',
        'The model is overloaded. Please try again later.',
      ],
      'no provider error falls back to the generic message' => [
        NULL,
        'The request could not be completed. Please try again.',
      ],
    ];
  }

  /**
   * A turn that ends cleanly keeps its agent state for the conversation.
   *
   * The turn's own state is gone, since the turn is over. The checkpoint the
   * next turn resumes from, keyed by this turn's request ID, is the agent as
   * it finished.
   *
   * @see \Drupal\canvas_dev_ai\Controller\CanvasDevAiBuilder::storeConversationState()
   */
  public function testCleanTurnEndKeepsTheConversationState(): void {
    $this->container->get(ModuleInstallerInterface::class)->install(['canvas_dev_ai']);
    $this->refreshContainer();
    $this->setUpAiDevHops();
    $this->keepToolCallsInHistory();

    $state = [
      'looped' => 2,
      'context_tools' => [],
      'chat_history' => [['role' => 'user', 'text' => 'Add a hero']],
    ];
    $agent = $this->createMock(AiAgentEntityWrapper::class);
    $agent->method('determineSolvability')->willReturn(AiAgentInterface::JOB_SOLVABLE);
    $agent->method('isFinished')->willReturn(TRUE);
    $agent->method('toArray')->willReturn($state);
    $agent->method('solve')->willReturn('The hero is placed.');
    $this->useMockedAgent($agent);

    $response = $this->hop([
      'messages' => [['role' => 'user', 'text' => 'Add a hero']],
    ]);

    self::assertTrue($response['status']);
    self::assertFalse($response['should_continue']);
    self::assertSame('The hero is placed.', $response['message']);
    $temp_store = $this->container->get(CanvasAiTempStore::class);
    self::assertNull($temp_store->getStoredAgentState('test-request'));
    self::assertSame(['agent_id' => 'canvas_agent', 'state' => $state], $temp_store->getStoredConversationState('test-conversation', 'test-request'));
  }

  /**
   * A request that sends no conversation_id keeps nothing for a next turn.
   *
   * @see \Drupal\canvas_dev_ai\Controller\CanvasDevAiBuilder::getConversationId()
   */
  public function testTurnWithoutConversationIdKeepsNothing(): void {
    $this->container->get(ModuleInstallerInterface::class)->install(['canvas_dev_ai']);
    $this->refreshContainer();
    $this->setUpAiDevHops();
    $this->keepToolCallsInHistory();

    $agent = $this->createMock(AiAgentEntityWrapper::class);
    $agent->method('determineSolvability')->willReturn(AiAgentInterface::JOB_SOLVABLE);
    $agent->method('isFinished')->willReturn(TRUE);
    $agent->method('toArray')->willReturn(['looped' => 1, 'context_tools' => []]);
    $agent->method('solve')->willReturn('Done.');
    $this->useMockedAgent($agent);

    $this->hop([
      'messages' => [['role' => 'user', 'text' => 'Add a hero']],
      'conversation_id' => '',
    ]);

    $temp_store = $this->container->get(CanvasAiTempStore::class);
    self::assertNull($temp_store->getStoredConversationState('', 'test-request'));
    self::assertNull($temp_store->getStoredConversationState('test-conversation', 'test-request'));
  }

  /**
   * The first turn of a conversation seeds the agent from the transcript.
   *
   * With nothing to resume, the client transcript is the only history there
   * is: the earlier messages become the chat history and the last one the
   * chat input.
   *
   * @see \Drupal\canvas_dev_ai\Controller\CanvasDevAiBuilder::prepareAgent()
   */
  public function testFirstTurnSeedsTheAgentFromTheTranscript(): void {
    $this->container->get(ModuleInstallerInterface::class)->install(['canvas_dev_ai']);
    $this->refreshContainer();
    $this->setUpAiDevHops();
    $this->keepToolCallsInHistory();

    $agent = $this->createMock(AiAgentEntityWrapper::class);
    $agent->expects(self::never())->method('fromArray');
    $agent->expects(self::once())->method('setChatHistory')
      ->with(self::callback(static fn (array $history): bool => \count($history) === 2));
    $agent->expects(self::once())->method('setChatInput')
      ->with(self::callback(static fn (ChatInput $input): bool => str_contains($input->getMessages()[0]->getText(), 'Make it blue')));
    $agent->method('determineSolvability')->willReturn(AiAgentInterface::JOB_SOLVABLE);
    $agent->method('isFinished')->willReturn(TRUE);
    $agent->method('toArray')->willReturn(['looped' => 1, 'context_tools' => []]);
    $agent->method('solve')->willReturn('It is blue.');
    $this->useMockedAgent($agent);

    $response = $this->hop([
      'messages' => [
        ['role' => 'user', 'text' => 'Add a hero'],
        ['role' => 'assistant', 'text' => 'The hero is placed.'],
        ['role' => 'user', 'text' => 'Make it blue'],
      ],
    ]);
    self::assertTrue($response['status']);
  }

  /**
   * A later turn of a conversation resumes the checkpoint it names.
   *
   * The turn names the turn the client last accepted as its
   * last_successful_request_id. That checkpoint is restored with its loop
   * counter reset, so the agent reads the new message, records its narration,
   * and counts loops for this turn only; the client transcript is not used.
   * The checkpoint stays beside the one this turn writes.
   *
   * @see \Drupal\canvas_dev_ai\Controller\CanvasDevAiBuilder::prepareAgent()
   */
  public function testLaterTurnResumesTheConversationState(): void {
    $this->container->get(ModuleInstallerInterface::class)->install(['canvas_dev_ai']);
    $this->refreshContainer();
    $this->setUpAiDevHops();
    $this->keepToolCallsInHistory();

    $temp_store = $this->container->get(CanvasAiTempStore::class);
    $temp_store->setStoredConversationState('test-conversation', 'turn-1', 'canvas_agent', [
      'looped' => 3,
      'chat_history' => [['role' => 'user', 'text' => 'Add a hero']],
      'provider_id' => 'echoai',
    ]);

    $agent = $this->createMock(AiAgentEntityWrapper::class);
    $agent->expects(self::once())->method('fromArray')
      ->with(self::callback(static fn (array $state): bool => $state['looped'] === 0 && $state['provider_id'] === 'echoai' && \count($state['chat_history']) === 1));
    $agent->expects(self::once())->method('setChatInput')
      ->with(self::callback(static fn (ChatInput $input): bool => str_contains($input->getMessages()[0]->getText(), 'Make it blue')));
    $agent->expects(self::never())->method('setChatHistory');
    $agent->method('determineSolvability')->willReturn(AiAgentInterface::JOB_SOLVABLE);
    $agent->method('isFinished')->willReturn(TRUE);
    $agent->method('toArray')->willReturn(['looped' => 1, 'context_tools' => []]);
    $agent->method('solve')->willReturn('It is blue.');
    $this->useMockedAgent($agent);

    $response = $this->hop([
      'messages' => [
        ['role' => 'user', 'text' => 'Add a hero'],
        ['role' => 'assistant', 'text' => 'The hero is placed.'],
        ['role' => 'user', 'text' => 'Make it blue'],
      ],
      'request_id' => 'turn-2',
      'last_successful_request_id' => 'turn-1',
    ]);
    self::assertTrue($response['status']);
    self::assertSame('It is blue.', $response['message']);
    self::assertNotNull($temp_store->getStoredConversationState('test-conversation', 'turn-1'));
    self::assertSame(
      ['agent_id' => 'canvas_agent', 'state' => ['looped' => 1, 'context_tools' => []]],
      $temp_store->getStoredConversationState('test-conversation', 'turn-2'),
    );
  }

  /**
   * A turn running another agent does not resume the conversation's history.
   *
   * Selecting a different Tool between turns is allowed. The kept history
   * describes what the previous agent did, so the new one is seeded from the
   * client transcript instead, and its own turn replaces what is kept.
   *
   * @see \Drupal\canvas_dev_ai\Controller\CanvasDevAiBuilder::prepareAgent()
   */
  public function testLaterTurnWithAnotherToolDoesNotResumeTheConversation(): void {
    $this->container->get(ModuleInstallerInterface::class)->install(['canvas_dev_ai']);
    $this->refreshContainer();
    $this->setUpAiDevHops();
    $this->keepToolCallsInHistory();

    $temp_store = $this->container->get(CanvasAiTempStore::class);
    $temp_store->setStoredConversationState('test-conversation', 'turn-1', 'canvas_agent', [
      'looped' => 3,
      'chat_history' => [['role' => 'user', 'text' => 'Add a hero']],
    ]);

    $agent = $this->createMock(AiAgentEntityWrapper::class);
    $agent->expects(self::never())->method('fromArray');
    $agent->expects(self::once())->method('setChatHistory');
    $agent->method('determineSolvability')->willReturn(AiAgentInterface::JOB_SOLVABLE);
    $agent->method('isFinished')->willReturn(TRUE);
    $agent->method('toArray')->willReturn(['looped' => 1, 'context_tools' => []]);
    $agent->method('solve')->willReturn('Component created.');
    $this->useMockedAgent($agent);

    // The Tool names an agent canvas_dev_ai.settings offers, so the turn runs
    // it instead of the main agent that wrote the checkpoint it names.
    // @see canvas_dev_ai_install()
    $response = $this->hop([
      'messages' => [
        ['role' => 'user', 'text' => 'Add a hero'],
        ['role' => 'assistant', 'text' => 'The hero is placed.'],
        ['role' => 'user', 'text' => 'Now make me a button component'],
      ],
      'request_id' => 'turn-2',
      'last_successful_request_id' => 'turn-1',
      'selected_tool' => 'canvas_component_agent',
    ]);
    self::assertTrue($response['status']);

    // What this turn ended with is kept under the agent that ran it, beside
    // the checkpoint the client named.
    self::assertSame([
      'agent_id' => 'canvas_component_agent',
      'state' => ['looped' => 1, 'context_tools' => []],
    ], $temp_store->getStoredConversationState('test-conversation', 'turn-2'));
    self::assertNotNull($temp_store->getStoredConversationState('test-conversation', 'turn-1'));
  }

  /**
   * Nothing is kept or resumed for a conversation unless the site opted in.
   *
   * The setting is off after install. A turn then seeds the agent from the
   * client transcript even when a conversation state exists, and drops that
   * state when it ends, so turning the setting off is enough to stop resuming.
   *
   * @see \Drupal\canvas_dev_ai\Controller\CanvasDevAiBuilder::keepsToolCallsInHistory()
   * @see canvas_dev_ai_install()
   */
  public function testConversationStateNeedsTheOptIn(): void {
    $this->container->get(ModuleInstallerInterface::class)->install(['canvas_dev_ai']);
    $this->refreshContainer();
    $this->setUpAiDevHops();
    self::assertFalse($this->config('canvas_dev_ai.settings')->get('keep_tool_calls_in_history'));

    $temp_store = $this->container->get(CanvasAiTempStore::class);
    $temp_store->setStoredConversationState('test-conversation', 'turn-1', 'canvas_agent', [
      'looped' => 3,
      'chat_history' => [['role' => 'user', 'text' => 'Add a hero']],
    ]);

    $agent = $this->createMock(AiAgentEntityWrapper::class);
    // The setting is off, so the checkpoint the turn names is never read:
    // fromArray() is not called, and the transcript seeds the agent.
    $agent->expects(self::never())->method('fromArray');
    $agent->expects(self::once())->method('setChatHistory');
    $agent->method('determineSolvability')->willReturn(AiAgentInterface::JOB_SOLVABLE);
    $agent->method('isFinished')->willReturn(TRUE);
    $agent->method('toArray')->willReturn(['looped' => 1, 'context_tools' => []]);
    $agent->method('solve')->willReturn('It is blue.');
    $this->useMockedAgent($agent);

    $response = $this->hop([
      'messages' => [
        ['role' => 'user', 'text' => 'Add a hero'],
        ['role' => 'assistant', 'text' => 'The hero is placed.'],
        ['role' => 'user', 'text' => 'Make it blue'],
      ],
      'request_id' => 'turn-2',
      'last_successful_request_id' => 'turn-1',
    ]);
    self::assertTrue($response['status']);
    // With the setting off, the turn's end drops every checkpoint the
    // conversation held and keeps none of its own.
    self::assertNull($temp_store->getStoredConversationState('test-conversation', 'turn-1'));
    self::assertNull($temp_store->getStoredConversationState('test-conversation', 'turn-2'));
  }

  /**
   * A turn that names no resumable checkpoint seeds from the transcript.
   *
   * The client names the turn it last accepted. Before it has accepted one
   * of this conversation, or when the checkpoint it names is gone, there is
   * nothing to resume: the agent is seeded from the client transcript, and
   * once the turn ends its own checkpoint is the only one left.
   *
   * @see \Drupal\canvas_dev_ai\Controller\CanvasDevAiBuilder::prepareAgent()
   * @see \Drupal\canvas_ai\CanvasAiTempStore::setStoredConversationState()
   */
  #[DataProvider('providerNoResumableCheckpoint')]
  public function testTurnNamingNoResumableCheckpointSeedsFromTheTranscript(string $last_successful_request_id): void {
    $this->container->get(ModuleInstallerInterface::class)->install(['canvas_dev_ai']);
    $this->refreshContainer();
    $this->setUpAiDevHops();
    $this->keepToolCallsInHistory();

    $temp_store = $this->container->get(CanvasAiTempStore::class);
    $temp_store->setStoredConversationState('test-conversation', 'turn-1', 'canvas_agent', [
      'looped' => 3,
      'chat_history' => [['role' => 'user', 'text' => 'Add a hero']],
    ]);

    $agent = $this->createMock(AiAgentEntityWrapper::class);
    // No checkpoint matches the last_successful_request_id the turn sends, so
    // fromArray() is never called: the transcript seeds the agent.
    $agent->expects(self::never())->method('fromArray');
    $agent->expects(self::once())->method('setChatHistory');
    $agent->method('determineSolvability')->willReturn(AiAgentInterface::JOB_SOLVABLE);
    $agent->method('isFinished')->willReturn(TRUE);
    $agent->method('toArray')->willReturn(['looped' => 1, 'context_tools' => []]);
    $agent->method('solve')->willReturn('It is blue.');
    $this->useMockedAgent($agent);

    $response = $this->hop([
      'messages' => [
        ['role' => 'user', 'text' => 'Add a hero'],
        ['role' => 'assistant', 'text' => 'The hero is placed.'],
        ['role' => 'user', 'text' => 'Make it blue'],
      ],
      'request_id' => 'turn-2',
      'last_successful_request_id' => $last_successful_request_id,
    ]);
    self::assertTrue($response['status']);

    // Nothing matched the name the turn sent, so the write at the end of the
    // hop kept only this turn's checkpoint: the stale one under turn-1 is
    // dropped from the tempstore.
    self::assertNull($temp_store->getStoredConversationState('test-conversation', 'turn-1'));
    self::assertSame(
      ['agent_id' => 'canvas_agent', 'state' => ['looped' => 1, 'context_tools' => []]],
      $temp_store->getStoredConversationState('test-conversation', 'turn-2'),
    );
  }

  /**
   * Data provider for testTurnNamingNoResumableCheckpointSeedsFromTheTranscript().
   */
  public static function providerNoResumableCheckpoint(): array {
    return [
      'no turn accepted yet' => [''],
      'the named turn left no checkpoint' => ['turn-0'],
    ];
  }

  /**
   * Only the checkpoint the client names is resumed.
   *
   * Turn 1 was accepted. Turn 2 was stopped by the user but still ended on
   * the server, which wrote its checkpoint beside turn 1's. The client never
   * accepted turn 2, so turn 3 names turn 1: it resumes turn 1's checkpoint,
   * and turn 2's goes with the write turn 3 makes.
   *
   * @see \Drupal\canvas_dev_ai\Controller\CanvasDevAiBuilder::prepareAgent()
   * @see \Drupal\canvas_ai\CanvasAiTempStore::setStoredConversationState()
   */
  public function testOnlyTheCheckpointTheClientNamesIsResumed(): void {
    $this->container->get(ModuleInstallerInterface::class)->install(['canvas_dev_ai']);
    $this->refreshContainer();
    $this->setUpAiDevHops();
    $this->keepToolCallsInHistory();

    $temp_store = $this->container->get(CanvasAiTempStore::class);
    $accepted_history = [['role' => 'user', 'text' => 'Add a hero']];
    $temp_store->setStoredConversationState('test-conversation', 'turn-1', 'canvas_agent', [
      'looped' => 2,
      'chat_history' => $accepted_history,
      'provider_id' => 'echoai',
    ]);

    // Turn 2 names turn 1 and ends on the server, as a turn the user stopped
    // while its last hop was in flight still does.
    $agent = $this->createMock(AiAgentEntityWrapper::class);
    $agent->method('determineSolvability')->willReturn(AiAgentInterface::JOB_SOLVABLE);
    $agent->method('isFinished')->willReturn(TRUE);
    $agent->method('toArray')->willReturn(['looped' => 5, 'context_tools' => [], 'chat_history' => []]);
    $agent->method('solve')->willReturn('The footer is placed.');
    $this->useMockedAgent($agent);
    $this->hop([
      'messages' => [
        ['role' => 'user', 'text' => 'Add a hero'],
        ['role' => 'assistant', 'text' => 'The hero is placed.'],
        ['role' => 'user', 'text' => 'Add a footer'],
      ],
      'request_id' => 'turn-2',
      'last_successful_request_id' => 'turn-1',
    ]);
    self::assertNotNull($temp_store->getStoredConversationState('test-conversation', 'turn-1'));
    self::assertNotNull($temp_store->getStoredConversationState('test-conversation', 'turn-2'));

    // Turn 3 still names turn 1, and resumes its state rather than turn 2's.
    $agent = $this->createMock(AiAgentEntityWrapper::class);
    $agent->expects(self::once())->method('fromArray')
      ->with(self::callback(static fn (array $state): bool => $state['looped'] === 0 && $state['chat_history'] === $accepted_history));
    $agent->expects(self::never())->method('setChatHistory');
    $agent->method('determineSolvability')->willReturn(AiAgentInterface::JOB_SOLVABLE);
    $agent->method('isFinished')->willReturn(TRUE);
    $agent->method('toArray')->willReturn(['looped' => 1, 'context_tools' => []]);
    $agent->method('solve')->willReturn('Trying again.');
    $this->useMockedAgent($agent);
    $response = $this->hop([
      'messages' => [
        ['role' => 'user', 'text' => 'Add a hero'],
        ['role' => 'assistant', 'text' => 'The hero is placed.'],
        ['role' => 'user', 'text' => 'Add a footer'],
        ['role' => 'assistant', 'text' => 'You interrupted the agent. What would you like to change?'],
        ['role' => 'user', 'text' => 'Try again'],
      ],
      'request_id' => 'turn-3',
      'last_successful_request_id' => 'turn-1',
    ]);
    self::assertTrue($response['status']);

    self::assertNotNull($temp_store->getStoredConversationState('test-conversation', 'turn-1'));
    self::assertNull($temp_store->getStoredConversationState('test-conversation', 'turn-2'));
    self::assertNotNull($temp_store->getStoredConversationState('test-conversation', 'turn-3'));
  }

  /**
   * Opts the site in to keeping the agent state between turns.
   *
   * @see \Drupal\canvas_dev_ai\Form\CanvasDevAiAgentSelectionForm
   */
  private function keepToolCallsInHistory(): void {
    $this->config('canvas_dev_ai.settings')->set('keep_tool_calls_in_history', TRUE)->save();
  }

  /**
   * Makes the agent manager hand out the given agent for every turn.
   *
   * @param \Drupal\ai_agents\PluginBase\AiAgentEntityWrapper $agent
   *   The mocked agent.
   */
  private function useMockedAgent(AiAgentEntityWrapper $agent): void {
    $agent_manager = $this->createMock(AiAgentManager::class);
    $agent_manager->method('hasDefinition')->willReturn(TRUE);
    $agent_manager->method('createInstance')->willReturn($agent);
    $this->container->set('plugin.manager.ai_agents', $agent_manager);
  }

  /**
   * Re-fetches the container after a module install or uninstall rebuild.
   */
  private function refreshContainer(): void {
    $container = \Drupal::getContainer();
    \assert($container instanceof ContainerBuilder);
    $this->container = $container;
  }

  /**
   * Runs the js_settings alter hooks on a minimal Canvas settings array.
   */
  private function alterJsSettings(): array {
    $settings = ['canvas' => ['aiExtensionAvailable' => TRUE]];
    $assets = new AttachedAssets();
    $this->container->get(ModuleHandlerInterface::class)->alter('js_settings', $settings, $assets);
    return $settings;
  }

}
