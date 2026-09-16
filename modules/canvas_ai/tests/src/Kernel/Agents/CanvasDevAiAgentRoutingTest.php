<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_ai\Kernel\Agents;

use Drupal\ai_agents\PluginBase\AiAgentEntityWrapper;
use Drupal\canvas_ai\CanvasAiPermissions;
use Drupal\canvas_ai\CanvasAiTempStore;
use Drupal\canvas_dev_ai\Controller\CanvasDevAiBuilder;
use Drupal\Component\Plugin\PluginManagerInterface;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Extension\ModuleInstallerInterface;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\canvas\Kernel\Traits\RequestTrait;
use Drupal\Tests\canvas_ai\Kernel\Traits\CanvasAiDevHopTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Tests the dev AI controller invokes the configured main agent or Tool.
 *
 * The agent plugin manager hands back a mocked AiAgentEntityWrapper and records
 * the ID it was asked for in $requestedAgentId. No agent runs.
 *
 * @see https://git.drupalcode.org/project/canvas/-/work_items/3591777
 */
#[Group('canvas_ai')]
#[CoversClass(CanvasDevAiBuilder::class)]
#[RunTestsInSeparateProcesses]
final class CanvasDevAiAgentRoutingTest extends CanvasKernelTestBase {

  use CanvasAiDevHopTrait;
  use RequestTrait;
  use UserCreationTrait;

  /**
   * The agent ID the controller asked the plugin manager for.
   */
  private ?string $requestedAgentId = NULL;

  /**
   * The agent the plugin manager hands back, whichever ID it is asked for.
   */
  private AiAgentEntityWrapper&MockObject $agentStub;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'canvas_ai',
    'key',
    'ai',
    'ai_agents',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installConfig(['canvas_ai', 'ai', 'ai_agents']);
    $this->installEntitySchema('path_alias');
    // Installed separately to trigger canvas_dev_ai_install().
    $this->container->get(ModuleInstallerInterface::class)->install(['canvas_dev_ai']);
    $container = \Drupal::getContainer();
    \assert($container instanceof ContainerBuilder);
    $this->container = $container;
    $this->setUpCurrentUser(permissions: [CanvasAiPermissions::USE_CANVAS_AI]);
    $this->setUpAiDevHops();

    $this->agentStub = $this->createMock(AiAgentEntityWrapper::class);
    $agent_manager = $this->createMock(PluginManagerInterface::class);
    // Reports TRUE only for the three selectable agent IDs; every other ID is
    // FALSE.
    $agent_manager->method('hasDefinition')->willReturnCallback(
      static fn (string $agent_id): bool => \in_array($agent_id, [
        'canvas_agent',
        'canvas_component_agent',
        'canvas_dev_page_builder_agent',
      ], TRUE),
    );
    // Records the agent ID the controller resolved; the stub stands in for it.
    $agent_manager->method('createInstance')->willReturnCallback(
      function (string $agent_id): AiAgentEntityWrapper {
        $this->requestedAgentId = $agent_id;
        return $this->agentStub;
      },
    );
    $this->container->set('plugin.manager.ai_agents', $agent_manager);
  }

  /**
   * Tests the shipped configuration invokes the Canvas agent.
   *
   * A turn that selects no Tool goes to the agent that answers questions and
   * names the Tool to use, rather than to any agent that performs work.
   */
  public function testDefaultConfiguration(): void {
    $this->hop(['messages' => [['role' => 'user', 'text' => 'Routing test.']]]);

    $this->assertSame('canvas_agent', $this->requestedAgentId);
  }

  /**
   * Tests a request without a Tool invokes the configured main agent.
   */
  public function testCustomMainAgentIsInvoked(): void {
    $this->config('canvas_dev_ai.settings')
      ->set('main_agent', 'canvas_dev_page_builder_agent')
      ->save();

    $this->hop(['messages' => [['role' => 'user', 'text' => 'Routing test.']]]);

    $this->assertSame('canvas_dev_page_builder_agent', $this->requestedAgentId);
  }

  /**
   * Tests a request carrying a configured Tool invokes that Tool.
   *
   * The Tool is invoked whichever agent the configuration names as the main
   * one, so the second request repeats the first against a changed main agent.
   */
  public function testToolIsInvoked(): void {
    $this->hop([
      'messages' => [['role' => 'user', 'text' => 'Routing test.']],
      'selected_tool' => 'canvas_dev_page_builder_agent',
    ]);

    $this->assertSame('canvas_dev_page_builder_agent', $this->requestedAgentId);

    $this->config('canvas_dev_ai.settings')
      ->set('main_agent', 'canvas_component_agent')
      ->save();
    $this->requestedAgentId = NULL;

    $this->hop([
      'messages' => [['role' => 'user', 'text' => 'Routing test.']],
      'selected_tool' => 'canvas_dev_page_builder_agent',
    ]);

    $this->assertSame('canvas_dev_page_builder_agent', $this->requestedAgentId);
  }

  /**
   * Tests a request carrying the main agent as its Tool is rejected.
   */
  public function testMainAgentAsToolIsNotAllowed(): void {
    $response = $this->hop([
      'messages' => [['role' => 'user', 'text' => 'Routing test.']],
      'selected_tool' => 'canvas_agent',
    ]);

    $this->assertNull($this->requestedAgentId);
    $this->assertStringContainsString('not allowed', $response['message']);
  }

  /**
   * Tests a request that resolves to no agent at all is rejected.
   */
  public function testUnresolvedAgentIsRejected(): void {
    $this->config('canvas_dev_ai.settings')->delete();

    $response = $this->hop(['messages' => [['role' => 'user', 'text' => 'Routing test.']]]);

    $this->assertNull($this->requestedAgentId);
    $this->assertStringContainsString('Unable to resolve', $response['message']);
  }

  /**
   * Tests a request resolving an agent without a plugin definition is rejected.
   */
  public function testAgentWithoutDefinitionIsRejected(): void {
    $this->config('canvas_dev_ai.settings')
      ->set('main_agent', 'canvas_title_generation_agent')
      ->save();

    $response = $this->hop(['messages' => [['role' => 'user', 'text' => 'Routing test.']]]);

    $this->assertNull($this->requestedAgentId);
    $this->assertFalse($response['status']);
    $this->assertStringContainsString('does not exist', $response['message']);
  }

  /**
   * Tests a hop that resolves a different agent than the turn's is rejected.
   *
   * @param string|null $second_tool
   *   The Tool the hop sends, or NULL to send none and resolve the main agent.
   */
  #[DataProvider('providerToolChange')]
  public function testToolCannotChangeDuringTurn(?string $second_tool): void {
    // Park a turn under the component agent, as a previous hop sending it as
    // the Tool would have.
    $temp_store = $this->container->get(CanvasAiTempStore::class);
    $temp_store->setStoredAgentState('test-request', 'canvas_component_agent', ['looped' => FALSE]);

    $prompt = ['messages' => [['role' => 'user', 'text' => 'Routing test.']]];
    if ($second_tool !== NULL) {
      $prompt['selected_tool'] = $second_tool;
    }
    $response = $this->hop($prompt);

    $this->assertNull($this->requestedAgentId);
    $this->assertFalse($response['status']);
    $this->assertFalse($response['should_continue']);
    $this->assertSame('The selected tool cannot change during a turn.', $response['message']);
    // The turn is over, so its parked state is dropped.
    $this->assertNull($temp_store->getStoredAgentState('test-request'));
  }

  /**
   * Data provider for ::testToolCannotChangeDuringTurn().
   *
   * @return array<string, array{string|null}>
   *   The Tool the hop sends.
   */
  public static function providerToolChange(): array {
    return [
      'another Tool' => ['canvas_dev_page_builder_agent'],
      'no Tool' => [NULL],
    ];
  }

  /**
   * Tests a hop carrying the Tool the turn started with resumes it.
   */
  public function testSameToolContinuesTheTurn(): void {
    $temp_store = $this->container->get(CanvasAiTempStore::class);
    $temp_store->setStoredAgentState('test-request', 'canvas_component_agent', ['looped' => FALSE]);
    // The parked state is restored into the agent that parked it.
    $this->agentStub->expects($this->once())
      ->method('fromArray')
      ->with(['looped' => FALSE]);

    $response = $this->hop([
      'messages' => [['role' => 'user', 'text' => 'Routing test.']],
      'selected_tool' => 'canvas_component_agent',
    ]);

    $this->assertSame('canvas_component_agent', $this->requestedAgentId);
    $this->assertStringNotContainsString('cannot change', $response['message']);
  }

}
