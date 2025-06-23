<?php

declare(strict_types=1);

namespace Drupal\Tests\xb_ai\Kernel\Plugin\AiFunctionCall;

use Drupal\Component\Serialization\Json;
use Drupal\KernelTests\KernelTestBase;
use Drupal\ai\Service\FunctionCalling\ExecutableFunctionCallInterface;
use Drupal\experience_builder\Entity\JavaScriptComponent;
use Symfony\Component\Yaml\Yaml;

/**
 * Tests for the CreateComponent function call plugin.
 *
 * @group xb_ai
 */
final class CreateComponentTest extends KernelTestBase {

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
   * Test creating a new component successfully.
   */
  public function testCreateNewComponent(): void {
    $tool = $this->functionCallManager->createInstance('ai_agent:create_component');
    $this->assertInstanceOf(ExecutableFunctionCallInterface::class, $tool);

    $component_name = 'Test Component';
    $javascript = 'console.log("Hello World");';
    $css = '.test { color: red; }';
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

    $tool->setContextValue('component_name', $component_name);
    $tool->setContextValue('js_structure', $javascript);
    $tool->setContextValue('css_structure', $css);
    $tool->setContextValue('props_metadata', $props_metadata);
    $tool->execute();
    $result = $tool->getReadableOutput();
    $this->assertIsString($result);
    $parsed_result = Yaml::parse($result);

    $this->assertArrayHasKey('component_structure', $parsed_result);
    $component_structure = $parsed_result['component_structure'];
    $this->assertEquals($component_name, $component_structure['name']);
    $this->assertEquals('test_component', $component_structure['machineName']);
    $this->assertFalse($component_structure['status']);
    $this->assertEquals($javascript, $component_structure['sourceCodeJs']);
    $this->assertEquals($css, $component_structure['sourceCodeCss']);
    $this->assertEquals('', $component_structure['compiledJs']);
    $this->assertEquals('', $component_structure['compiledCss']);
    $this->assertEquals([], $component_structure['importedJsComponents']);

    $expected_props = [
      'title' => [
        'title' => 'Title',
        'type' => 'string',
        'examples' => ['Sample Title'],
      ],
      'count' => [
        'title' => 'Count',
        'type' => 'number',
        'examples' => [5],
      ],
    ];
    $this->assertEquals($expected_props, $component_structure['props']);
  }

  /**
   * Test that attempting to create a component with an existing name fails.
   */
  public function testCreateExistingComponentFails(): void {
    $js_component = JavaScriptComponent::create([
      'machineName' => 'existing_component',
      'name' => 'Existing Component',
      'status' => FALSE,
      'props' => [],
      'required' => [],
      'slots' => [],
      'js' => [
        'original' => 'console.log("hey");',
        'compiled' => 'console.log("hey");',
      ],
      'css' => [
        'original' => '.test { display: none; }',
        'compiled' => '.test { display: none; }',
      ],
    ]);
    $js_component->save();

    $tool = $this->functionCallManager->createInstance('ai_agent:create_component');
    $this->assertInstanceOf(ExecutableFunctionCallInterface::class, $tool);
    $tool->setContextValue('component_name', 'Existing Component');
    $tool->execute();

    $result = $tool->getReadableOutput();
    $this->assertIsString($result);
    $this->assertEquals('The component with same name already exists.', $result);
  }

}
