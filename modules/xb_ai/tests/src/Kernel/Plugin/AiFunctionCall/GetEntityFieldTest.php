<?php

declare(strict_types=1);

namespace Drupal\Tests\xb_ai\Kernel\Plugin\AiFunctionCall;

use Drupal\ai\Service\FunctionCalling\ExecutableFunctionCallInterface;
use Symfony\Component\Yaml\Yaml;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;

/**
 * Tests for the GetEntityField function call plugin.
 *
 * @group xb_ai
 */
final class GetEntityFieldTest extends KernelTestBase {

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
    'system',
    'node',
    'user',
    'field',
    'ai',
    'ai_agents',
    'experience_builder',
    'xb_ai',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('node');
    $this->installEntitySchema('user');
    $this->functionCallManager = $this->container->get('plugin.manager.ai.function_calls');
    $node_type = NodeType::create(['type' => 'article', 'name' => 'Article']);
    $node_type->save();

    $node = Node::create([
      'type' => 'article',
      'title' => 'Test Node',
    ]);
    $node->save();
  }

  /**
   * Tests fetching a field from an entity.
   */
  public function testGetEntityField(): void {
    $tool = $this->functionCallManager->createInstance('ai_agent:get_entity_field');
    $this->assertInstanceOf(ExecutableFunctionCallInterface::class, $tool);

    $tool->setContextValue('entity_type', 'node');
    $tool->setContextValue('entity_id', '1');
    $tool->setContextValue('field_name', 'title');
    $tool->execute();

    $result = $tool->getReadableOutput();
    $field_value = Yaml::parse($result);

    $this->assertArrayHasKey('title', $field_value);
    $this->assertEquals('Test Node', $field_value['title']);
  }

  /**
   * Tests fetching a field for a non-existing entity.
   */
  public function testFetchingWrongEntity(): void {
    $tool = $this->functionCallManager->createInstance('ai_agent:get_entity_field');
    $this->assertInstanceOf(ExecutableFunctionCallInterface::class, $tool);

    $tool->setContextValue('entity_type', 'node');
    $tool->setContextValue('entity_id', '2');
    $tool->setContextValue('field_name', 'title');
    $tool->execute();

    $result = $tool->getReadableOutput();
    $field_value = Yaml::parse($result);
    $this->assertSame("The entity does not exist or is not fieldable.", $field_value);
  }

  /**
   * Tests fetching a field which doesn't exist for the entity.
   */
  public function testFetchingWrongField(): void {
    $tool = $this->functionCallManager->createInstance('ai_agent:get_entity_field');
    $this->assertInstanceOf(ExecutableFunctionCallInterface::class, $tool);

    $tool->setContextValue('entity_type', 'node');
    $tool->setContextValue('entity_id', '1');
    $tool->setContextValue('field_name', 'image');
    $tool->execute();

    $result = $tool->getReadableOutput();
    $field_value = Yaml::parse($result);
    $this->assertSame("The field 'image' does not exist for this entity.", $field_value);
  }

  /**
   * Tests the tool without field name.
   */
  public function testWithoutField(): void {
    $tool = $this->functionCallManager->createInstance('ai_agent:get_entity_field');
    $this->assertInstanceOf(ExecutableFunctionCallInterface::class, $tool);

    $tool->setContextValue('entity_type', 'node');
    $tool->setContextValue('entity_id', '1');
    $tool->execute();

    $result = $tool->getReadableOutput();
    $field_value = Yaml::parse($result);
    $this->assertSame("No field provided.", $field_value);
  }

}
