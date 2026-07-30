<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_ai\Kernel\Plugin\AiFunctionCall;

use Drupal\ai\Service\FunctionCalling\ExecutableFunctionCallInterface;
use Drupal\canvas_ai\CanvasAiPageBuilderHelper;
use Drupal\canvas_ai\CanvasAiPermissions;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\canvas\Traits\CreateTestJsComponentTrait;
use Drupal\Tests\canvas\Traits\GenerateComponentConfigTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\Yaml\Yaml;

/**
 * Tests for the GetComponentContext function call plugin.
 */
#[Group('canvas_ai')]
#[RunTestsInSeparateProcesses]
final class GetComponentContextTest extends CanvasKernelTestBase {

  use CreateTestJsComponentTrait;
  use GenerateComponentConfigTrait;
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
    $this->installEntitySchema('path_alias');
    $this->installEntitySchema('user');

    $this->functionCallManager = $this->container->get('plugin.manager.ai.function_calls');
    $privileged_user = $this->createUser([CanvasAiPermissions::USE_CANVAS_AI]);
    $unprivileged_user = $this->createUser();
    if (!$privileged_user instanceof User || !$unprivileged_user instanceof User) {
      throw new \Exception('Failed to create test users');
    }
    $this->privilegedUser = $privileged_user;
    $this->unprivilegedUser = $unprivileged_user;
  }

  /**
   * Tests getting component context with proper permissions.
   */
  public function testGetComponentContextWithPermissions(): void {
    $this->container->get(AccountProxyInterface::class)->setAccount($this->privilegedUser);
    $mock_helper = $this->createMock(CanvasAiPageBuilderHelper::class);
    $test_data = [
      'test_component' => [
        'name' => 'Test Component',
        'description' => 'A test component for testing',
        'id' => 'test_component',
      ],
    ];
    $expected_yaml = Yaml::dump($test_data, 4, 2);
    $mock_helper->expects($this->once())
      ->method('getComponentContextForAi')
      ->willReturn($expected_yaml);
    $this->container->set('canvas_ai.page_builder_helper', $mock_helper);
    $tool = $this->functionCallManager->createInstance('canvas_ai:get_component_context');
    $this->assertInstanceOf(ExecutableFunctionCallInterface::class, $tool);
    $tool->execute();
    $result = $tool->getReadableOutput();
    $this->assertEquals($expected_yaml, $result);
  }

  /**
   * Tests getting component context without proper permissions.
   */
  public function testGetComponentContextWithoutPermissions(): void {
    $this->container->get(AccountProxyInterface::class)->setAccount($this->unprivilegedUser);
    $tool = $this->functionCallManager->createInstance('canvas_ai:get_component_context');
    $this->assertInstanceOf(ExecutableFunctionCallInterface::class, $tool);
    // Expect an exception to be thrown.
    $this->expectException(\Exception::class);
    $this->expectExceptionMessage('The current user does not have the right permissions to run this tool.');
    $tool->execute();
  }

  /**
   * Tests that the tool calls the page builder helper service.
   */
  public function testPageBuilderHelperServiceCall(): void {
    $this->container->get(AccountProxyInterface::class)->setAccount($this->privilegedUser);

    // The expectation is verified by the mock - if getComponentContextForAi
    // wasn't called exactly once, the test would fail.
    $mockHelper = $this->createMock(CanvasAiPageBuilderHelper::class);
    $mockHelper->expects($this->once())
      ->method('getComponentContextForAi')
      ->willReturn(Yaml::dump(['test' => 'data'], 4, 2));

    $this->container->set('canvas_ai.page_builder_helper', $mockHelper);
    $tool = $this->functionCallManager->createInstance('canvas_ai:get_component_context');
    $this->assertInstanceOf(ExecutableFunctionCallInterface::class, $tool);
    $tool->execute();
  }

  /**
   * Tests that required SDC props include required: true; optional do not.
   */
  public function testSdcComponentRequiredPropsInContext(): void {
    $this->container->get(AccountProxyInterface::class)->setAccount($this->privilegedUser);
    $this->generateComponentConfig();

    $yaml = $this->container->get('canvas_ai.page_builder_helper')
      ->getComponentContextForAi();
    $context = Yaml::parse($yaml) ?? [];

    // canvas_test_sdc/card has required: [image, loading]; heading is optional.
    $card_id = 'sdc.canvas_test_sdc.card';
    $this->assertArrayHasKey($card_id, $context);
    $props = $context[$card_id]['props'] ?? [];

    $this->assertArrayHasKey('loading', $props);
    $this->assertTrue($props['loading']['required'] ?? FALSE, 'Required prop loading must have required: true.');

    $this->assertArrayHasKey('heading', $props);
    $this->assertArrayNotHasKey('required', $props['heading'], 'Optional prop heading must not have a required key.');
  }

  /**
   * Tests that required code component props include required: true.
   */
  public function testCodeComponentRequiredPropsInContext(): void {
    $this->container->get(AccountProxyInterface::class)->setAccount($this->privilegedUser);
    $this->createMyCtaComponentFromSdc();
    $this->generateComponentConfig();

    $yaml = $this->container->get('canvas_ai.page_builder_helper')
      ->getComponentContextForAi();
    $context = Yaml::parse($yaml) ?? [];

    // my-cta has required: [text, href]; target is optional.
    $cta_id = 'js.my-cta';
    $this->assertArrayHasKey($cta_id, $context);
    $props = $context[$cta_id]['props'] ?? [];

    $this->assertArrayHasKey('text', $props);
    $this->assertTrue($props['text']['required'] ?? FALSE, 'Required prop text must have required: true.');

    $this->assertArrayHasKey('target', $props);
    $this->assertArrayNotHasKey('required', $props['target'], 'Optional prop target must not have a required key.');
  }

  /**
   * Tests component context stored in config gets required props on next load.
   *
   * When component context is already stored in config (by saving the component
   * description settings form), the next getComponentContextForAi() load
   * refreshes the config and adds the required props.
   *
   * @see \Drupal\canvas_ai\Form\CanvasAiComponentDescriptionSettingsForm
   * @see \Drupal\canvas_ai\CanvasAiPageBuilderHelper::refreshComponentContext()
   */
  public function testRequiredPropSurvivesSettingsConfig(): void {
    $this->container->get(AccountProxyInterface::class)->setAccount($this->privilegedUser);
    $this->generateComponentConfig();

    // Stored config from before the required flag existed: no required key, and
    // an edited description.
    $card_id = 'sdc.canvas_test_sdc.card';
    $this->config('canvas_ai.component_description.settings')
      ->set('langcode', 'en')
      ->set('component_context', [
        'sdc' => [
          'enabled' => TRUE,
          'data' => Yaml::dump([
            $card_id => [
              'props' => [
                'loading' => ['name' => 'Loading', 'description' => 'Edited description'],
              ],
            ],
          ]),
        ],
      ])->save();

    $yaml = $this->container->get('canvas_ai.page_builder_helper')
      ->getComponentContextForAi();
    $context = Yaml::parse($yaml) ?? [];

    // Required is added back; the edited description is kept.
    $loading = $context[$card_id]['props']['loading'] ?? [];
    $this->assertTrue($loading['required'] ?? FALSE, 'Required prop loading must have required: true.');
    $this->assertSame('Edited description', $loading['description'] ?? '', 'Edited description must be preserved.');

    // The flag is also written back to the stored config.
    $this->container->get(ConfigFactoryInterface::class)->reset('canvas_ai.component_description.settings');
    $stored = Yaml::parse($this->config('canvas_ai.component_description.settings')
      ->get('component_context')['sdc']['data']);
    $this->assertTrue($stored[$card_id]['props']['loading']['required'] ?? FALSE, 'Required flag must be persisted to config.');
  }

}
