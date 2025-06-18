<?php

declare(strict_types=1);

namespace Drupal\Tests\xb_ai\Kernel\Plugin\AiFunctionCall;

use Drupal\Component\Serialization\Json;
use Drupal\KernelTests\KernelTestBase;
use Drupal\ai\Service\FunctionCalling\ExecutableFunctionCallInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * Tests for the EditComponentJs function call plugin.
 *
 * @group xb_ai
 */
final class EditComponentJsTest extends KernelTestBase {

  /**
   * The function call plugin manager.
   *
   * @var \Drupal\Component\Plugin\PluginManagerInterface
   */
  protected $functionCallManager;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'ai',
    'ai_agents',
    'experience_builder',
    'system',
    'user',
    'xb_ai',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->functionCallManager = $this->container->get('plugin.manager.ai.function_calls');
  }

  /**
   * Test editing component JavaScript successfully.
   */
  public function testEditComponentJs(): void {
    $tool = $this->functionCallManager->createInstance('ai_agent:edit_component_js');
    $this->assertInstanceOf(ExecutableFunctionCallInterface::class, $tool);

    $js_content = 'console.log("Hello World"); const component = { init: () => {} };';
    $props_metadata = Json::encode([
      [
        'id' => 'title',
        'name' => 'Title',
        'type' => 'string',
        'example' => 'Sample Title',
      ],
      [
        'id' => 'count',
        'name' => 'Count',
        'type' => 'number',
        'example' => 5,
      ],
    ]);

    $tool->setContextValue('javascript', $js_content);
    $tool->setContextValue('props_metadata', $props_metadata);
    $tool->execute();
    $result = $tool->getReadableOutput();

    $this->assertIsString($result);
    $parsed_result = Yaml::parse($result);

    $this->assertArrayHasKey('js_structure', $parsed_result);
    $this->assertArrayHasKey('props_metadata', $parsed_result);
    $this->assertEquals($js_content, $parsed_result['js_structure']);
    $this->assertEquals($props_metadata, $parsed_result['props_metadata']);
  }

}
