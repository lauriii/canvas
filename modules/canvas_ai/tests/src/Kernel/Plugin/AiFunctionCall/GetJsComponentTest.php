<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_ai\Kernel\Plugin\AiFunctionCall;

use Drupal\ai\Service\FunctionCalling\ExecutableFunctionCallInterface;
use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\Entity\JavaScriptComponent;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Component\Yaml\Yaml;

/**
 * Tests for the GetJsComponent function call plugin.
 */
#[Group('canvas_ai')]
final class GetJsComponentTest extends CanvasKernelTestBase {

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
    $this->installEntitySchema('path_alias');
    $this->functionCallManager = $this->container->get('plugin.manager.ai.function_calls');
    // Needs access to Canvas, permission to create code components is enough.
    $this->setUpCurrentUser([], [JavaScriptComponent::ADMIN_PERMISSION]);
  }

  /**
   * Test getting JS component returns expected JS and CSS.
   */
  #[TestWith([FALSE])]
  #[TestWith([TRUE])]
  public function testGetJsComponent(bool $with_auto_save = FALSE): void {
    $tool = $this->functionCallManager->createInstance('ai_agent:get_js_component');
    $this->assertInstanceOf(ExecutableFunctionCallInterface::class, $tool);

    $component = JavaScriptComponent::create([
      'machineName' => 'test_component',
      'name' => 'Test Component',
      'status' => FALSE,
      'props' => [
        'content' => [
          'type' => 'string',
          'title' => 'Content',
          'examples' => ['Short title or info'],
        ],
      ],
      'required' => ['content'],
      'slots' => [
        'cardContent' => [
          'title' => 'Card Content',
          'examples' => ['<p>Place components here</p>'],
        ],
      ],
      'js' => [
        'original' => 'console.log("hey");',
        'compiled' => 'console.log("hey");',
      ],
      'css' => [
        'original' => '.test { display: none; }',
        'compiled' => '.test { display: none; }',
      ],
      'dataDependencies' => [],
    ]);
    $component->save();

    if ($with_auto_save) {
      $autoSave = JavaScriptComponent::create($component->toArray());
      \assert($autoSave instanceof JavaScriptComponent);
      $css = $autoSave->get('css');
      $css['original'] = '.test { display: none; }/**/';
      $autoSave->set('css', $css);
      $autoSaveManager = $this->container->get(AutoSaveManager::class);
      \assert($autoSaveManager instanceof AutoSaveManager);
      $autoSaveManager->saveEntity($autoSave);
    }

    $component_id = 'test_component';
    $tool->setContextValue('component_name', $component_id);
    $tool->execute();
    $output = $tool->getReadableOutput();
    $this->assertIsString($output);
    $parsed = Yaml::parse($output);

    $this->assertArrayHasKey('js', $parsed);
    $this->assertArrayHasKey('css', $parsed);
    $this->assertEquals('console.log("hey");', $parsed['js']);
    $this->assertEquals('.test { display: none; }' . ($with_auto_save ? '/**/' : ''), $parsed['css']);

    // The tool also exposes the component's props and slots, converted to the
    // agent-facing metadata format by the trait.
    // @see \Drupal\canvas_ai\Plugin\AiFunctionCall\AiGeneratedJsComponentPropsAndSlotsTrait::buildPropsMetadataFromStored()
    // @see \Drupal\canvas_ai\Plugin\AiFunctionCall\AiGeneratedJsComponentPropsAndSlotsTrait::buildSlotsMetadataFromStored()
    $this->assertEquals([
      [
        'id' => 'content',
        'name' => 'Content',
        'type' => 'string',
        'example' => 'Short title or info',
        'required' => TRUE,
      ],
    ], $parsed['props_metadata']);
    $this->assertEquals([
      [
        'id' => 'cardContent',
        'name' => 'Card Content',
        'example' => '<p>Place components here</p>',
      ],
    ], $parsed['slots_metadata']);
  }

  /**
   * Test GetJsComponent returns error message when component does not exist.
   */
  public function testGetJsComponentNonExistent(): void {
    $tool = $this->functionCallManager->createInstance('ai_agent:get_js_component');
    $this->assertInstanceOf(ExecutableFunctionCallInterface::class, $tool);

    $tool->setContextValue('component_name', 'non_existent_component');

    $tool->execute();

    $output = $tool->getReadableOutput();
    $this->assertStringContainsString('does not exist', $output);
  }

}
