<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_ai\Kernel\Plugin\AiFunctionCall;

use Drupal\canvas\Entity\JavaScriptComponent;
use Drupal\canvas_ai\CanvasAiPermissions;
use Drupal\canvas_ai\Plugin\AiFunctionCall\EditComponentJs;
use Drupal\Component\Serialization\Json;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\canvas_ai\Traits\FunctionalCallTestTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests for the EditComponentJs function call plugin.
 */
#[Group('canvas_ai')]
#[RunTestsInSeparateProcesses]
final class EditComponentJsTest extends CanvasKernelTestBase {

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
   * Tests that the tool refuses to run without the Canvas AI permission.
   */
  public function testWithoutPermission(): void {
    $this->setUpCurrentUser();
    $tool = $this->functionCallManager->createInstance('ai_agent:edit_component_js');
    $this->assertInstanceOf(EditComponentJs::class, $tool);
    $tool->setContextValue('component_machine_name', 'existing_component');

    $this->expectException(\Exception::class);
    $this->expectExceptionMessage('The current user does not have the right permissions to run this tool.');
    $tool->execute();
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

  /**
   * Tests that every error of an edit call is reported together.
   *
   * @param array $contexts
   *   The context values to set on the tool.
   * @param string $expected_error
   *   The expected error message.
   */
  #[DataProvider('errorsProvider')]
  public function testErrorsAreReportedTogether(array $contexts, string $expected_error): void {
    $tool = $this->functionCallManager->createInstance('ai_agent:edit_component_js');
    $this->assertInstanceOf(EditComponentJs::class, $tool);
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
    $title_prop = Json::encode([
      ['id' => 'title', 'name' => 'Title', 'type' => 'string', 'example' => 'Hi'],
    ]);
    $accordion_content_slot = Json::encode([
      ['id' => 'accordion_content', 'name' => 'Accordion Content', 'example' => '<p>x</p>'],
    ]);
    $nothing_applied = 'Nothing was applied. Fix every error listed below and call the tool again.';
    $accordion_content_error = 'The slot "id" must be the camelCase of the slot "name". Got id "accordion_content" for name "Accordion Content"; expected "accordionContent".';

    return [
      'prop id collides with slot name' => [
        'contexts' => [
          'javascript' => 'console.log("x");',
          'props_metadata' => Json::encode([
            ['id' => 'content', 'name' => 'Content', 'type' => 'string', 'example' => 'Some content'],
          ]),
          'slots_metadata' => Json::encode([
            ['id' => 'content', 'name' => 'Content', 'example' => '<p>child</p>'],
          ]),
          'component_machine_name' => 'existing_component',
        ],
        'expected_error' => $nothing_applied . ' ## Component - Component validation errors: component_structure: The component "canvas:existing_component" declared [content] both as a prop and as a slot. Make sure to use different names.',
      ],
      'malformed slots JSON' => [
        'contexts' => [
          'javascript' => 'console.log("x");',
          'props_metadata' => $title_prop,
          'slots_metadata' => 'garbage',
          'component_machine_name' => 'existing_component',
        ],
        'expected_error' => $nothing_applied . ' ## Slots - The slots metadata must be a valid JSON array of slot objects.',
      ],
      'slot missing a name' => [
        'contexts' => [
          'javascript' => 'console.log("x");',
          'props_metadata' => $title_prop,
          'slots_metadata' => Json::encode([
            ['id' => 'children'],
          ]),
          'component_machine_name' => 'existing_component',
        ],
        'expected_error' => $nothing_applied . ' ## Slot 0 - Each slot must include both an "id" and a "name". Slot "children" is missing one of them.',
      ],
      'slot id is not the camelCase of its name' => [
        'contexts' => [
          'javascript' => 'console.log("x");',
          'props_metadata' => $title_prop,
          'slots_metadata' => $accordion_content_slot,
          'component_machine_name' => 'existing_component',
        ],
        'expected_error' => $nothing_applied . ' ## Slot 0 - ' . $accordion_content_error,
      ],
      'every invalid slot is reported' => [
        'contexts' => [
          'javascript' => 'console.log("x");',
          'props_metadata' => $title_prop,
          'slots_metadata' => Json::encode([
            ['id' => 'children'],
            ['id' => 'accordion_content', 'name' => 'Accordion Content', 'example' => '<p>x</p>'],
          ]),
          'component_machine_name' => 'existing_component',
        ],
        'expected_error' => $nothing_applied . ' ## Slot 0 - Each slot must include both an "id" and a "name". Slot "children" is missing one of them. ## Slot 1 - ' . $accordion_content_error,
      ],
      'component does not exist' => [
        'contexts' => [
          'javascript' => 'console.log("x");',
          'props_metadata' => $title_prop,
          'component_machine_name' => 'missing_component',
        ],
        'expected_error' => $nothing_applied . ' ## Component - Could not find component missing_component.',
      ],
      'component does not exist and a slot is invalid' => [
        'contexts' => [
          'javascript' => 'console.log("x");',
          'props_metadata' => $title_prop,
          'slots_metadata' => $accordion_content_slot,
          'component_machine_name' => 'missing_component',
        ],
        'expected_error' => $nothing_applied . ' ## Component - Could not find component missing_component. ## Slot 0 - ' . $accordion_content_error,
      ],
      'invalid prop examples' => [
        'contexts' => [
          'javascript' => 'console.log("Hello World");',
          'props_metadata' => Json::encode([
            ['id' => 'title', 'name' => 'Title', 'type' => 'string', 'example' => 1],
            // 'example' will be transformed into 'examples' array.
            ['id' => 'count', 'name' => 'Count', 'type' => 'integer', 'example' => 'four'],
          ]),
          'component_machine_name' => 'existing_component',
        ],
        'expected_error' => $nothing_applied . ' ## Component - Component validation errors: component_structure: Prop "title" has invalid example value: [] Integer value found, but a string or an object is required component_structure: Prop "count" has invalid example value: [] String value found, but an integer or an object is required component_structure.props.count.examples.0: This value should be of the correct primitive type.',
      ],
      'slot and structure errors are reported together' => [
        'contexts' => [
          'javascript' => 'console.log("x");',
          'props_metadata' => Json::encode([
            ['id' => 'title', 'name' => 'Title', 'type' => 'string', 'example' => 1],
            ['id' => 'count', 'name' => 'Count', 'type' => 'integer', 'example' => 'four'],
          ]),
          'slots_metadata' => $accordion_content_slot,
          'component_machine_name' => 'existing_component',
        ],
        'expected_error' => $nothing_applied . ' ## Slot 0 - ' . $accordion_content_error . ' ## Component - Component validation errors: component_structure: Prop "title" has invalid example value: [] Integer value found, but a string or an object is required component_structure: Prop "count" has invalid example value: [] String value found, but an integer or an object is required component_structure.props.count.examples.0: This value should be of the correct primitive type.',
      ],
    ];
  }

}
