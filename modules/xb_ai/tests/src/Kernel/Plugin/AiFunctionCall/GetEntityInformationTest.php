<?php

declare(strict_types=1);

namespace Drupal\Tests\xb_ai\Kernel\Plugin\AiFunctionCall;

use Drupal\KernelTests\KernelTestBase;
use Symfony\Component\Yaml\Yaml;
use Drupal\ai\Service\FunctionCalling\ExecutableFunctionCallInterface;

/**
 * Tests for the GetEntityInformation function call plugin.
 *
 * @group xb_ai
 */
final class GetEntityInformationTest extends KernelTestBase {

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
   * Test getting entity information with valid information.
   */
  public function testGetEntityInformation(): void {
    $tool = $this->functionCallManager->createInstance('ai_agent:get_entity_information');
    $this->assertInstanceOf(ExecutableFunctionCallInterface::class, $tool);

    $entity_type = 'node';
    $entity_id = 42;
    $selected_component = 'Hero component';
    $layout = "{\"6b6d0485-3a31-4639-8826-7e20ae7a9070\":{\"heading\":\"Cats are my best friend\",\"subheading\":\"Cats are amazing\",\"cta1\":\"View\",\"cta1href\":\"https://example.com\",\"cta2\":\"Click\"},\"4bae0c93-76e3-44b9-b7a3-f6807593cff7\":{\"text\":\"Cats are wonderful companions with charming personalities. Their playful antics, soft purrs, and affectionate gestures make every day brighter. Whether lounging lazily in a sunbeam or curling up beside you, cats offer comfort and joy. Their loyalty and gentle presence create a sense of friendship that warms the heart.\"}}";
    $tool->setContextValue('entity_type', $entity_type);
    $tool->setContextValue('entity_id', $entity_id);
    $tool->setContextValue('selected_component', $selected_component);
    $tool->setContextValue('layout', $layout);
    $tool->execute();
    $result = $tool->getReadableOutput();

    $this->assertIsString($result);
    $parsed_result = Yaml::parse($result);

    $this->assertEquals($entity_type, $parsed_result['entity_type']);
    $this->assertEquals($entity_id, $parsed_result['entity_id']);
    $this->assertEquals($selected_component, $parsed_result['selected_component']);
    $this->assertEquals($layout, $parsed_result['layout']);
  }

}
