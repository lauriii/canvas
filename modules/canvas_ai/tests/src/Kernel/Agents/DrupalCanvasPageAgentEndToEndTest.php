<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_ai\Kernel\Agents;

use Drupal\ai\Event\PreGenerateResponseEvent;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\canvas\Entity\ContentTemplate;
use Drupal\canvas_ai\CanvasAiPermissions;
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
   * A whole-page request ends the turn at the plan gate, placing nothing.
   *
   * The plan is the agent's answer; the frontend must not send another hop.
   */
  public function testWholePageRequestStopsAtThePlanGate(): void {
    // fixture: tests/resources/ai_test/requests/chat/drupal-canvas-page-agent-plan-gate.yml.
    $hop = $this->hop([
      'messages' => [['role' => 'user', 'text' => 'Create me a landing page for a university site']],
      'current_layout' => self::emptyLayout(),
    ]);
    $this->assertTrue($hop['status']);
    $this->assertFalse($hop['should_continue']);
    $this->assertArrayNotHasKey('operations', $hop);
    $this->assertSame('Here is the plan: 1) Hero — the welcome and the main call to action. 2) Programs — three cards on the main study areas. 3) Campus life — what a week looks like. 4) Admissions — how to apply, with dates. 5) Contact — where to ask questions. Shall I build it?', $hop['message']);
    $this->assertSame('', $hop['progress']);
  }

  /**
   * An edit turn returns the component_updates the client applies.
   *
   * Hop 1 parks the edit_components call; hop 2 executes it against the
   * layout, returns the updates and closes the turn.
   */
  public function testEditTurnReturnsComponentUpdates(): void {
    $messages = [['role' => 'user', 'text' => 'Change the hero heading to Hello']];
    $layout = [
      'regions' => [
        'content' => [
          'nodePathPrefix' => [0],
          'components' => [
            [
              'name' => 'sdc.canvas_test_sdc.my-hero',
              'uuid' => self::HERO_UUID,
              'props' => ['heading' => 'Build Faster with Canvas', 'cta1href' => 'https://example.com'],
            ],
          ],
        ],
      ],
    ];

    // fixture: tests/resources/ai_test/requests/chat/drupal-canvas-page-agent-edit-hop-1.yml.
    $hop1 = $this->hop(['messages' => $messages, 'current_layout' => $layout]);
    $this->assertTrue($hop1['status']);
    $this->assertTrue($hop1['should_continue']);
    $this->assertArrayNotHasKey('component_updates', $hop1);
    $this->assertSame('Changing the hero heading now.', $hop1['progress']);

    // fixture: tests/resources/ai_test/requests/chat/drupal-canvas-page-agent-edit-hop-2.yml.
    $hop2 = $this->hop(['messages' => $messages, 'current_layout' => $layout]);
    $this->assertTrue($hop2['status']);
    $this->assertFalse($hop2['should_continue']);
    $this->assertSame(['component_updates' => [self::HERO_UUID => ['heading' => 'Hello']]], ['component_updates' => $hop2['component_updates']]);
    $this->assertArrayNotHasKey('operations', $hop2);
    $this->assertSame('The hero heading now says Hello.', $hop2['message']);
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
  }

  /**
   * The layout of a page with nothing on it yet.
   */
  private static function emptyLayout(): array {
    return ['regions' => ['content' => ['nodePathPrefix' => [0], 'components' => []]]];
  }

  /**
   * Builds the hero section one placement call per hop, chaining on its UUID.
   *
   * The turn takes three hops driven the way the dev wizard drives them, with
   * the layout each hop sends reflecting the operations the client applied so
   * far:
   * - Hop 1 only narrates and parks the first place_components call.
   * - Hop 2 executes it against the empty page and returns the hero placement
   *   with its backend-assigned UUID; the model reads that UUID from the tool
   *   result and parks the second call placing the heading below the hero.
   * - Hop 3 sends the layout containing the hero — without it the `below`
   *   placement could not resolve — and returns the heading placement plus the
   *   closing answer.
   */
  public function testSequentialPlacementAcrossHops(): void {
    $messages = [['role' => 'user', 'text' => 'Add a hero with a heading under it']];
    $empty_layout = [
      'regions' => [
        'content' => [
          'nodePathPrefix' => [0],
          'components' => [],
        ],
      ],
    ];

    // fixture: tests/resources/ai_test/requests/chat/drupal-canvas-page-agent-place-hop-1.yml.
    $hop1 = $this->hop([
      'messages' => $messages,
      'current_layout' => $empty_layout,
    ]);
    $this->assertTrue($hop1['status']);
    $this->assertTrue($hop1['should_continue']);
    $this->assertArrayNotHasKey('operations', $hop1);
    $this->assertSame('Adding the hero banner now.', $hop1['progress']);

    // fixture: tests/resources/ai_test/requests/chat/drupal-canvas-page-agent-place-hop-2.yml.
    // The client has no operations to apply yet, so it re-sends the same
    // layout; the parked hero placement executes against it during this hop.
    $hop2 = $this->hop([
      'messages' => $messages,
      'current_layout' => $empty_layout,
    ]);
    $this->assertTrue($hop2['status']);
    $this->assertTrue($hop2['should_continue']);
    $this->assertSame("Adding the hero banner now.\n\nAdding the heading under the hero now.", $hop2['progress']);
    $this->assertSame([
      'operations' => [
        [
          'operation' => 'ADD',
          'components' => [
            [
              'id' => 'sdc.canvas_test_sdc.my-hero',
              'uuid' => self::HERO_UUID,
              'nodePath' => [0, 0],
              'fieldValues' => [
                'heading' => 'Build Faster with Canvas',
                'subheading' => 'Everything you need to launch a page.',
                'cta1' => 'Get started',
                'cta1href' => 'https://example.com',
                'cta2' => 'Learn more',
              ],
            ],
          ],
        ],
      ],
    ], ['operations' => $hop2['operations']]);

    // fixture: tests/resources/ai_test/requests/chat/drupal-canvas-page-agent-place-hop-3.yml.
    // The client applied hop 2's operations before sending this hop, so the
    // layout now contains the hero the second placement references.
    $hop3 = $this->hop([
      'messages' => $messages,
      'current_layout' => [
        'regions' => [
          'content' => [
            'nodePathPrefix' => [0],
            'components' => [
              [
                'name' => 'sdc.canvas_test_sdc.my-hero',
                'uuid' => self::HERO_UUID,
                'props' => [
                  'heading' => 'Build Faster with Canvas',
                  'subheading' => 'Everything you need to launch a page.',
                  'cta1' => 'Get started',
                  'cta1href' => 'https://example.com',
                  'cta2' => 'Learn more',
                ],
              ],
            ],
          ],
        ],
      ],
    ]);
    $this->assertTrue($hop3['status']);
    $this->assertFalse($hop3['should_continue']);
    $this->assertSame('The hero and its heading are in place.', $hop3['message']);
    // The final hop narrates the earlier hops only; its own text is the answer.
    $this->assertSame("Adding the hero banner now.\n\nAdding the heading under the hero now.", $hop3['progress']);
    $this->assertSame([
      'operations' => [
        [
          'operation' => 'ADD',
          'components' => [
            [
              'id' => 'sdc.canvas_test_sdc.heading',
              'uuid' => self::HEADING_UUID,
              'nodePath' => [0, 1],
              'fieldValues' => [
                'text' => 'Why teams choose Canvas',
                'element' => 'h2',
              ],
            ],
          ],
        ],
      ],
    ], ['operations' => $hop3['operations']]);
  }

  /**
   * Turn 2 resumes the agent history turn 1 built, tool messages included.
   *
   * The dev wizard sends the same conversation_id with every turn of one chat.
   * With the site opted in, the backend resumes the agent from the state turn
   * 1 finished with instead of rebuilding it from the client transcript, which
   * carries text only, so the model's first request of turn 2 still holds the
   * edit_components call turn 1 made and its result: the agent need not repeat
   * that work.
   *
   * @see https://git.drupalcode.org/project/canvas/-/work_items/3592032
   */
  public function testSecondTurnResumesTheHistoryOfTheFirst(): void {
    // Off after install: keeping the history costs tokens on every later turn.
    // @see \Drupal\canvas_dev_ai\Form\CanvasDevAiAgentSelectionForm
    $this->config('canvas_dev_ai.settings')->set('keep_tool_calls_in_history', TRUE)->save();

    $layout = [
      'regions' => [
        'content' => [
          'nodePathPrefix' => [0],
          'components' => [
            [
              'name' => 'sdc.canvas_test_sdc.my-hero',
              'uuid' => self::HERO_UUID,
              'props' => ['heading' => 'Build Faster with Canvas', 'cta1href' => 'https://example.com'],
            ],
          ],
        ],
      ],
    ];

    // Turn 1 is the edit turn: hop 1 parks the edit_components call, hop 2
    // executes it and closes the turn.
    // fixtures: tests/resources/ai_test/requests/chat/
    // drupal-canvas-page-agent-edit-hop-1.yml and drupal-canvas-page-agent-edit-hop-2.yml.
    $responses = $this->driveTurn([
      'messages' => [['role' => 'user', 'text' => 'Change the hero heading to Hello']],
      'current_layout' => $layout,
      'request_id' => 'turn-1',
      'conversation_id' => 'conversation-1',
    ]);
    self::assertCount(2, $responses);
    self::assertSame('The hero heading now says Hello.', $responses[1]['message']);

    // Record what the model is sent on the next turn.
    $inputs = [];
    $this->container->get(EventDispatcherInterface::class)->addListener(
      PreGenerateResponseEvent::EVENT_NAME,
      static function (PreGenerateResponseEvent $event) use (&$inputs): void {
        $inputs[] = $event->getInput();
      },
    );

    // Turn 2 sends a new request_id under the same conversation_id, with the
    // transcript the client holds: text only, as the wizard keeps it. The
    // agent parks another edit_components call straight away, without reading
    // the component again; the second hop runs it and closes the turn.
    // fixtures: tests/resources/ai_test/requests/chat/
    // drupal-canvas-page-agent-history-turn-2-hop-1.yml and -hop-2.yml.
    $layout['regions']['content']['components'][0]['props']['heading'] = 'Hello';
    $responses = $this->driveTurn([
      'messages' => [
        ['role' => 'user', 'text' => 'Change the hero heading to Hello'],
        ['role' => 'assistant', 'text' => 'The hero heading now says Hello.'],
        ['role' => 'user', 'text' => 'Now make it say Goodbye'],
      ],
      'current_layout' => $layout,
      'request_id' => 'turn-2',
      'conversation_id' => 'conversation-1',
    ]);
    self::assertCount(2, $responses);
    self::assertSame('Changing the hero heading again now.', $responses[0]['progress']);
    self::assertSame([self::HERO_UUID => ['heading' => 'Goodbye']], $responses[1]['component_updates']);
    self::assertSame('The hero heading now says Goodbye.', $responses[1]['message']);

    // The model's first request of turn 2 carries the history turn 1 built:
    // its user message, the edit_components call, its result and the answer,
    // then this turn's user message. The component catalog is not among them:
    // the agent's default information tools feed the system prompt.
    self::assertCount(2, $inputs);
    $input = $inputs[0];
    self::assertInstanceOf(ChatInput::class, $input);
    $messages = $input->getMessages();
    self::assertSame(
      ['user', 'assistant', 'tool', 'assistant', 'user'],
      \array_map(static fn (ChatMessage $message): string => $message->getRole(), $messages),
    );
    self::assertStringContainsString('Change the hero heading to Hello', $messages[0]->getText());
    self::assertSame('edit_components', $messages[1]->toArray()['tools'][0]['function']['name']);
    self::assertSame('call_1', $messages[2]->getToolsId());
    self::assertStringContainsString('The updates were applied successfully.', $messages[2]->getText());
    self::assertSame('The hero heading now says Hello.', $messages[3]->getText());
    self::assertStringContainsString('Now make it say Goodbye', $messages[4]->getText());

    // Assert that the component catalog is present in the system prompt.
    self::assertStringContainsString('sdc.canvas_test_sdc.banner', $input->getSystemPrompt());
    self::assertStringContainsString('get_component_context', $input->getSystemPrompt());
  }

}
