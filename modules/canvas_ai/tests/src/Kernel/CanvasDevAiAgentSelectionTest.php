<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_ai\Kernel;

use Drupal\canvas_dev_ai\Form\CanvasDevAiAgentSelectionForm;
use Drupal\canvas_dev_ai\Hook\CanvasDevAiHooks;
use Drupal\Core\Asset\AttachedAssets;
use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Extension\ModuleInstallerInterface;
use Drupal\Core\Render\BubbleableMetadata;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the Agents & Tools settings form and the Tools it exposes.
 *
 * The same configured Tools reach the front end as JavaScript settings and an
 * agent's system prompt as a token, so both are covered here.
 */
#[Group('canvas_ai')]
#[CoversClass(CanvasDevAiAgentSelectionForm::class)]
#[CoversClass(CanvasDevAiHooks::class)]
final class CanvasDevAiAgentSelectionTest extends CanvasKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'canvas_ai',
    'ai',
    'ai_agents',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // Uninstalling any module fires user_module_uninstall(), which deletes from
    // the users_data table. Kernel tests do not create it unless asked.
    $this->installSchema('user', ['users_data']);
    // The ai_agent entities the form filters on ship in canvas_ai's
    // config/install. CanvasKernelTestBase installs config for 'system' and
    // 'canvas' only, so without this the agents do not exist and the Tools
    // payload is silently empty.
    $this->installConfig(['canvas_ai']);
  }

  /**
   * Tests that the selected Tools are exposed to JavaScript.
   */
  public function testToolsAreExposedToJs(): void {
    $this->container->get(ModuleInstallerInterface::class)->install(['canvas_dev_ai']);
    $this->refreshContainer();

    $this->config('canvas_dev_ai.settings')
      ->set('tools', ['canvas_component_agent', 'canvas_page_builder_agent'])
      ->save();

    $settings = $this->alterJsSettings();
    $this->assertArrayHasKey('ai', $settings['canvas']);
    $tools = $settings['canvas']['ai']['tools'];
    $this->assertCount(2, $tools);

    // The labels and descriptions must come from the ai_agent entities, not
    // from config. Compare against the entities rather than hard-coded strings,
    // otherwise this would still pass if they had been copied into config.
    $storage = $this->agentStorage();
    $expected_ids = ['canvas_component_agent', 'canvas_page_builder_agent'];

    foreach ($expected_ids as $index => $id) {
      $agent = $storage->load($id);
      $this->assertInstanceOf(ConfigEntityInterface::class, $agent);
      $this->assertSame($id, $tools[$index]['id']);
      $this->assertSame((string) $agent->label(), $tools[$index]['label']);
      $this->assertSame((string) $agent->get('description'), $tools[$index]['description']);
    }

    // The main agent is deliberately not sent to the front end.
    $this->assertArrayNotHasKey('main_agent', $settings['canvas']['ai']);
  }

  /**
   * Tests that no tools key is emitted when no Tools are selected.
   */
  public function testNoToolsKeyWhenNoneSelected(): void {
    $this->container->get(ModuleInstallerInterface::class)->install(['canvas_dev_ai']);
    $this->refreshContainer();

    $this->config('canvas_dev_ai.settings')->set('tools', [])->save();

    $settings = $this->alterJsSettings();
    $this->assertTrue($settings['canvas']['aiDevMode']);
    $this->assertArrayNotHasKey('tools', $settings['canvas']['ai'] ?? []);
  }

  /**
   * Tests that uninstalling removes the config object and the JS key.
   *
   * The local task's availability and removal are covered by
   * \Drupal\Tests\canvas_ai\Functional\Form\CanvasDevAiAgentSelectionFormTest,
   * because enumerating local task definitions in a kernel test triggers a PHP
   * warning from core's views local-task deriver, whose routes are not built.
   */
  public function testUninstallRemovesEverything(): void {
    $module_installer = $this->container->get(ModuleInstallerInterface::class);

    $module_installer->install(['canvas_dev_ai']);
    $this->refreshContainer();

    // Present while installed.
    $this->assertFalse($this->config('canvas_dev_ai.settings')->isNew());
    $this->assertInstanceOf(ConfigEntityInterface::class, $this->agentStorage()->load('canvas_agent'));
    $this->assertArrayHasKey('tools', $this->alterJsSettings()['canvas']['ai']);

    $module_installer->uninstall(['canvas_dev_ai']);
    $this->refreshContainer();

    // Gone once uninstalled. Uninstalling a module deletes the config objects
    // prefixed with its name, so the settings object disappears with it.
    $this->assertTrue($this->config('canvas_dev_ai.settings')->isNew());
    $this->assertArrayNotHasKey('tools', $this->alterJsSettings()['canvas']['ai'] ?? []);
    // The agent declares an enforced dependency on canvas_dev_ai, so removing
    // the feature flag leaves no agent behind.
    $this->assertNull($this->agentStorage()->load('canvas_agent'));
  }

  /**
   * Tests that the Canvas agent ships without tools.
   */
  public function testCanvasAgentShipsWithoutTools(): void {
    $this->container->get(ModuleInstallerInterface::class)->install(['canvas_dev_ai']);
    $this->refreshContainer();

    $agent = $this->agentStorage()->load('canvas_agent');
    $this->assertInstanceOf(ConfigEntityInterface::class, $agent);
    // The user selects the Tool, so this agent answers questions rather than
    // delegating. Carrying tools would make it delegate again.
    $this->assertSame([], $agent->get('tools'));
    $this->assertFalse($agent->get('orchestration_agent'));
    // The prompt must carry the token. The token tests replace it in
    // isolation and would still pass if the YAML dropped this string.
    $this->assertStringContainsString('[canvas_dev_ai:available_tools]', (string) $agent->get('system_prompt'));
  }

  /**
   * Tests that the Canvas agent is not offered as a Tool.
   *
   * It describes the Tools, so offering it as one would let it describe itself.
   */
  public function testCanvasAgentIsNotShippedAsTool(): void {
    $this->container->get(ModuleInstallerInterface::class)->install(['canvas_dev_ai']);
    $this->refreshContainer();

    $this->assertNotContains('canvas_agent', $this->config('canvas_dev_ai.settings')->get('tools'));
  }

  /**
   * Tests that the token renders the configured Tools.
   */
  public function testAvailableToolsToken(): void {
    $this->container->get(ModuleInstallerInterface::class)->install(['canvas_dev_ai']);
    $this->refreshContainer();

    $this->config('canvas_dev_ai.settings')
      ->set('tools', ['canvas_component_agent'])
      ->save();

    $bubbleable_metadata = new BubbleableMetadata();
    $rendered = $this->replaceToolsToken($bubbleable_metadata);

    // The label and description must come from the ai_agent entity, so that
    // the prompt cannot describe a Tool differently from the chat.
    $agent = $this->agentStorage()->load('canvas_component_agent');
    $this->assertInstanceOf(ConfigEntityInterface::class, $agent);
    $this->assertStringContainsString(
      \sprintf('* **%s** (enabled): %s', $agent->label(), $agent->get('description')),
      $rendered,
    );
    // An available Tool the site has not enabled is still described, so the
    // agent can tell the user to enable it rather than deny the task.
    $disabled = $this->agentStorage()->load('canvas_page_builder_agent');
    $this->assertInstanceOf(ConfigEntityInterface::class, $disabled);
    $this->assertStringContainsString(
      \sprintf('* **%s** (disabled): %s', $disabled->label(), $disabled->get('description')),
      $rendered,
    );
    // The agent describing the Tools is never one of them.
    $this->assertStringNotContainsString('Drupal Canvas Agent', $rendered);

    // The replacement is built from the settings and the agents it describes,
    // so both must bubble; without them, anything caching the rendered output
    // would keep serving a stale Tool list after the config changes.
    $cache_tags = $bubbleable_metadata->getCacheTags();
    $this->assertContains('config:canvas_dev_ai.settings', $cache_tags);
    $this->assertContains('config:ai_agents.ai_agent.canvas_component_agent', $cache_tags);
  }

  /**
   * Tests that the token renders empty when no Tool is offered.
   */
  public function testAvailableToolsTokenWithoutTools(): void {
    $this->container->get(ModuleInstallerInterface::class)->install(['canvas_dev_ai']);
    $this->refreshContainer();

    $this->config('canvas_dev_ai.settings')->set('tools', [])->save();

    // Enabling nothing does not make the Tools unavailable: every available
    // Tool is still described, marked disabled, so the agent can point the
    // user at the setting rather than claim the task is impossible.
    $bubbleable_metadata = new BubbleableMetadata();
    $rendered = $this->replaceToolsToken($bubbleable_metadata);
    $this->assertStringContainsString('(disabled)', $rendered);
    $this->assertStringNotContainsString('(enabled)', $rendered);
    // The raw token must not survive into the prompt, otherwise the agent
    // reads it as literal text.
    $this->assertStringNotContainsString('[canvas_dev_ai:available_tools]', $rendered);
    // The empty list is still derived from the settings: offering a Tool
    // later must invalidate whatever cached the empty rendering.
    $this->assertContains('config:canvas_dev_ai.settings', $bubbleable_metadata->getCacheTags());
  }

  /**
   * Tests that a Tool whose agent carries no description still renders.
   *
   * The ai_agent schema does not require a description, so an imported agent
   * record may omit the key. The entity API cannot save such a record, so it
   * is written as raw config, the way an import would. The list entry must
   * degrade to the label rather than fail rendering.
   */
  public function testAvailableToolsTokenWithoutDescription(): void {
    $this->container->get(ModuleInstallerInterface::class)->install(['canvas_dev_ai']);
    $this->refreshContainer();

    // The record replaces a selectable agent, because only those are described.
    // AiAgent::$description is a typed string property, so the entity API
    // rejects a record without one, but a raw config record loads and get()
    // then returns NULL.
    $this->config('ai_agents.ai_agent.canvas_component_agent')
      ->setData([
        'langcode' => 'en',
        'status' => TRUE,
        'dependencies' => [],
        'id' => 'canvas_component_agent',
        'label' => 'No Description Agent',
        'system_prompt' => 'Answer the question.',
        'orchestration_agent' => FALSE,
        'triage_agent' => FALSE,
        'max_loops' => 3,
      ])
      ->save();
    $this->config('canvas_dev_ai.settings')
      ->set('tools', ['canvas_component_agent'])
      ->save();

    $this->assertStringContainsString(
      '* **No Description Agent** (enabled): ',
      $this->replaceToolsToken(),
    );
  }

  /**
   * Tests that a Tool naming a deleted agent is skipped.
   *
   * The ConfigExists constraint only validates the settings as they are
   * saved, and the settings carry no dependency on the agents they name, so
   * deleting an offered agent leaves its ID behind. The stale ID must reach
   * neither the prompt nor the front end.
   */
  public function testStaleToolIdIsSkipped(): void {
    $this->container->get(ModuleInstallerInterface::class)->install(['canvas_dev_ai']);
    $this->refreshContainer();

    $this->config('canvas_dev_ai.settings')
      ->set('tools', ['canvas_component_agent', 'canvas_page_builder_agent'])
      ->save();
    $stale = $this->agentStorage()->load('canvas_component_agent');
    $this->assertInstanceOf(ConfigEntityInterface::class, $stale);
    $stale_label = (string) $stale->label();
    $stale->delete();

    // A deleted agent is described nowhere, even though its ID is still
    // listed as selectable.
    $rendered = $this->replaceToolsToken();
    $this->assertStringNotContainsString($stale_label, $rendered);

    $survivor = $this->agentStorage()->load('canvas_page_builder_agent');
    $this->assertInstanceOf(ConfigEntityInterface::class, $survivor);
    $this->assertStringContainsString(
      \sprintf('* **%s** (enabled): %s', $survivor->label(), $survivor->get('description')),
      $rendered,
    );

    $tools = $this->alterJsSettings()['canvas']['ai']['tools'];
    $this->assertCount(1, $tools);
    $this->assertSame('canvas_page_builder_agent', $tools[0]['id']);
  }

  /**
   * Tests that nothing renders when every offered Tool is stale.
   */
  public function testAllToolIdsStale(): void {
    $this->container->get(ModuleInstallerInterface::class)->install(['canvas_dev_ai']);
    $this->refreshContainer();

    $this->config('canvas_dev_ai.settings')
      ->set('tools', ['canvas_component_agent'])
      ->save();
    // Delete every selectable agent, so nothing is available to describe.
    foreach (CanvasDevAiAgentSelectionForm::SELECTABLE_AGENTS as $id) {
      $agent = $this->agentStorage()->load($id);
      if ($agent instanceof ConfigEntityInterface) {
        $agent->delete();
      }
    }

    // With no agent resolving, the agent is told so in words rather than
    // being handed an empty list it might read as a rendering failure.
    $this->assertSame('There are no tools available.', $this->replaceToolsToken());
    // The front end treats an all-stale list like an empty one: no tools key.
    $this->assertArrayNotHasKey('tools', $this->alterJsSettings()['canvas']['ai'] ?? []);
  }

  /**
   * Replaces the available Tools token the way an agent's system prompt does.
   *
   * @param \Drupal\Core\Render\BubbleableMetadata|null $bubbleable_metadata
   *   (optional) Collects the cacheability the replacement bubbles.
   *
   * @return string
   *   The replaced token.
   */
  private function replaceToolsToken(?BubbleableMetadata $bubbleable_metadata = NULL): string {
    // Mirrors AiAgentEntityWrapper::applyTokens(), which renders an agent's
    // system prompt. It passes no options, so an unreplaced token would
    // survive into the prompt as literal text.
    return $this->container->get('token')
      ->replacePlain('[canvas_dev_ai:available_tools]', [], [], $bubbleable_metadata);
  }

  /**
   * Returns the ai_agent entity storage.
   */
  private function agentStorage(): EntityStorageInterface {
    return $this->container->get(EntityTypeManagerInterface::class)->getStorage('ai_agent');
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
   *
   * @return array
   *   The altered settings array.
   */
  private function alterJsSettings(): array {
    $settings = ['canvas' => ['aiExtensionAvailable' => TRUE]];
    $assets = new AttachedAssets();
    $this->container->get(ModuleHandlerInterface::class)->alter('js_settings', $settings, $assets);
    return $settings;
  }

}
