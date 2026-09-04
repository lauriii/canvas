<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_ai\Kernel;

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
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Tests the canvas_dev_ai AI controller's access, settings and error paths.
 *
 * @see \Drupal\Tests\canvas_ai\Kernel\Agents\CanvasComponentAgentEndToEndTest
 */
#[Group('canvas_ai')]
#[CoversClass(CanvasDevAiBuilder::class)]
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
   * A determineSolvability() failure clears the turn's stored agent state.
   *
   * @see \Drupal\canvas_dev_ai\Controller\CanvasDevAiBuilder::render()
   */
  public function testDetermineSolvabilityFailureClearsStoredAgentState(): void {
    $this->container->get(ModuleInstallerInterface::class)->install(['canvas_dev_ai']);
    $this->refreshContainer();
    $this->setUpAiDevHops();

    // Seed the state a previous hop would have parked, so deletion is
    // observable. The controller resumes it through the agent's fromArray().
    $temp_store = $this->container->get(CanvasAiTempStore::class);
    $temp_store->setStoredAgentState('test-request', ['looped' => FALSE]);
    self::assertNotNull($temp_store->getStoredAgentState('test-request'));

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
  }

  /**
   * A not-solvable response gives the expected error.
   *
   * Any not-solvable response outside max-loop exhaustion triggers this error.
   *
   * @see \Drupal\canvas_dev_ai\Controller\CanvasDevAiBuilder::getNotSolvableMessage()
   * @see \Drupal\Tests\canvas_ai\Kernel\Agents\CanvasDevPageBuilderAgentEndToEndTest::testMaxLoopsOutcomeIsReported()
   * @see \Drupal\Tests\canvas_ai\Kernel\Agents\CanvasComponentAgentEndToEndTest::testMaxLoopsWithoutAConfiguredMessageUsesTheDefault()
   */
  public function testNotSolvableResponseGivesExpectedError(): void {
    $this->container->get(ModuleInstallerInterface::class)->install(['canvas_dev_ai']);
    $this->refreshContainer();
    $this->setUpAiDevHops();

    // The agent gave up on loop 1, well inside the agent's max_loops of 50.
    $agent = $this->createMock(AiAgentEntityWrapper::class);
    $agent->method('determineSolvability')->willReturn(AiAgentInterface::JOB_NOT_SOLVABLE);
    $agent->method('isFinished')->willReturn(TRUE);
    $agent->method('toArray')->willReturn(['looped' => 1]);
    $agent->method('getAiAgentEntity')->willReturnCallback(
      fn () => AiAgent::load('canvas_dev_page_builder_agent'),
    );
    $agent_manager = $this->createMock(AiAgentManager::class);
    $agent_manager->method('hasDefinition')->willReturn(TRUE);
    $agent_manager->method('createInstance')->willReturn($agent);
    $this->container->set('plugin.manager.ai_agents', $agent_manager);

    $response = $this->hop([
      'messages' => [['role' => 'user', 'text' => 'Add a hero']],
    ]);

    self::assertFalse($response['status']);
    self::assertSame('The request could not be completed. Please try again.', $response['message']);
    self::assertFalse($response['should_continue']);
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
