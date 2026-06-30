<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_ai\Kernel\Plugin\AiFunctionCall;

use Drupal\canvas\Entity\JavaScriptComponent;
use Drupal\canvas_ai\Plugin\AiFunctionCall\CreateComponent;
use Drupal\Component\Serialization\Json;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\canvas_ai\Traits\FunctionalCallTestTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Yaml\Yaml;

/**
 * Tests for the CreateComponent function call plugin.
 */
#[Group('canvas_ai')]
final class CreateComponentTest extends CanvasKernelTestBase {

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
  }

  /**
   * Test creating a new component successfully.
   */
  public function testCreateNewComponent(): void {
    $tool = $this->functionCallManager->createInstance('ai_agent:create_component');
    $this->assertInstanceOf(CreateComponent::class, $tool);

    $component_name = 'Test Component';
    $javascript = 'console.log("Hello World");';
    $css = '.test { color: red; }';
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
      ],
    ]);

    $tool->setContextValue('component_name', $component_name);
    $tool->setContextValue('js_structure', $javascript);
    $tool->setContextValue('css_structure', $css);
    $tool->setContextValue('props_metadata', $props_metadata);
    $tool->execute();
    $result = $tool->getStructuredOutput();

    $this->assertArrayHasKey('component_structure', $result);
    $component_structure = $result['component_structure'];
    $this->assertEquals($component_name, $component_structure['name']);
    $this->assertEquals('test_component', $component_structure['machineName']);
    $this->assertFalse($component_structure['status']);
    $this->assertEquals($javascript, $component_structure['sourceCodeJs']);
    $this->assertEquals($css, $component_structure['sourceCodeCss']);
    $this->assertEquals('', $component_structure['compiledJs']);
    $this->assertEquals('', $component_structure['compiledCss']);
    $this->assertEquals([], $component_structure['importedJsComponents']);
    $this->assertEquals([], $component_structure['dataDependencies']);

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
    $this->assertEquals(['title'], $component_structure['required']);
    // No slots were supplied, so the slots key is an empty array.
    $this->assertEquals([], $component_structure['slots']);
  }

  /**
   * Test that a formatted-text prop retains its rich-text schema keys.
   *
   * A string prop is only treated as formatted/rich text by Canvas when its
   * 'contentMediaType' (and optional 'x-formatting-context') are preserved.
   */
  public function testCreateComponentWithFormattedTextProp(): void {
    $props_metadata = Json::encode([
      [
        'id' => 'body',
        'name' => 'Body',
        'type' => 'string',
        'example' => '<p>Example body text.</p>',
        'derivedType' => 'formattedText',
        'contentMediaType' => 'text/html',
        'x-formatting-context' => 'block',
      ],
    ]);

    $tool = $this->functionCallManager->createInstance('ai_agent:create_component');
    $this->assertInstanceOf(CreateComponent::class, $tool);
    $tool->setContextValue('component_name', 'Article Body Component');
    $tool->setContextValue('js_structure', 'console.log("body");');
    $tool->setContextValue('css_structure', '');
    $tool->setContextValue('props_metadata', $props_metadata);
    $tool->execute();
    $result = $tool->getStructuredOutput();

    $this->assertArrayHasKey('component_structure', $result);
    $expected = [
      'title' => 'Body',
      'type' => 'string',
      'examples' => ['<p>Example body text.</p>'],
      'contentMediaType' => 'text/html',
      'x-formatting-context' => 'block',
    ];
    $this->assertEquals($expected, $result['component_structure']['props']['body']);
  }

  /**
   * Test creating a component with slots.
   */
  public function testCreateComponentWithSlots(): void {
    $props_metadata = Json::encode([
      [
        'id' => 'heading',
        'name' => 'Heading',
        'type' => 'string',
        'example' => 'Card title',
        'required' => TRUE,
      ],
    ]);
    $slots_metadata = Json::encode([
      [
        'id' => 'children',
        'name' => 'Children',
        'example' => '<p>Place components here</p>',
      ],
      [
        // A slot without an example should still be accepted.
        'id' => 'footer',
        'name' => 'Footer',
      ],
    ]);

    $tool = $this->functionCallManager->createInstance('ai_agent:create_component');
    $this->assertInstanceOf(CreateComponent::class, $tool);
    $tool->setContextValue('component_name', 'Card Component');
    $tool->setContextValue('js_structure', 'console.log("card");');
    $tool->setContextValue('css_structure', '.card { padding: 1rem; }');
    $tool->setContextValue('props_metadata', $props_metadata);
    $tool->setContextValue('slots_metadata', $slots_metadata);
    $tool->execute();
    $result = $tool->getStructuredOutput();

    $this->assertArrayHasKey('component_structure', $result);
    $component_structure = $result['component_structure'];
    $expected_slots = [
      'children' => [
        'title' => 'Children',
        'examples' => ['<p>Place components here</p>'],
      ],
      'footer' => [
        'title' => 'Footer',
      ],
    ];
    $this->assertEquals($expected_slots, $component_structure['slots']);
    $this->assertArrayHasKey('heading', $component_structure['props']);
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
        'Component validation errors: component_structure: The component "canvas:invalid_metadata_component" declared [content] both as a prop and as a slot. Make sure to use different names.',
      ],
      'multiple prop/slot collisions are all reported' => [
        Json::encode([
          ['id' => 'content', 'name' => 'Content', 'type' => 'string', 'example' => 'c'],
          ['id' => 'heading', 'name' => 'Heading', 'type' => 'string', 'example' => 'h'],
        ]),
        Json::encode([
          ['id' => 'content', 'name' => 'Content'],
          ['id' => 'heading', 'name' => 'Heading'],
        ]),
        'Component validation errors: component_structure: The component "canvas:invalid_metadata_component" declared [content, heading] both as a prop and as a slot. Make sure to use different names.',
      ],
      'malformed slots JSON' => [
        Json::encode([
          ['id' => 'heading', 'name' => 'Heading', 'type' => 'string', 'example' => 'Hi'],
        ]),
        'not valid json',
        'The slots metadata must be a valid JSON array of slot objects.',
      ],
      'slot missing an id' => [
        Json::encode([
          ['id' => 'heading', 'name' => 'Heading', 'type' => 'string', 'example' => 'Hi'],
        ]),
        Json::encode([
          ['id' => 'children', 'name' => 'Children', 'example' => '<p>x</p>'],
          ['name' => 'No Id'],
        ]),
        'Each slot must include both an "id" and a "name". Slot "No Id" is missing one of them.',
      ],
      'slot id is not the camelCase of its name' => [
        Json::encode([
          ['id' => 'heading', 'name' => 'Heading', 'type' => 'string', 'example' => 'Hi'],
        ]),
        Json::encode([
          ['id' => 'cta_content', 'name' => 'CTA Content', 'example' => '<p>x</p>'],
        ]),
        'The slot "id" must be the camelCase of the slot "name". Got id "cta_content" for name "CTA Content"; expected "ctaContent".',
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
      'ai_agent:create_component',
      [
        'component_name' => 'Invalid Metadata Component',
        'js_structure' => 'console.log("x");',
        'css_structure' => '',
        'props_metadata' => $props_metadata,
        'slots_metadata' => $slots_metadata,
      ]
    );
    self::assertYamlError($result, $expected_error);
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
      'dataDependencies' => [],
    ]);
    $js_component->save();

    self::assertYamlError(
      $this->getToolOutput('ai_agent:create_component', ['component_name' => $js_component->id()]),
      'The component with same name already exists.'
    );
  }

  public function testComponentValidation(): void {
    $component_name = 'Invalid Component';
    $javascript = 'console.log("Hello World");';
    $css = '.test { color: red; }';
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
      'ai_agent:create_component',
      [
        'component_name' => $component_name,
        'js_structure' => $javascript,
        'css_structure' => $css,
        'props_metadata' => $props_metadata,
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
