<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_ai\Kernel\Plugin\AiFunctionCall;

use Drupal\ai\Service\FunctionCalling\ExecutableFunctionCallInterface;
use Drupal\canvas_ai\CanvasAiPermissions;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests for the GetPropsType function call plugin.
 */
#[Group('canvas_ai')]
final class GetPropsTypeTest extends CanvasKernelTestBase {

  use UserCreationTrait;

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
    // setUpCurrentUser() queries the path_alias table.
    $this->installEntitySchema('path_alias');
    $this->functionCallManager = $this->container->get('plugin.manager.ai.function_calls');
    $this->setUpCurrentUser([], [CanvasAiPermissions::USE_CANVAS_AI]);
  }

  /**
   * Tests that the tool refuses to run without the Canvas AI permission.
   */
  public function testWithoutPermission(): void {
    $this->setUpCurrentUser();
    $tool = $this->functionCallManager->createInstance('ai_agent:get_props_type');
    $this->assertInstanceOf(ExecutableFunctionCallInterface::class, $tool);
    $tool->setContextValue('derived_proptypes', 'string');

    $this->expectException(\Exception::class);
    $this->expectExceptionMessage('The current user does not have the right permissions to run this tool.');
    $tool->execute();
  }

  /**
   * Tests that the tool decodes HTML entities in the given prop type.
   */
  public function testGetPropsType(): void {
    $tool = $this->functionCallManager->createInstance('ai_agent:get_props_type');
    $this->assertInstanceOf(ExecutableFunctionCallInterface::class, $tool);
    $tool->setContextValue('derived_proptypes', 'array&lt;string&gt;');

    $tool->execute();

    $this->assertSame('array<string>', $tool->getReadableOutput());
  }

}
