<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_ai\Kernel\Agents;

use Drupal\ai\Service\FunctionCalling\ExecutableFunctionCallInterface;
use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleInstallerInterface;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\Yaml\Yaml;

/**
 * Tests the canvas_dev_page_builder_agent config entity and its tools.
 */
#[Group('canvas_ai')]
#[RunTestsInSeparateProcesses]
final class CanvasDevPageBuilderAgentConfigTest extends CanvasKernelTestBase {

  private const AGENT_ID = 'canvas_dev_page_builder_agent';

  private const AGENT_CONFIG_NAME = 'ai_agents.ai_agent.canvas_dev_page_builder_agent';

  /**
   * The tools the agent must have enabled.
   */
  private const AGENT_TOOLS = [
    'canvas_ai:place_components',
    'canvas_ai:edit_components',
    'canvas_ai:get_component_details',
    'canvas_ai:set_page_value',
  ];

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'ai',
    'ai_agents',
    'canvas_ai',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['canvas_ai']);
    // A real module install, so the agent entity ships from config/install.
    $this->container->get(ModuleInstallerInterface::class)->install(['canvas_dev_ai']);
    $container = \Drupal::getContainer();
    \assert($container instanceof ContainerBuilder);
    $this->container = $container;
  }

  /**
   * Tests the shipped agent entity's wiring.
   */
  public function testAgentEntityWiring(): void {
    $agent = $this->container->get(EntityTypeManagerInterface::class)
      ->getStorage('ai_agent')
      ->load(self::AGENT_ID);
    $this->assertInstanceOf(ConfigEntityInterface::class, $agent, 'The dev page builder agent entity exists.');

    $this->assertSame('Drupal Canvas Dev Page Builder Agent', (string) $agent->label());
    $this->assertSame(50, $agent->get('max_loops'));
    $this->assertFalse($agent->get('orchestration_agent'));
    $this->assertFalse($agent->get('triage_agent'));

    $tools = \array_keys(array_filter($agent->get('tools')));
    sort($tools);
    $expected = self::AGENT_TOOLS;
    sort($expected);
    $this->assertSame($expected, $tools);

    // Only one place_components call is allowed per model response.
    $place_settings = $agent->get('tool_settings')['canvas_ai:place_components'];
    $this->assertNotEmpty($place_settings['restrict_multiple_calls']);
    $this->assertSame('This tool can only be called once to place multiple components at multiple places, provide different operation lists.', $place_settings['multiple_call_error_message']);
    foreach (['canvas_ai:edit_components', 'canvas_ai:get_component_details', 'canvas_ai:set_page_value'] as $tool_id) {
      $this->assertEmpty($agent->get('tool_settings')[$tool_id]['restrict_multiple_calls']);
    }

    // The catalog is injected once on the first hop; the layout on every hop.
    $information_tools = Yaml::parse($agent->get('default_information_tools'));
    $catalog = $information_tools['available_components'];
    $this->assertSame('canvas_ai:get_component_context', $catalog['tool']);
    $this->assertSame(['catalog_only' => TRUE], $catalog['parameters']);
    $this->assertSame([1], $catalog['available_on_loop']);
    $layout = $information_tools['current_layout'];
    $this->assertSame('canvas_ai:get_current_layout', $layout['tool']);
    $this->assertArrayNotHasKey('available_on_loop', $layout);
  }

  /**
   * Tests that every tool the agent wires resolves to a function call plugin.
   */
  public function testToolPluginsAreRegistered(): void {
    $manager = $this->container->get('plugin.manager.ai.function_calls');
    $information_tools = ['canvas_ai:get_component_context', 'canvas_ai:get_current_layout'];
    foreach ([...self::AGENT_TOOLS, ...$information_tools] as $plugin_id) {
      $tool = $manager->createInstance($plugin_id);
      $this->assertInstanceOf(ExecutableFunctionCallInterface::class, $tool, "Tool $plugin_id resolves.");
    }

    // The catalog switch the agent's information tool relies on.
    $context_definitions = $manager->getDefinition('canvas_ai:get_component_context')['context_definitions'];
    $this->assertArrayHasKey('catalog_only', $context_definitions);
    $this->assertSame('boolean', $context_definitions['catalog_only']->getDataType());
    $this->assertFalse($context_definitions['catalog_only']->isRequired());
  }

  /**
   * Tests the shipped agent config validates against the ai_agents schema.
   */
  public function testAgentConfigSchemaValidates(): void {
    $data = $this->config(self::AGENT_CONFIG_NAME)->get();
    $violations = $this->container->get(TypedConfigManagerInterface::class)
      ->createFromNameAndData(self::AGENT_CONFIG_NAME, $data)
      ->validate();
    $this->assertCount(0, $violations, (string) $violations);
  }

}
