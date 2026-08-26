<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_ai\Kernel\Plugin\AiFunctionCall;

use Drupal\canvas_ai\Plugin\AiFunctionCall\SetPageValue;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests for the SetPageValue function call plugin.
 */
#[Group('canvas_ai')]
class SetPageValueTest extends CanvasKernelTestBase {

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
    'canvas_ai',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->functionCallManager = $this->container->get('plugin.manager.ai.function_calls');
  }

  /**
   * Tests setting the page title.
   */
  public function testSetTitle(): void {
    $tool = $this->functionCallManager->createInstance('canvas_ai:set_page_value');
    $this->assertInstanceOf(SetPageValue::class, $tool);

    $value = 'My page title';
    $tool->setContextValue('key', 'title');
    $tool->setContextValue('value', $value);
    $this->assertCount(0, $tool->validateContexts());
    $tool->execute();

    $this->assertEquals([
      'canvas_page_data' => ['title[0][value]' => $value],
    ], $tool->getStructuredOutput());
    $this->assertEquals('Page title set successfully.', $tool->getReadableOutput());
  }

  /**
   * Tests setting the page meta description.
   */
  public function testSetDescription(): void {
    $tool = $this->functionCallManager->createInstance('canvas_ai:set_page_value');
    $this->assertInstanceOf(SetPageValue::class, $tool);

    $value = 'My SEO meta description';
    $tool->setContextValue('key', 'description');
    $tool->setContextValue('value', $value);
    $this->assertCount(0, $tool->validateContexts());
    $tool->execute();

    $this->assertEquals([
      'canvas_page_data' => ['description[0][value]' => $value],
    ], $tool->getStructuredOutput());
    $this->assertEquals('Page description set successfully.', $tool->getReadableOutput());
  }

  /**
   * Tests that a disallowed key is rejected by context validation.
   *
   * The agent validates contexts before running a tool, so a key outside the
   * AllowedValues set is rejected there rather than in ::execute().
   *
   * @see \Drupal\ai_agents\PluginBase\AiAgentEntityWrapper::validateTool()
   */
  public function testDisallowedKeyIsRejected(): void {
    $tool = $this->functionCallManager->createInstance('canvas_ai:set_page_value');
    $this->assertInstanceOf(SetPageValue::class, $tool);

    $tool->setContextValue('key', 'body');
    $tool->setContextValue('value', 'Some value');

    $violations = $tool->validateContexts();
    $this->assertCount(1, $violations);
    $this->assertStringContainsString('not a valid choice', (string) $violations->get(0)->getMessage());
  }

  /**
   * Tests that the "key" argument is exposed to the model as an enum.
   */
  public function testKeyExposesAllowedValues(): void {
    $tool = $this->functionCallManager->createInstance('canvas_ai:set_page_value');
    $this->assertInstanceOf(SetPageValue::class, $tool);

    $key = $tool->normalize()->getPropertyByName('key');
    $this->assertNotNull($key);
    $this->assertEquals(['title', 'description'], $key->getEnum());
  }

}
