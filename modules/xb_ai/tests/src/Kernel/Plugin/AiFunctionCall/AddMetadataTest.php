<?php

declare(strict_types=1);

namespace Drupal\Tests\xb_ai\Kernel\Plugin\AiFunctionCall;

use Drupal\ai\Service\FunctionCalling\ExecutableFunctionCallInterface;
use Drupal\Component\Serialization\Json;
use Drupal\KernelTests\KernelTestBase;
use Symfony\Component\Yaml\Yaml;

/**
 * Tests for the AddMetadata function call plugin.
 *
 * @group xb_ai
 */
class AddMetadataTest extends KernelTestBase {

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
   * Test generating metadata successfully.
   */
  public function testAddMetadata(): void {
    $tool = $this->functionCallManager->createInstance('ai_agent:add_metadata');
    $this->assertInstanceOf(ExecutableFunctionCallInterface::class, $tool);

    $generated_metadata = [
      'metadata' => [
        'metatag_description' => 'This is metatag description',
      ],
    ];
    $tool->setContextValue('metadata', Json::encode($generated_metadata));
    $tool->execute();
    $result = $tool->getReadableOutput();
    $this->assertIsString($result);

    $parsed_result = Yaml::parse($result);
    $this->assertArrayHasKey('metadata', $parsed_result);
    $this->assertEquals($generated_metadata, Json::decode($parsed_result['metadata']));
  }

}
