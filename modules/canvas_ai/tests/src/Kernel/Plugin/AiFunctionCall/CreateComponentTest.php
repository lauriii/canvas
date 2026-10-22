<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_ai\Kernel\Plugin\AiFunctionCall;

use Drupal\canvas\Entity\JavaScriptComponent;
use Drupal\canvas_ai\CanvasAiPermissions;
use Drupal\canvas_ai\Plugin\AiFunctionCall\CreateComponent;
use Drupal\Component\Serialization\Json;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\canvas_ai\Traits\FunctionalCallTestTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests for the CreateComponent function call plugin.
 */
#[Group('canvas_ai')]
final class CreateComponentTest extends CanvasKernelTestBase {

  use FunctionalCallTestTrait;
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
    $this->functionCallManager = $this->container->get('plugin.manager.ai.function_calls');
    // setUpCurrentUser() queries the path_alias table.
    $this->installEntitySchema('path_alias');
    $this->setUpCurrentUser([], [CanvasAiPermissions::USE_CANVAS_AI]);
    JavaScriptComponent::create([
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
    ])->save();
  }

  /**
   * Tests that the tool refuses to run without the Canvas AI permission.
   */
  public function testWithoutPermission(): void {
    $this->setUpCurrentUser();
    $tool = $this->functionCallManager->createInstance('ai_agent:create_component');
    $this->assertInstanceOf(CreateComponent::class, $tool);
    $tool->setContextValue('component_name', 'New Component');

    $this->expectException(\Exception::class);
    $this->expectExceptionMessage('The current user does not have the right permissions to run this tool.');
    $tool->execute();
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
   * Tests that every error of a create call is reported together.
   *
   * @param array $contexts
   *   The context values to set on the tool.
   * @param string $expected_error
   *   The expected error message.
   */
  #[DataProvider('errorsProvider')]
  public function testErrorsAreReportedTogether(array $contexts, string $expected_error): void {
    $tool = $this->functionCallManager->createInstance('ai_agent:create_component');
    $this->assertInstanceOf(CreateComponent::class, $tool);
    foreach ($contexts as $key => $context) {
      $tool->setContextValue($key, $context);
    }
    $tool->execute();

    self::assertSame($expected_error, self::normalizeErrorString($tool->getReadableOutput()));
    // Nothing is applied while any error exists.
    self::assertSame([], $tool->getStructuredOutput());
  }

  /**
   * Data provider for testErrorsAreReportedTogether.
   *
   * @return array<string, array{contexts: array, expected_error: string}>
   *   An array of test cases.
   */
  public static function errorsProvider(): array {
    $heading_prop = Json::encode([
      ['id' => 'heading', 'name' => 'Heading', 'type' => 'string', 'example' => 'Hi'],
    ]);
    $content_prop = Json::encode([
      ['id' => 'content', 'name' => 'Content', 'type' => 'string', 'example' => 'Some content'],
    ]);
    $content_slot = Json::encode([
      ['id' => 'content', 'name' => 'Content', 'example' => '<p>child</p>'],
    ]);
    $cta_content_slot = Json::encode([
      ['id' => 'cta_content', 'name' => 'CTA Content', 'example' => '<p>x</p>'],
    ]);
    $nothing_applied = 'Nothing was applied. Fix every error listed below and call the tool again.';
    $cta_content_error = 'The slot "id" must be the camelCase of the slot "name". Got id "cta_content" for name "CTA Content"; expected "ctaContent".';
    $invalid_examples = Json::encode([
      ['id' => 'title', 'name' => 'Title', 'type' => 'string', 'example' => 1],
      // 'example' will be transformed into 'examples' array.
      ['id' => 'count', 'name' => 'Count', 'type' => 'integer', 'example' => 'four'],
    ]);

    return [
      'prop id collides with slot name' => [
        'contexts' => [
          'component_name' => 'Invalid Metadata Component',
          'js_structure' => 'console.log("x");',
          'props_metadata' => $content_prop,
          'slots_metadata' => $content_slot,
        ],
        'expected_error' => $nothing_applied . ' ## Component - Component validation errors: component_structure: The component "canvas:invalid_metadata_component" declared [content] both as a prop and as a slot. Make sure to use different names.',
      ],
      'multiple prop/slot collisions are all reported' => [
        'contexts' => [
          'component_name' => 'Invalid Metadata Component',
          'js_structure' => 'console.log("x");',
          'props_metadata' => Json::encode([
            ['id' => 'content', 'name' => 'Content', 'type' => 'string', 'example' => 'c'],
            ['id' => 'heading', 'name' => 'Heading', 'type' => 'string', 'example' => 'h'],
          ]),
          'slots_metadata' => Json::encode([
            ['id' => 'content', 'name' => 'Content'],
            ['id' => 'heading', 'name' => 'Heading'],
          ]),
        ],
        'expected_error' => $nothing_applied . ' ## Component - Component validation errors: component_structure: The component "canvas:invalid_metadata_component" declared [content, heading] both as a prop and as a slot. Make sure to use different names.',
      ],
      'malformed slots JSON' => [
        'contexts' => [
          'component_name' => 'Invalid Metadata Component',
          'js_structure' => 'console.log("x");',
          'props_metadata' => $heading_prop,
          'slots_metadata' => 'not valid json',
        ],
        'expected_error' => $nothing_applied . ' ## Slots - The slots metadata must be a valid JSON array of slot objects.',
      ],
      'slot missing an id' => [
        'contexts' => [
          'component_name' => 'Invalid Metadata Component',
          'js_structure' => 'console.log("x");',
          'props_metadata' => $heading_prop,
          'slots_metadata' => Json::encode([
            ['id' => 'children', 'name' => 'Children', 'example' => '<p>x</p>'],
            ['name' => 'No Id'],
          ]),
        ],
        'expected_error' => $nothing_applied . ' ## Slot 1 - Each slot must include both an "id" and a "name". Slot "No Id" is missing one of them.',
      ],
      'slot id is not the camelCase of its name' => [
        'contexts' => [
          'component_name' => 'Invalid Metadata Component',
          'js_structure' => 'console.log("x");',
          'props_metadata' => $heading_prop,
          'slots_metadata' => $cta_content_slot,
        ],
        'expected_error' => $nothing_applied . ' ## Slot 0 - ' . $cta_content_error,
      ],
      'every invalid slot is reported' => [
        'contexts' => [
          'component_name' => 'Invalid Metadata Component',
          'js_structure' => 'console.log("x");',
          'props_metadata' => $heading_prop,
          'slots_metadata' => Json::encode([
            ['name' => 'No Id'],
            ['id' => 'cta_content', 'name' => 'CTA Content', 'example' => '<p>x</p>'],
            ['id' => 'footer', 'name' => 'Footer'],
          ]),
        ],
        'expected_error' => $nothing_applied . ' ## Slot 0 - Each slot must include both an "id" and a "name". Slot "No Id" is missing one of them. ## Slot 1 - ' . $cta_content_error,
      ],
      'component name already exists' => [
        'contexts' => [
          'component_name' => 'existing_component',
        ],
        'expected_error' => $nothing_applied . ' ## Component - The component with same name already exists.',
      ],
      '@apply in the CSS' => [
        'contexts' => [
          'component_name' => 'Apply Component',
          'js_structure' => 'console.log("x");',
          'css_structure' => '.test { @apply font-bold; }',
        ],
        'expected_error' => $nothing_applied . ' ## Component - @apply directives are not supported.',
      ],
      'invalid prop examples' => [
        'contexts' => [
          'component_name' => 'Invalid Component',
          'js_structure' => 'console.log("Hello World");',
          'css_structure' => '.test { color: red; }',
          'props_metadata' => $invalid_examples,
        ],
        'expected_error' => $nothing_applied . ' ## Component - Component validation errors: component_structure: Prop "title" has invalid example value: [] Integer value found, but a string or an object is required component_structure: Prop "count" has invalid example value: [] String value found, but an integer or an object is required component_structure.props.count.examples.0: This value should be of the correct primitive type.',
      ],
      'errors in every section are reported together' => [
        'contexts' => [
          'component_name' => 'existing_component',
          'js_structure' => 'console.log("x");',
          'css_structure' => '.test { @apply font-bold; }',
          'props_metadata' => $heading_prop,
          'slots_metadata' => $cta_content_slot,
        ],
        'expected_error' => $nothing_applied . ' ## Component - The component with same name already exists. - @apply directives are not supported. ## Slot 0 - ' . $cta_content_error,
      ],
      'validation still runs when the name already exists' => [
        'contexts' => [
          'component_name' => 'existing_component',
          'js_structure' => 'console.log("x");',
          'props_metadata' => $content_prop,
          'slots_metadata' => $content_slot,
        ],
        'expected_error' => $nothing_applied . ' ## Component - The component with same name already exists. - Component validation errors: component_structure: The component "canvas:existing_component" declared [content] both as a prop and as a slot. Make sure to use different names.',
      ],
      'slot and structure errors are reported together' => [
        'contexts' => [
          'component_name' => 'Invalid Component',
          'js_structure' => 'console.log("x");',
          'props_metadata' => $invalid_examples,
          'slots_metadata' => $cta_content_slot,
        ],
        'expected_error' => $nothing_applied . ' ## Slot 0 - ' . $cta_content_error . ' ## Component - Component validation errors: component_structure: Prop "title" has invalid example value: [] Integer value found, but a string or an object is required component_structure: Prop "count" has invalid example value: [] String value found, but an integer or an object is required component_structure.props.count.examples.0: This value should be of the correct primitive type.',
      ],
    ];
  }

}
