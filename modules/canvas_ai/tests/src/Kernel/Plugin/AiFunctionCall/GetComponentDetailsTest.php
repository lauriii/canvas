<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_ai\Kernel\Plugin\AiFunctionCall;

use Drupal\ai\Service\FunctionCalling\ExecutableFunctionCallInterface;
use Drupal\canvas\ComponentSource\ComponentSourceManager;
use Drupal\canvas\Entity\JavaScriptComponent;
use Drupal\canvas_ai\CanvasAiPermissions;
use Drupal\canvas_ai\Plugin\AiFunctionCall\GetComponentDetails;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\canvas\Traits\CreateTestJsComponentTrait;
use Drupal\Tests\canvas_ai\Traits\FunctionalCallTestTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Yaml\Yaml;

/**
 * Tests for the GetComponentDetails function call plugin.
 */
#[Group('canvas_ai')]
final class GetComponentDetailsTest extends CanvasKernelTestBase {

  use CreateTestJsComponentTrait;
  use FunctionalCallTestTrait;
  use UserCreationTrait;

  /**
   * The function call plugin manager.
   *
   * @var \Drupal\Component\Plugin\PluginManagerInterface
   */
  protected $functionCallManager;

  /**
   * A test user with AI permissions.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected AccountInterface $privilegedUser;

  /**
   * A test user without AI permissions.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected AccountInterface $unprivilegedUser;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'ai',
    'ai_agents',
    'canvas_ai',
    'canvas_ai_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('path_alias');
    $this->container->get(ComponentSourceManager::class)->generateComponents();
    $this->createTestCodeComponent();

    $this->functionCallManager = $this->container->get('plugin.manager.ai.function_calls');
    $privileged_user = $this->createUser([CanvasAiPermissions::USE_CANVAS_AI]);
    $unprivileged_user = $this->createUser();
    if (!$privileged_user instanceof User || !$unprivileged_user instanceof User) {
      throw new \Exception('Failed to create test users');
    }
    $this->privilegedUser = $privileged_user;
    $this->unprivilegedUser = $unprivileged_user;
    $this->container->get(ConfigFactoryInterface::class)
      ->getEditable('system.theme')
      ->set('default', 'stark')
      ->save();
  }

  /**
   * Tests getting component details for valid SDC, block, and JS ids.
   */
  public function testGetComponentDetailsWithValidIds(): void {
    $this->container->get(AccountProxyInterface::class)->setAccount($this->privilegedUser);

    $tool = $this->functionCallManager->createInstance('canvas_ai:get_component_details');
    $this->assertInstanceOf(GetComponentDetails::class, $tool);

    // sdc.canvas_test_sdc.two_column is included alongside my-hero because it
    // has slots; my-hero, the block, and the JS component do not.
    $component_ids = [
      'sdc.canvas_test_sdc.my-hero',
      'sdc.canvas_test_sdc.two_column',
      'block.system_breadcrumb_block',
      'js.test-code-component',
    ];
    $tool->setContextValue('component_ids_list', $component_ids);
    $tool->execute();
    $result = $tool->getReadableOutput();
    $decoded = Yaml::parse($result);
    $this->assertIsArray($decoded);

    foreach ($component_ids as $component_id) {
      $this->assertArrayHasKey($component_id, $decoded);
    }

    // The SDC's description is returned as-is.
    $hero = $decoded['sdc.canvas_test_sdc.my-hero'];
    $this->assertSame('A Hero section with inline styles for testing purposes', $hero['description']);

    // The SDC's full props structure is returned as-is, including the
    // required prop details.
    $this->assertSame([
      'heading' => [
        'name' => 'Heading',
        'description' => 'The main heading of the hero',
        'type' => 'string',
        'default' => 'There goes my hero',
        'required' => TRUE,
      ],
      'subheading' => [
        'name' => 'Sub-heading',
        'description' => 'See the <a href="https://www.example.com/icons">icon library</a> for icons.',
        'type' => 'string',
        'default' => 'Watch him as he goes!',
      ],
      'cta1' => [
        'name' => 'CTA 1 text',
        'description' => 'No description available',
        'type' => 'string',
        'default' => 'View',
      ],
      'cta1href' => [
        'name' => 'CTA 1 link',
        'description' => 'No description available',
        'type' => 'string',
        'default' => 'https://example.com',
        'required' => TRUE,
      ],
      'cta2' => [
        'name' => 'CTA 2 text',
        'description' => 'No description available',
        'type' => 'string',
        'default' => 'Click',
      ],
    ], $hero['props']);

    // The SDC's full slots structure is returned as-is.
    $this->assertSame([
      'column_one' => [
        'name' => 'Column One',
        'description' => 'The contents of the first column.',
      ],
      'column_two' => [
        'name' => 'Column Two',
        'description' => 'The contents of the second column.',
      ],
    ], $decoded['sdc.canvas_test_sdc.two_column']['slots']);

    // The block's full structure is returned as-is; blocks have no slots.
    $this->assertSame([
      'id' => 'block.system_breadcrumb_block',
      'name' => 'Breadcrumbs',
      'description' => 'Breadcrumbs',
      'props' => [
        'label' => [
          'name' => 'Description',
          'description' => 'Description',
          'type' => 'label',
          'default' => 'Breadcrumbs',
          'required' => TRUE,
        ],
        'label_display' => [
          'name' => 'Display title',
          'description' => 'Display title',
          'type' => 'string',
          'default' => '0',
          'required' => TRUE,
          'enum' => ['0', 'visible'],
        ],
      ],
    ], $decoded['block.system_breadcrumb_block']);

    // The JS component's full structure is returned as-is.
    $this->assertSame([
      'id' => 'js.test-code-component',
      'name' => 'Test Code Component',
      'description' => 'Test Code Component',
      'props' => [
        'heading' => [
          'name' => 'heading',
          'description' => 'heading',
          'type' => 'string',
          'default' => 'Example Heading',
          'format' => '',
          'enum' => '',
        ],
        'content' => [
          'name' => 'content',
          'description' => 'content',
          'type' => 'string',
          'default' => 'Example Content',
          'format' => '',
          'enum' => '',
        ],
      ],
      'slots' => [],
    ], $decoded['js.test-code-component']);
  }

  /**
   * Tests the item schema and item limits of multi-value props.
   */
  public function testGetComponentDetailsWithMultiValueProps(): void {
    $this->container->get(AccountProxyInterface::class)->setAccount($this->privilegedUser);
    JavaScriptComponent::create([
      'machineName' => 'multi-value-code-component',
      'name' => 'Multi-value Code Component',
      'status' => TRUE,
      'props' => [
        'tags' => [
          'title' => 'Tags',
          'type' => 'array',
          'minItems' => 1,
          'maxItems' => 3,
          'items' => ['type' => 'string'],
          'examples' => [['Drupal', 'Canvas']],
        ],
        'links' => [
          'title' => 'Links',
          'type' => 'array',
          'items' => ['type' => 'string', 'format' => 'uri'],
          'examples' => [['https://drupal.org']],
        ],
        'sizes' => [
          'title' => 'Sizes',
          'type' => 'array',
          'items' => [
            'type' => 'string',
            'enum' => ['small', 'large'],
            'meta:enum' => ['small' => 'Small size', 'large' => 'Large size'],
          ],
          'examples' => [['small']],
        ],
      ],
      'required' => ['tags'],
      'js' => [
        'original' => 'export default function MultiValueCodeComponent() { return null; }',
        'compiled' => 'export default function MultiValueCodeComponent() { return null; }',
      ],
      'css' => ['original' => '', 'compiled' => ''],
      'dataDependencies' => [],
    ])->save();

    $decoded = Yaml::parse($this->getComponentDetailsToolOutput([
      'sdc.canvas_test_sdc.multivalue-props',
      'sdc.canvas_test_sdc.image-gallery',
      'sdc.canvas_test_sdc.sparkline',
      'sdc.canvas_ai_test.formatted-text-list',
      'js.multi-value-code-component',
    ]));
    $this->assertIsArray($decoded);

    $string = ['type' => 'string'];
    $uri = ['type' => 'string', 'format' => 'uri'];
    $uri_reference = ['type' => 'string', 'format' => 'uri-reference'];
    $date_time = ['type' => 'string', 'format' => 'date-time'];
    $date = ['type' => 'string', 'format' => 'date'];
    $number = ['type' => 'number'];
    $integer = ['type' => 'integer'];
    $list_text = [
      'type' => 'string',
      'enum' => ['option_one', 'option_two', 'option_three', 'option_four'],
      'enum_options' => [
        ['label' => 'Option One', 'value' => 'option_one'],
        ['label' => 'Option Two', 'value' => 'option_two'],
        ['label' => 'Option Three', 'value' => 'option_three'],
        ['label' => 'Option Four', 'value' => 'option_four'],
      ],
    ];
    $list_int = [
      'type' => 'integer',
      'enum' => [10, 20, 30, 40],
      'enum_options' => [
        ['label' => 'Ten', 'value' => 10],
        ['label' => 'Twenty', 'value' => 20],
        ['label' => 'Thirty', 'value' => 30],
        ['label' => 'Forty', 'value' => 40],
      ],
    ];

    // Each SDC multi-value prop gets its item schema, and the item limits only
    // when it sets them.
    $this->assertSame([
      'text' => ['items' => $string],
      'text_limited' => ['items' => $string, 'maxItems' => 3],
      'text_required' => ['items' => $string, 'minItems' => 1],
      'link' => ['items' => $uri],
      'link_limited' => ['items' => $uri, 'maxItems' => 3],
      'relative_link' => ['items' => $uri_reference],
      'relative_link_limited' => ['items' => $uri_reference, 'maxItems' => 3],
      'number' => ['items' => $number],
      'number_limited' => ['items' => $number, 'maxItems' => 3],
      'integer' => ['items' => $integer],
      'integer_limited' => ['items' => $integer, 'maxItems' => 3],
      'datetime' => ['items' => $date_time],
      'datetime_limited' => ['items' => $date_time, 'maxItems' => 3],
      'date' => ['items' => $date],
      'date_limited' => ['items' => $date, 'maxItems' => 3],
      'list_text' => ['items' => $list_text],
      'list_text_limited' => ['items' => $list_text, 'maxItems' => 3],
      'list_int' => ['items' => $list_int],
      'list_int_limited' => ['items' => $list_int, 'maxItems' => 3],
    ], self::getMultiValueKeys($decoded['sdc.canvas_test_sdc.multivalue-props']['props']));

    // A single-value prop gets none of these keys.
    $this->assertSame([
      'caption' => [],
      'images' => [
        'items' => [
          'type' => 'object',
          'properties' => [
            'src' => [
              'type' => 'string',
              'contentMediaType' => 'image/*',
              'format' => 'uri-reference',
              'x-allowed-schemes' => ['http', 'https'],
            ],
            'alt' => ['type' => 'string'],
            'width' => ['type' => 'integer'],
            'height' => ['type' => 'integer'],
          ],
          'required' => ['src'],
        ],
        'minItems' => 1,
      ],
    ], self::getMultiValueKeys($decoded['sdc.canvas_test_sdc.image-gallery']['props']));
    $this->assertSame([
      'data' => [
        'items' => ['type' => 'integer', 'maximum' => 100, 'minimum' => -100],
        'minItems' => 1,
        'maxItems' => 100,
      ],
    ], self::getMultiValueKeys($decoded['sdc.canvas_test_sdc.sparkline']['props']));

    // The default of a multi-value formatted text prop is a list of HTML
    // strings.
    $this->assertSame([
      'name' => 'Paragraphs',
      'description' => 'No description available',
      'type' => 'array',
      'default' => ['<p>First <strong>paragraph</strong></p>', '<p>Second paragraph</p>'],
      'items' => ['type' => 'string', 'contentMediaType' => 'text/html', 'x-formatting-context' => 'block'],
      'maxItems' => 3,
    ], $decoded['sdc.canvas_ai_test.formatted-text-list']['props']['paragraphs']);

    // Code component multi-value props get the same keys.
    $this->assertSame([
      'tags' => ['items' => $string, 'minItems' => 1, 'maxItems' => 3],
      'links' => ['items' => $uri],
      'sizes' => [
        'items' => [
          'type' => 'string',
          'enum' => ['small', 'large'],
          'enum_options' => [
            ['label' => 'Small size', 'value' => 'small'],
            ['label' => 'Large size', 'value' => 'large'],
          ],
        ],
      ],
    ], self::getMultiValueKeys($decoded['js.multi-value-code-component']['props']));

    // get_component_context with catalog_only off returns the same props.
    $context = Yaml::parse($this->getToolOutput('canvas_ai:get_component_context', ['catalog_only' => FALSE]));
    $this->assertIsArray($context);
    foreach ($decoded as $component_id => $component) {
      $this->assertSame($component, $context[$component_id]);
    }
  }

  /**
   * Tests that the component catalog strips props and slots.
   */
  public function testGetComponentCatalog(): void {
    $this->container->get(AccountProxyInterface::class)->setAccount($this->privilegedUser);

    $catalog = Yaml::parse($this->container->get('canvas_ai.component_context_helper')->getComponentCatalog());
    $this->assertIsArray($catalog);

    $this->assertSame([
      'name' => 'Hero',
      'description' => 'A Hero section with inline styles for testing purposes',
    ], $catalog['sdc.canvas_test_sdc.my-hero']);
    $this->assertSame([
      'name' => 'Two Column',
      'description' => 'Two Column',
    ], $catalog['sdc.canvas_test_sdc.two_column']);
    $this->assertSame([
      'name' => 'Breadcrumbs',
      'description' => 'Breadcrumbs',
    ], $catalog['block.system_breadcrumb_block']);
    $this->assertSame([
      'name' => 'Test Code Component',
      'description' => 'Test Code Component',
    ], $catalog['js.test-code-component']);
  }

  /**
   * Tests getting component details for a made-up component id.
   */
  public function testGetComponentDetailsWithInvalidId(): void {
    $this->container->get(AccountProxyInterface::class)->setAccount($this->privilegedUser);

    $result = $this->getComponentDetailsToolOutput(['invalid.component.id']);
    $this->assertSame('Component with id "invalid.component.id" does not exist.', $result);
    $this->assertStringNotContainsString('sdc.canvas_test_sdc.my-hero', $result);
  }

  /**
   * Tests that one valid id alongside an invalid id returns no component data.
   */
  public function testGetComponentDetailsWithValidAndInvalidIds(): void {
    $this->container->get(AccountProxyInterface::class)->setAccount($this->privilegedUser);

    $result = $this->getComponentDetailsToolOutput([
      'sdc.canvas_test_sdc.my-hero',
      'invalid.component.id',
    ]);
    $this->assertStringContainsString('invalid.component.id', $result);
    $this->assertStringNotContainsString('sdc.canvas_test_sdc.my-hero', $result);
  }

  /**
   * Tests getting component details without proper permissions.
   */
  public function testGetComponentDetailsWithoutPermissions(): void {
    $this->container->get(AccountProxyInterface::class)->setAccount($this->unprivilegedUser);

    $tool = $this->functionCallManager->createInstance('canvas_ai:get_component_details');
    $this->assertInstanceOf(ExecutableFunctionCallInterface::class, $tool);

    $this->expectException(\Exception::class);
    $this->expectExceptionMessage('The current user does not have the right permissions to run this tool.');

    $tool->setContextValue('component_ids_list', ['sdc.canvas_test_sdc.my-hero']);
    $tool->execute();
  }

  /**
   * Keeps only the multi-value keys of each prop.
   *
   * @param array $props
   *   The props of a component, keyed by prop name.
   *
   * @return array
   *   The 'items', 'minItems' and 'maxItems' keys of each prop, keyed by prop
   *   name.
   */
  private static function getMultiValueKeys(array $props): array {
    return \array_map(static fn (array $prop): array => \array_intersect_key($prop, \array_flip(['items', 'minItems', 'maxItems'])), $props);
  }

  /**
   * Invokes the get_component_details tool and returns its readable output.
   */
  private function getComponentDetailsToolOutput(array $component_ids): string {
    return $this->getToolOutput('canvas_ai:get_component_details', ['component_ids_list' => $component_ids]);
  }

}
