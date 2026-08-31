<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_ai\Kernel\Agents;

use Drupal\canvas\Entity\JavaScriptComponent;
use Drupal\canvas_ai\CanvasAiPermissions;
use Drupal\canvas_dev_ai\Controller\CanvasDevAiBuilder;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\canvas\Kernel\Traits\RequestTrait;
use Drupal\Tests\canvas_ai\Kernel\Traits\CanvasAiDevHopTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests canvas_component_agent turns driven through the dev AI controller.
 *
 * Provider responses come from the ai module's echoai provider, which matches
 * each hop's request against a recorded fixture under
 * tests/resources/ai_test/requests/chat.
 *
 * @see \Drupal\ai_test\Plugin\AiProvider\EchoProvider::getMatchingRequest()
 * @see https://git.drupalcode.org/project/canvas/-/work_items/3591777
 */
#[Group('canvas_ai')]
#[CoversClass(CanvasDevAiBuilder::class)]
#[RunTestsInSeparateProcesses]
final class CanvasComponentAgentEndToEndTest extends CanvasKernelTestBase {

  use CanvasAiDevHopTrait;
  use RequestTrait;
  use UserCreationTrait;

  /**
   * The 1x1 PNG the attachment test sends, as its fixtures carry it.
   */
  private const PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAADElEQVR4nGP4z8AAAAMBAQDJ/pLvAAAAAElFTkSuQmCC';

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
  protected function setUp(): void {
    parent::setUp();

    $this->installConfig(['canvas_ai', 'canvas_dev_ai', 'ai', 'ai_agents', 'ai_test']);
    // The echoai provider reads the ai_mock_provider_result table before the
    // file fixtures this test drives it from.
    $this->installEntitySchema('ai_mock_provider_result');
    $this->installEntitySchema('path_alias');
    $this->setUpCurrentUser(permissions: [CanvasAiPermissions::USE_CANVAS_AI]);
    $this->setUpAiDevHops();

    $this->config('ai.settings')
      ->set('default_providers.chat', [
        'provider_id' => 'echoai',
        'model_id' => 'gpt-test',
      ])
      ->save();
  }

  /**
   * A text-only provider response ends the turn and answers the user.
   *
   * The response parks no tool call, so the agent finishes on its first hop: the
   * controller returns the answer as `message` and does not ask for another hop.
   */
  public function testTextOnlyResponseAnswersInOneHop(): void {
    // fixture: tests/resources/ai_test/requests/chat/component-agent-capabilities-question.yml.
    $hops = $this->driveTurn([
      'messages' => [['role' => 'user', 'text' => 'Hi what can you do']],
    ]);

    $this->assertCount(1, $hops);
    $this->assertTrue($hops[0]['status']);
    $this->assertFalse($hops[0]['should_continue']);
    $this->assertStringContainsString(
      'I can help to create code components.',
      $hops[0]['message'],
    );
  }

  /**
   * A tool-calling response parks the create tool, which runs on the next hop.
   *
   * The turn takes two hops: hop 1 only decides to call the create tool, so it
   * carries no component structure and asks for another hop; hop 2 runs the
   * tool and returns the component it built, plus the answer ending the turn.
   */
  public function testCreateComponentToolRunsOnTheSecondHop(): void {
    // fixtures: tests/resources/ai_test/requests/chat/component-agent-create-button-hop-{1,2}.yml.
    $hops = $this->driveTurn([
      'messages' => [
        ['role' => 'user', 'text' => 'Hi what can you do'],
        ['role' => 'assistant', 'text' => 'I can help to create code components.'],
        ['role' => 'user', 'text' => 'Create a red button code component'],
      ],
    ]);

    $this->assertCount(2, $hops);
    // Hop 1 reports the sentence the agent said while deciding, and no component.
    $this->assertTrue($hops[0]['should_continue']);
    $this->assertArrayNotHasKey('component_structure', $hops[0]);
    $this->assertSame('I am creating the Red Button component now.', $hops[0]['progress']);

    $this->assertFalse($hops[1]['should_continue']);
    $this->assertSame('I created the Red Button component for you.', $hops[1]['message']);
    // Only the create tool produces a component_structure.
    $component = $hops[1]['component_structure'];
    $this->assertSame('Red Button', $component['name']);
    $this->assertSame('red_button', $component['machineName']);
    $this->assertSame(
      <<<'JS'
      export default function RedButton({ buttonText }) {
        return <button className="bg-red-600 text-white">{buttonText}</button>;
      }

      JS,
      $component['sourceCodeJs'],
    );
    // The tool reshapes the props metadata the agent sent into the props of the
    // component it builds.
    $this->assertSame([
      'buttonText' => [
        'title' => 'Button Text',
        'type' => 'string',
        'examples' => ['Click me'],
      ],
    ], $component['props']);
  }

  /**
   * Editing an open component loads it first, then edits it: three hops.
   *
   * Each hop parks one call, so the load tool runs on hop 2 and the edit tool on
   * hop 3, which also carries the answer ending the turn.
   */
  public function testEditComponentJsToolRunsOnTheThirdHop(): void {
    JavaScriptComponent::create([
      'machineName' => 'red_button',
      'name' => 'Red Button',
      'status' => FALSE,
      'props' => [
        'buttonText' => [
          'title' => 'Button Text',
          'type' => 'string',
          'examples' => ['Click me'],
        ],
      ],
      'required' => [],
      'slots' => [],
      'js' => ['original' => "export default function RedButton({ buttonText }) {\n  return <button className=\"bg-red-600 text-white\">{buttonText}</button>;\n}\n", 'compiled' => ''],
      'css' => ['original' => '', 'compiled' => ''],
    ])->save();

    // fixtures: tests/resources/ai_test/requests/chat/component-agent-edit-button-hop-{1,2,3}.yml.
    $hops = $this->driveTurn([
      'messages' => [['role' => 'user', 'text' => 'Change button text to uppercase']],
      'selected_component' => 'red_button',
      'selected_component_required_props' => [],
    ]);

    $this->assertCount(3, $hops);
    $this->assertTrue($hops[0]['should_continue']);
    $this->assertSame('I am loading the Red Button component to make its text uppercase.', $hops[0]['progress']);
    $this->assertTrue($hops[1]['should_continue']);
    $this->assertSame(
      "I am loading the Red Button component to make its text uppercase.\n\nI am updating the Red Button component to render its text in uppercase.",
      $hops[1]['progress'],
    );

    // Only the edit tool produces a js_structure.
    $this->assertFalse($hops[2]['should_continue']);
    $this->assertSame('Updated the button so its text now renders in uppercase.', $hops[2]['message']);
    // The final hop's progress narrates the earlier hops only; its own text is
    // returned as the message.
    $this->assertSame(
      "I am loading the Red Button component to make its text uppercase.\n\nI am updating the Red Button component to render its text in uppercase.",
      $hops[2]['progress'],
    );
    $this->assertSame(
      <<<'JS'
      export default function RedButton({ buttonText }) {
        return <button className="bg-red-600 text-white uppercase">{buttonText}</button>;
      }

      JS,
      $hops[2]['js_structure'],
    );
    $this->assertSame(
      '{"buttonText":{"title":"Button Text","type":"string","examples":["Click me"]}}',
      $hops[2]['props_metadata'],
    );
  }

  /**
   * An attached image reaches the model, and is still there when the tool runs.
   *
   * The chat sends an attachment as a data URI on the message carrying it, which
   * the chat helper decodes back into an image on the chat history it hands the
   * agent. Both hop fixtures carry that image on the first message.
   *
   * @see \Drupal\canvas_ai\CanvasAiChatHelper::getFilteredChatHistory()
   */
  public function testCreateComponentFromAnAttachedImage(): void {
    // fixtures: tests/resources/ai_test/requests/chat/component-agent-create-from-image-hop-{1,2}.yml.
    $hops = $this->driveTurn([
      'messages' => [
        [
          'role' => 'user',
          'text' => 'Make a component out of this',
          'files' => [['src' => 'data:image/png;base64,' . self::PNG_BASE64]],
        ],
        ['role' => 'assistant', 'text' => 'I can build that as a code component.'],
        ['role' => 'user', 'text' => 'Create this component'],
      ],
    ]);

    $this->assertCount(2, $hops);
    $this->assertTrue($hops[0]['should_continue']);
    $this->assertArrayNotHasKey('component_structure', $hops[0]);
    $this->assertSame('I am creating the CTA Card component from your image now.', $hops[0]['progress']);

    $this->assertFalse($hops[1]['should_continue']);
    $this->assertSame('I created the CTA Card component from your image.', $hops[1]['message']);
    $component = $hops[1]['component_structure'];
    $this->assertSame('CTA Card', $component['name']);
    $this->assertSame('cta_card', $component['machineName']);
    $this->assertSame(
      <<<'JS'
      export default function CtaCard({ heading }) {
        return <section className="bg-blue-600 text-white"><h2>{heading}</h2></section>;
      }

      JS,
      $component['sourceCodeJs'],
    );
    $this->assertSame([
      'heading' => [
        'title' => 'Heading',
        'type' => 'string',
        'examples' => ['Ready to get started?'],
      ],
    ], $component['props']);
  }

  /**
   * A component agent sent as 'selected_tool' runs instead of the main agent.
   */
  public function testSelectedToolAgentRunsEveryHop(): void {
    // Set another agent as the main agent.
    $this->config('canvas_dev_ai.settings')
      ->set('main_agent', 'canvas_ai_orchestrator')
      ->set('tools', ['canvas_component_agent'])
      ->save();
    JavaScriptComponent::create([
      'machineName' => 'red_button',
      'name' => 'Red Button',
      'status' => FALSE,
      'props' => [
        'buttonText' => [
          'title' => 'Button Text',
          'type' => 'string',
          'examples' => ['Click me'],
        ],
      ],
      'required' => [],
      'slots' => [],
      'js' => ['original' => "export default function RedButton({ buttonText }) {\n  return <button className=\"bg-red-600 text-white\">{buttonText}</button>;\n}\n", 'compiled' => ''],
      'css' => ['original' => '', 'compiled' => ''],
    ])->save();

    // fixtures: tests/resources/ai_test/requests/chat/component-agent-edit-button-hop-{1,2,3}.yml.
    // Send a request with canvas_component_agent as the selected tool.
    $hops = $this->driveTurn([
      'messages' => [['role' => 'user', 'text' => 'Change button text to uppercase']],
      'selected_component' => 'red_button',
      'selected_component_required_props' => [],
      'selected_tool' => 'canvas_component_agent',
    ]);

    // Ensure the component agent ran: its progress narration names the load
    // and edit tools it used, one per hop.
    $this->assertCount(3, $hops);
    $this->assertSame('I am loading the Red Button component to make its text uppercase.', $hops[0]['progress']);
    $this->assertSame(
      "I am loading the Red Button component to make its text uppercase.\n\nI am updating the Red Button component to render its text in uppercase.",
      $hops[1]['progress'],
    );
  }

  /**
   * Running out of loops falls back to a default message for this agent.
   *
   * This agent ships without a max_loops_message, unlike the dev page builder
   * agent, so the controller supplies its own text.
   *
   * @see \Drupal\canvas_dev_ai\Controller\CanvasDevAiBuilder::getNotSolvableMessage()
   */
  public function testMaxLoopsWithoutAConfiguredMessageUsesTheDefault(): void {
    $agent = $this->config('ai_agents.ai_agent.canvas_component_agent');
    self::assertSame('', $agent->get('max_loops_message'));
    // The budget is exhausted before the first provider call, so no fixture is
    // needed for this turn.
    $agent->set('max_loops', 0)->save();

    $response = $this->hop([
      'messages' => [['role' => 'user', 'text' => 'Make a red button']],
    ]);

    $this->assertFalse($response['status']);
    $this->assertFalse($response['should_continue']);
    $this->assertSame('I was unable to fully answer your question within the allowed number of processing steps. Please try rephrasing or narrowing your question.', $response['message']);
  }

}
