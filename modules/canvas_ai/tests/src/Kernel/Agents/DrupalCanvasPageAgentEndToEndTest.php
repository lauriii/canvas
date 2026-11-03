<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_ai\Kernel\Agents;

use Drupal\ai\Event\PreGenerateResponseEvent;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\canvas\Entity\ContentTemplate;
use Drupal\canvas_ai\CanvasAiPermissions;
use Drupal\canvas_ai\CanvasAiTempStore;
use Drupal\canvas_ai\Plugin\AiFunctionCall\EditComponents;
use Drupal\canvas_ai\Plugin\AiFunctionCall\PlaceComponents;
use Drupal\canvas_dev_ai\Controller\CanvasDevAiBuilder;
use Drupal\Component\Uuid\Php;
use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\canvas\Kernel\Traits\RequestTrait;
use Drupal\Tests\canvas\Traits\GenerateComponentConfigTrait;
use Drupal\Tests\canvas_ai\Kernel\Traits\CanvasAiDevHopTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Tests drupal_canvas_page_agent turns driven through the dev controller.
 *
 * Provider responses come from the ai module's echoai provider, which matches
 * each hop's request against a recorded fixture under
 * tests/resources/ai_test/requests/chat. The fixtures also pin the agent's
 * request shape: the component catalog is not among the messages, since the
 * agent's default information tools feed the system prompt, and each later hop
 * carries the parked place_components call and its tool result.
 *
 * @see \Drupal\ai_test\Plugin\AiProvider\EchoProvider::getMatchingRequest()
 */
#[Group('canvas_ai')]
#[CoversClass(CanvasDevAiBuilder::class)]
#[RunTestsInSeparateProcesses]
final class DrupalCanvasPageAgentEndToEndTest extends CanvasKernelTestBase {

  use CanvasAiDevHopTrait;
  use GenerateComponentConfigTrait;
  use RequestTrait;
  use UserCreationTrait;

  /**
   * The service id of the UUID generator the placement helper is given.
   */
  private const PLACEMENT_UUID_SERVICE = 'canvas_ai.test_placement_uuid';

  /**
   * The UUIDs the two placements of this turn are assigned, in order.
   *
   * @see \Drupal\canvas_ai\CanvasAiPageBuilderHelper::assignUuidsRecursively()
   */
  private const HERO_UUID = '00000000-0000-4000-8000-000000000001';

  private const HEADING_UUID = '00000000-0000-4000-8000-000000000002';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'canvas_ai',
    'canvas_dev_ai',
    'key',
    'ai',
    'ai_test',
    'ai_agents',
  ];

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container): void {
    parent::register($container);

    // The fixtures repeat the placed components' UUIDs byte-for-byte, so the
    // placement helper is given a generator of its own, stubbed in ::setUp().
    // Scoping it to that one service keeps the UUIDs the rest of the request
    // allocates from shifting the placements'.
    $container->register(self::PLACEMENT_UUID_SERVICE, Php::class);
    $container->getDefinition('canvas_ai.page_builder_helper')
      ->setArgument('$uuidService', new Reference(self::PLACEMENT_UUID_SERVICE));
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installConfig(['canvas_ai', 'canvas_dev_ai', 'ai', 'ai_agents', 'ai_test']);
    // The dev page builder agent is not the shipped default; sites select it
    // on the Agents & Tools form. The controller reads this setting.
    $this->config('canvas_dev_ai.settings')
      ->set('main_agent', 'drupal_canvas_page_agent')
      ->save();
    // The echoai provider reads the ai_mock_provider_result table before the
    // file fixtures this test drives it from.
    $this->installEntitySchema('ai_mock_provider_result');
    $this->installEntitySchema('path_alias');
    // The catalog the first hop pins into the chat history, and the components
    // the placements validate against, come from the test SDCs.
    $this->generateComponentConfig();
    // Viewing the component entities the catalog lists requires Canvas UI
    // access on top of the AI permission: the content templates admin
    // permission grants it without setting up a Canvas-editable content
    // entity type.
    // @see \Drupal\canvas\Access\CanvasUiAccessCheck::access()
    $this->setUpCurrentUser(permissions: [CanvasAiPermissions::USE_CANVAS_AI, ContentTemplate::ADMIN_PERMISSION]);
    $this->setUpAiDevHops();

    $this->config('ai.settings')
      ->set('default_providers.chat', [
        'provider_id' => 'echoai',
        'model_id' => 'gpt-test',
      ])
      ->save();

    // Numbers the UUIDs in placement order: the hero gets ...0001 and the
    // heading ...0002, the values the fixtures' tool results carry.
    $this->container->set(self::PLACEMENT_UUID_SERVICE, new class () implements UuidInterface {

      private int $count = 0;

      public function generate(): string {
        return \sprintf('00000000-0000-4000-8000-%012d', ++$this->count);
      }

    });
  }

  /**
   * Running out of loops ends the turn with the agent's max-loops message.
   *
   * The controller used to map the agent's JOB_NOT_SOLVABLE outcome to a
   * generic "Something went wrong"; the configured message tells the user
   * what happened and that the page keeps what was placed.
   *
   * @see \Drupal\canvas_dev_ai\Controller\CanvasDevAiBuilder::getNotSolvableMessage()
   */
  public function testMaxLoopsOutcomeIsReported(): void {
    $agent = $this->config('ai_agents.ai_agent.drupal_canvas_page_agent');
    $agent->set('max_loops', 0)->save();

    $response = $this->hop([
      'messages' => [['role' => 'user', 'text' => 'Add a hero with a heading under it']],
      'current_layout' => ['regions' => ['content' => ['nodePathPrefix' => [0], 'components' => []]]],
    ]);

    $this->assertFalse($response['status']);
    $this->assertFalse($response['should_continue']);
    $this->assertSame($agent->get('max_loops_message'), $response['message']);
  }

  /**
   * Title and description set in one hop reach the client together.
   *
   * The set_page_value tool writes one key per call, so a hop that sets both
   * carries two tool results; their canvas_page_data must be merged, not
   * overwritten.
   */
  public function testTitleAndDescriptionSetInOneHopAreMerged(): void {
    $messages = [['role' => 'user', 'text' => 'Set the page title to Campus and the description to Visit us']];

    // fixture: tests/resources/ai_test/requests/chat/drupal-canvas-page-agent-metadata-hop-1.yml.
    $hop1 = $this->hop(['messages' => $messages, 'current_layout' => self::emptyLayout()]);
    $this->assertTrue($hop1['should_continue']);
    $this->assertArrayNotHasKey('canvas_page_data', $hop1);

    // fixture: tests/resources/ai_test/requests/chat/drupal-canvas-page-agent-metadata-hop-2.yml.
    $hop2 = $this->hop(['messages' => $messages, 'current_layout' => self::emptyLayout()]);
    $this->assertTrue($hop2['status']);
    $this->assertFalse($hop2['should_continue']);
    $this->assertEqualsCanonicalizing([
      'title[0][value]' => 'Campus',
      'description[0][value]' => 'Visit us',
    ], $hop2['canvas_page_data']);
    $this->assertSame('The title and description are set.', $hop2['message']);

    // This turn ran with keep_tool_calls_in_history off, so nothing was kept
    // for the conversation: the next turn rebuilds from the client transcript.
    // The checkpoint is keyed by request ID, which ::hop() defaults to
    // 'test-request'.
    self::assertNull(
      $this->container->get(CanvasAiTempStore::class)
        ->getStoredConversationState('test-conversation', 'test-request'),
    );
  }

  /**
   * The layout of a page with nothing on it yet.
   */
  private static function emptyLayout(): array {
    return ['regions' => ['content' => ['nodePathPrefix' => [0], 'components' => []]]];
  }

  /**
   * Provides the 'keep tool calls in history' setting values.
   */
  public static function providerPageBuilding(): array {
    return [
      'tool calls kept in history' => [TRUE],
      'tool calls not kept in history' => [FALSE],
    ];
  }

  /**
   * Tests the agent end to end with 'keep tool calls in history' on and off.
   *
   * One conversation plans a page, builds it, then edits it. With the option
   * on, each turn resumes the agent state the previous turn's kept tool calls
   * left behind; a kept tool result is trimmed only when it succeeded, and a
   * failure keeps its error text. With it off, each turn rebuilds from the
   * client's text-only transcript.
   *
   * @see \Drupal\canvas_dev_ai\Form\CanvasDevAiAgentSelectionForm
   * @see https://git.drupalcode.org/project/canvas/-/work_items/3592032
   * @see https://git.drupalcode.org/project/canvas/-/work_items/3592082
   */
  #[DataProvider('providerPageBuilding')]
  public function testPageBuilding(bool $keep_tool_calls_in_history): void {
    $this->config('canvas_dev_ai.settings')->set('keep_tool_calls_in_history', $keep_tool_calls_in_history)->save();

    // Record what the model is sent, so each turn can be asserted on the
    // request that opened it.
    $inputs = [];
    $this->container->get(EventDispatcherInterface::class)->addListener(
      PreGenerateResponseEvent::EVENT_NAME,
      static function (PreGenerateResponseEvent $event) use (&$inputs): void {
        $inputs[] = $event->getInput();
      },
    );

    // Turn 1: the agent reads the hero's and heading's metadata, then answers
    // with the plan.
    // fixture: tests/resources/ai_test/requests/chat/drupal-canvas-page-agent-build-plan-hop-1.yml, then -hop-2.yml.
    $turn1 = $this->driveTurn([
      'messages' => [['role' => 'user', 'text' => 'Create me a landing page for a university site']],
      'current_layout' => self::emptyLayout(),
      'request_id' => 'turn-1',
      'conversation_id' => 'conversation-1',
    ]);
    self::assertCount(2, $turn1);
    self::assertTrue($turn1[1]['status']);
    self::assertFalse($turn1[1]['should_continue']);
    self::assertArrayNotHasKey('operations', $turn1[1]);
    self::assertSame('Here is the plan: a hero at the top with a heading below it. Shall I build it?', $turn1[1]['message']);
    self::assertSame('Looking up the hero and heading components.', $turn1[1]['progress']);

    // Turn 2: the user approves. The hero placement fails, then succeeds; the
    // heading is placed below it, using the UUID from the hero's tool result.
    $turn2_first_request = \count($inputs);
    $turn2_prompt = [
      'messages' => [
        ['role' => 'user', 'text' => 'Create me a landing page for a university site'],
        ['role' => 'assistant', 'text' => 'Here is the plan: a hero at the top with a heading below it. Shall I build it?'],
        ['role' => 'user', 'text' => 'Yes, build it'],
      ],
      'current_layout' => self::emptyLayout(),
      'request_id' => 'turn-2',
      'conversation_id' => 'conversation-1',
      'last_successful_request_id' => 'turn-1',
    ];

    // fixture: tests/resources/ai_test/requests/chat/drupal-canvas-page-agent-build-place-hop-1.yml when $keep_tool_calls_in_history is TRUE,
    // drupal-canvas-page-agent-build-place-hop-1-no-history.yml when FALSE.
    $hop1 = $this->hop($turn2_prompt);
    self::assertTrue($hop1['should_continue']);
    self::assertArrayNotHasKey('operations', $hop1);
    self::assertSame('Placing the hero now.', $hop1['progress']);

    // fixture: tests/resources/ai_test/requests/chat/drupal-canvas-page-agent-build-place-hop-2.yml when $keep_tool_calls_in_history is TRUE,
    // drupal-canvas-page-agent-build-place-hop-2-no-history.yml when FALSE.
    $hop2 = $this->hop($turn2_prompt);
    self::assertTrue($hop2['should_continue']);
    // The failed placement returns no operations.
    self::assertArrayNotHasKey('operations', $hop2);

    // fixture: tests/resources/ai_test/requests/chat/drupal-canvas-page-agent-build-place-hop-3.yml when $keep_tool_calls_in_history is TRUE,
    // drupal-canvas-page-agent-build-place-hop-3-no-history.yml when FALSE.
    $hop3 = $this->hop($turn2_prompt);
    // The hero's operations reach the client while the turn continues.
    self::assertTrue($hop3['should_continue']);
    self::assertSame([
      'operations' => [
        [
          'operation' => 'ADD',
          'components' => [
            [
              'id' => 'sdc.canvas_test_sdc.my-hero',
              'uuid' => self::HERO_UUID,
              'nodePath' => [0, 0],
              'fieldValues' => [
                'heading' => 'Welcome to the university',
                'cta1href' => 'https://example.com',
              ],
            ],
          ],
        ],
      ],
    ], ['operations' => $hop3['operations']]);

    // fixture: tests/resources/ai_test/requests/chat/drupal-canvas-page-agent-build-place-hop-4.yml when $keep_tool_calls_in_history is TRUE,
    // drupal-canvas-page-agent-build-place-hop-4-no-history.yml when FALSE.
    // The client applied hop 3's operations, so the layout holds the hero
    // the heading is placed below.
    $hero = [
      'name' => 'sdc.canvas_test_sdc.my-hero',
      'uuid' => self::HERO_UUID,
      'props' => ['heading' => 'Welcome to the university', 'cta1href' => 'https://example.com'],
    ];
    $turn2_prompt['current_layout']['regions']['content']['components'] = [$hero];
    $hop4 = $this->hop($turn2_prompt);
    self::assertTrue($hop4['status']);
    self::assertFalse($hop4['should_continue']);
    self::assertSame('The hero and its heading are on the page.', $hop4['message']);
    self::assertSame(
      "Placing the hero now.\n\nCorrecting the placement.\n\nAdding the heading below the hero.",
      $hop4['progress'],
    );
    self::assertSame([
      'operations' => [
        [
          'operation' => 'ADD',
          'components' => [
            [
              'id' => 'sdc.canvas_test_sdc.heading',
              'uuid' => self::HEADING_UUID,
              'nodePath' => [0, 1],
              'fieldValues' => [
                'text' => 'Why study with us',
                'element' => 'h2',
              ],
            ],
          ],
        ],
      ],
    ], ['operations' => $hop4['operations']]);

    $turn2_first_input = $inputs[$turn2_first_request];
    self::assertInstanceOf(ChatInput::class, $turn2_first_input);
    $messages = $turn2_first_input->getMessages();
    $roles = \array_map(static fn (ChatMessage $message): string => $message->getRole(), $messages);
    if ($keep_tool_calls_in_history) {
      // The request that opened turn 2 carries turn 1's history: the user
      // message, the metadata call and its result, the plan, then this turn's
      // user message.
      self::assertSame(['user', 'assistant', 'tool', 'assistant', 'user'], $roles);
      self::assertSame('get_component_details', $messages[1]->toArray()['tools'][0]['function']['name']);
    }
    else {
      // The request that opened turn 2 carries the client's text-only
      // transcript.
      self::assertSame(['user', 'assistant', 'user'], $roles);
    }
    self::assertStringContainsString('Yes, build it', (string) \end($messages)->getText());

    // The component catalog feeds the system prompt, not the messages.
    self::assertStringContainsString('sdc.canvas_test_sdc.banner', $turn2_first_input->getSystemPrompt());
    self::assertStringContainsString('get_component_context', $turn2_first_input->getSystemPrompt());
    foreach ($messages as $message) {
      self::assertStringNotContainsString('sdc.canvas_test_sdc.banner', (string) $message->getText());
    }

    // Turn 3: the user asks for a change. The first edit names a UUID that is
    // not on the page.
    // fixture: tests/resources/ai_test/requests/chat/drupal-canvas-page-agent-build-edit-hop-1.yml, -hop-2.yml, then -hop-3.yml when $keep_tool_calls_in_history is TRUE,
    // drupal-canvas-page-agent-build-edit-hop-1-no-history.yml, -hop-2-no-history.yml, then -hop-3-no-history.yml when FALSE.
    $turn3_first_request = \count($inputs);
    $turn3_layout = self::emptyLayout();
    $turn3_layout['regions']['content']['components'] = [
      $hero,
      [
        'name' => 'sdc.canvas_test_sdc.heading',
        'uuid' => self::HEADING_UUID,
        'props' => ['text' => 'Why study with us', 'element' => 'h2'],
      ],
    ];
    $turn3 = $this->driveTurn([
      'messages' => [
        ['role' => 'user', 'text' => 'Create me a landing page for a university site'],
        ['role' => 'assistant', 'text' => 'Here is the plan: a hero at the top with a heading below it. Shall I build it?'],
        ['role' => 'user', 'text' => 'Yes, build it'],
        ['role' => 'assistant', 'text' => 'The hero and its heading are on the page.'],
        ['role' => 'user', 'text' => 'Change the hero heading to Hello'],
      ],
      'current_layout' => $turn3_layout,
      'request_id' => 'turn-3',
      'conversation_id' => 'conversation-1',
      'last_successful_request_id' => 'turn-2',
    ]);
    self::assertCount(3, $turn3);
    // The edit is parked first, so the opening hop carries no updates.
    self::assertTrue($turn3[0]['should_continue']);
    self::assertArrayNotHasKey('component_updates', $turn3[0]);
    // The failed edit returns no updates.
    self::assertArrayNotHasKey('component_updates', $turn3[1]);
    self::assertFalse($turn3[2]['should_continue']);
    self::assertSame([self::HERO_UUID => ['heading' => 'Hello']], $turn3[2]['component_updates']);
    self::assertArrayNotHasKey('operations', $turn3[2]);
    self::assertSame('The hero heading now says Hello.', $turn3[2]['message']);
    // The narration from the failed call and its correction both carry
    // forward; the closing answer is stripped from the end separately.
    self::assertSame(
      "Updating the hero heading.\n\nUsing the UUID that is on the page.",
      $turn3[2]['progress'],
    );

    $turn3_first_input = $inputs[$turn3_first_request];
    self::assertInstanceOf(ChatInput::class, $turn3_first_input);
    $messages = $turn3_first_input->getMessages();
    $roles = \array_map(static fn (ChatMessage $message): string => $message->getRole(), $messages);
    $conversation = $this->container->get(CanvasAiTempStore::class)
      ->getStoredConversationState('conversation-1', 'turn-3');
    if ($keep_tool_calls_in_history) {
      // The request that opened turn 3 carries both earlier turns. The failed
      // placement keeps its error text; the successful ones are cut to their
      // success sentence, since the UUID dump only served the turn that made
      // it.
      self::assertSame(
        ['user', 'assistant', 'tool', 'assistant', 'user', 'assistant', 'tool', 'assistant', 'tool', 'assistant', 'tool', 'assistant', 'user'],
        $roles,
      );
      self::assertStringContainsString(
        'The reference_uuid must be provided for above/below placement.',
        $messages[6]->getText(),
      );
      // CanvasDevAiBuilder::trimKeptToolResults() trims them when turn 2 is
      // stored.
      self::assertSame(PlaceComponents::SUCCESS_MESSAGE, $messages[8]->getText());
      self::assertSame(PlaceComponents::SUCCESS_MESSAGE, $messages[10]->getText());

      // Turn 3 ended clean, so its state was kept for the conversation. The
      // edit result it holds is cut to its success sentence; the failed one
      // before it keeps the error, which is what a later turn needs to see.
      self::assertNotNull($conversation);
      $tool_texts = \array_values(\array_map(
        static fn (array $message): string => $message['text'],
        \array_filter(
          $conversation['state']['chat_history'],
          static fn (array $message): bool => $message['role'] === 'tool',
        ),
      ));
      self::assertSame(EditComponents::SUCCESS_MESSAGE, \end($tool_texts));
      self::assertStringContainsString(
        'was not found on the page.',
        $tool_texts[\count($tool_texts) - 2],
      );
    }
    else {
      // The request that opened turn 3 carries the client's text-only
      // transcript, and nothing was kept for the conversation.
      self::assertSame(['user', 'assistant', 'user', 'assistant', 'user'], $roles);
      self::assertNull($conversation);
    }
  }

}
