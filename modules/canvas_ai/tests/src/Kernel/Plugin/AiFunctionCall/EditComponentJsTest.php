<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_ai\Kernel\Plugin\AiFunctionCall;

use Drupal\canvas\Entity\JavaScriptComponent;
use Drupal\canvas_ai\Plugin\AiFunctionCall\EditComponentJs;
use Drupal\Component\Serialization\Json;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\canvas_ai\Traits\FunctionalCallTestTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Yaml\Yaml;

/**
 * Tests for the EditComponentJs function call plugin.
 */
#[Group('canvas_ai')]
final class EditComponentJsTest extends CanvasKernelTestBase {

  use FunctionalCallTestTrait;

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
      'dataDependencies' => [],
    ]);
    $js_component->save();
  }

  /**
   * Test editing component JavaScript successfully.
   */
  public function testEditComponentJs(): void {
    $tool = $this->functionCallManager->createInstance('ai_agent:edit_component_js');
    $this->assertInstanceOf(EditComponentJs::class, $tool);

    $js_content = 'console.log("Hello World"); const component = { init: () => {} };';
    $props_metadata = Json::encode([
      [
        'id' => 'title',
        'name' => 'Title',
        'type' => 'string',
        'example' => 'Sample Title',
        'required' => TRUE,
      ],
      [
        'id' => 'count',
        'name' => 'Count',
        'type' => 'number',
        'example' => 5,
        'required' => FALSE,
      ],
      [
        'id' => 'description',
        'name' => 'Description',
        'type' => 'string',
        'example' => 'A description',
      ],
      [
        'id' => 'author',
        'name' => 'Author',
        'type' => 'string',
        'example' => 'John Doe',
      ],
    ]);

    $tool->setContextValue('javascript', $js_content);
    $tool->setContextValue('props_metadata', $props_metadata);
    $tool->setContextValue('component_machine_name', 'existing_component');
    $tool->setContextValue('selected_component_required_props', ['count', 'description']);
    $tool->execute();
    $result = $tool->getStructuredOutput();

    $this->assertArrayHasKey('js_structure', $result);
    $this->assertArrayHasKey('props_metadata', $result);
    $this->assertArrayHasKey('required_props', $result);
    $this->assertEquals($js_content, $result['js_structure']);

    // Verify props_metadata is transformed to Record format.
    $props_metadata = [
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
      'description' => [
        'title' => 'Description',
        'type' => 'string',
        'examples' => ['A description'],
      ],
      'author' => [
        'title' => 'Author',
        'type' => 'string',
        'examples' => ['John Doe'],
      ],
    ];
    $this->assertEquals($props_metadata, Json::decode($result['props_metadata']));
    self::assertEquals(['title', 'description'], $result['required_props']);
    // No slots supplied: structured output exposes an empty slots metadata.
    $this->assertArrayHasKey('slots_metadata', $result);
    $this->assertEquals([], Json::decode($result['slots_metadata']));
  }

  /**
   * Test editing a component to add slots.
   */
  public function testEditComponentJsWithSlots(): void {
    $props_metadata = Json::encode([
      [
        'id' => 'heading',
        'name' => 'Heading',
        'type' => 'string',
        'example' => 'Card title',
      ],
    ]);
    $slots_metadata = Json::encode([
      [
        'id' => 'children',
        'name' => 'Children',
        'example' => '<p>child</p>',
      ],
    ]);

    $tool = $this->functionCallManager->createInstance('ai_agent:edit_component_js');
    $this->assertInstanceOf(EditComponentJs::class, $tool);
    $tool->setContextValue('javascript', 'console.log("card");');
    $tool->setContextValue('props_metadata', $props_metadata);
    $tool->setContextValue('slots_metadata', $slots_metadata);
    $tool->setContextValue('component_machine_name', 'existing_component');
    $tool->execute();
    $result = $tool->getStructuredOutput();

    $expected_slots = [
      'children' => [
        'title' => 'Children',
        'examples' => ['<p>child</p>'],
      ],
    ];
    $this->assertEquals($expected_slots, Json::decode($result['slots_metadata']));
  }

  /**
   * Data provider for testInvalidPropsOrSlotsMetadataReturnsError.
   *
   * @return array<string, array{string, string, string}>
   *   Cases of [props_metadata, slots_metadata, expected error].
   */
  public static function invalidPropsOrSlotsMetadataProvider(): array {
    return [
      'prop id collides with slot name' => [
        Json::encode([
          ['id' => 'content', 'name' => 'Content', 'type' => 'string', 'example' => 'Some content'],
        ]),
        Json::encode([
          ['id' => 'content', 'name' => 'Content', 'example' => '<p>child</p>'],
        ]),
        'Component validation errors: component_structure: The component "canvas:existing_component" declared [content] both as a prop and as a slot. Make sure to use different names.',
      ],
      'malformed slots JSON' => [
        Json::encode([
          ['id' => 'title', 'name' => 'Title', 'type' => 'string', 'example' => 'Hi'],
        ]),
        'garbage',
        'The slots metadata must be a valid JSON array of slot objects.',
      ],
      'slot missing a name' => [
        Json::encode([
          ['id' => 'title', 'name' => 'Title', 'type' => 'string', 'example' => 'Hi'],
        ]),
        Json::encode([
          ['id' => 'children'],
        ]),
        'Each slot must include both an "id" and a "name". Slot "children" is missing one of them.',
      ],
      'slot id is not the camelCase of its name' => [
        Json::encode([
          ['id' => 'title', 'name' => 'Title', 'type' => 'string', 'example' => 'Hi'],
        ]),
        Json::encode([
          ['id' => 'accordion_content', 'name' => 'Accordion Content', 'example' => '<p>x</p>'],
        ]),
        'The slot "id" must be the camelCase of the slot "name". Got id "accordion_content" for name "Accordion Content"; expected "accordionContent".',
      ],
    ];
  }

  /**
   * Test that invalid props or slots metadata returns the error to the agent.
   *
   * @param string $props_metadata
   *   The props metadata as a JSON encoded string.
   * @param string $slots_metadata
   *   The slots metadata as a JSON encoded string.
   * @param string $expected_error
   *   The expected error message.
   */
  #[DataProvider('invalidPropsOrSlotsMetadataProvider')]
  public function testInvalidPropsOrSlotsMetadataReturnsError(string $props_metadata, string $slots_metadata, string $expected_error): void {
    $result = $this->getToolOutput(
      'ai_agent:edit_component_js',
      [
        'javascript' => 'console.log("x");',
        'props_metadata' => $props_metadata,
        'slots_metadata' => $slots_metadata,
        'component_machine_name' => 'existing_component',
      ]
    );
    self::assertYamlError($result, $expected_error);
  }

  /**
   * Test that dropping existing props and slots warns they were removed.
   */
  public function testEditDroppingPropsAndSlotsWarns(): void {
    JavaScriptComponent::create([
      'machineName' => 'component_with_metadata',
      'name' => 'Component With Metadata',
      'status' => FALSE,
      'props' => [
        'title' => ['title' => 'Title', 'type' => 'string', 'examples' => ['Hello']],
        'subtitle' => ['title' => 'Subtitle', 'type' => 'string', 'examples' => ['World']],
      ],
      'required' => [],
      'slots' => [
        'main' => ['title' => 'Main', 'examples' => ['<p>main</p>']],
        'aside' => ['title' => 'Aside', 'examples' => ['<p>aside</p>']],
      ],
      'js' => ['original' => 'console.log("x");', 'compiled' => 'console.log("x");'],
      'css' => ['original' => '', 'compiled' => ''],
      'dataDependencies' => [],
    ])->save();

    // The edit keeps only the 'title' prop and 'main' slot, dropping the rest.
    $result = $this->getToolOutput(
      'ai_agent:edit_component_js',
      [
        'javascript' => 'console.log("x");',
        'props_metadata' => Json::encode([
          ['id' => 'title', 'name' => 'Title', 'type' => 'string', 'example' => 'Hello'],
        ]),
        'slots_metadata' => Json::encode([
          ['id' => 'main', 'name' => 'Main', 'example' => '<p>main</p>'],
        ]),
        'component_machine_name' => 'component_with_metadata',
      ]
    );

    $this->assertStringContainsString('Component with id "component_with_metadata" has been successfully updated.', $result);
    // The dropped prop 'subtitle' is reported as removed.
    $this->assertStringContainsString('These props existed on the component but were left out of this update, so they have been removed', $result);
    $this->assertStringContainsString('subtitle', $result);
    // The dropped slot 'aside' is reported as removed.
    $this->assertStringContainsString('These slots existed on the component but were left out of this update, so they have been removed', $result);
    $this->assertStringContainsString('aside', $result);
  }

  public function testComponentValidation(): void {
    $component_machine_name = 'existing_component';
    $javascript = 'console.log("Hello World");';
    $props_metadata = Json::encode([
      [
        'id' => 'title',
        'name' => 'Title',
        'type' => 'string',
        'example' => 1,
      ],
      [
        'id' => 'count',
        'name' => 'Count',
        'type' => 'integer',
        // 'example' will be transformed into 'examples' array.
        'example' => 'four',
      ],
    ]);
    $result = $this->getToolOutput(
      'ai_agent:edit_component_js',
      [
        'javascript' => $javascript,
        'props_metadata' => $props_metadata,
        'component_machine_name' => $component_machine_name,
      ]
    );
    self::assertYamlError($result, 'Component validation errors: component_structure: Prop "title" has invalid example value: [] Integer value found, but a string or an object is required component_structure: Prop "count" has invalid example value: [] String value found, but an integer or an object is required component_structure.props.count.examples.0: This value should be of the correct primitive type.');
  }

  /**
   * Asserts that the tool result contains a YAML error message.
   *
   * CanvasBuilder expects the tool result to always be a YAML parsable string.
   *
   * @param string $toolResult
   *   The tool result.
   * @param string $expectedError
   *   The expected error message.
   *
   * @return void
   *
   * @see \Drupal\canvas_ai\Controller\CanvasBuilder::render()
   */
  private function assertYamlError(string $toolResult, string $expectedError): void {
    $yaml = Yaml::parse($toolResult);
    self::assertIsArray($yaml);
    self::assertCount(1, $yaml);
    self::assertSame("Failed to process Javascript component data: $expectedError", $this->normalizeErrorString($yaml['error']));
  }

}
