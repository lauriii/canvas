<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_ai\Kernel\Plugin\AiFunctionCall;

use Drupal\ai\Service\FunctionCalling\ExecutableFunctionCallInterface;
use Drupal\canvas\ComponentSource\ComponentSourceManager;
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
   * Invokes the get_component_details tool and returns its readable output.
   */
  private function getComponentDetailsToolOutput(array $component_ids): string {
    return $this->getToolOutput('canvas_ai:get_component_details', ['component_ids_list' => $component_ids]);
  }

}
