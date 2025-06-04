<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Kernel\Plugin\ExperienceBuilder\ComponentSource;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Extension\ModuleInstallerInterface;
use Drupal\experience_builder\Entity\Component;
use Drupal\experience_builder\Entity\ComponentInterface;
use Drupal\experience_builder\Entity\Pattern;
use Drupal\experience_builder\Plugin\ExperienceBuilder\ComponentSource\BlockComponent;
use Drupal\experience_builder\Plugin\ExperienceBuilder\ComponentSource\Fallback;
use Drupal\experience_builder\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\node\Entity\Node;
use Drupal\system\Entity\Menu;
use Drupal\Tests\experience_builder\Traits\BlockComponentTreeTestTrait;
use Drupal\Tests\experience_builder\Traits\ConstraintViolationsTestTrait;
use Drupal\Tests\experience_builder\Traits\CrawlerTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\Entity\User;
use Drupal\xb_test_block\Plugin\Block\XbTestBlockInputNone;
use Drupal\xb_test_block\Plugin\Block\XbTestBlockInputValidatable;
use Drupal\xb_test_block\Plugin\Block\XbTestBlockInputValidatableCrash;
use Drupal\xb_test_block\Plugin\Block\XbTestBlockOptionalContexts;

/**
 * @coversDefaultClass \Drupal\experience_builder\Plugin\ExperienceBuilder\ComponentSource\BlockComponent
 * @group experience_builder
 * @group #slow
 * @phpstan-import-type ComponentConfigEntityId from \Drupal\experience_builder\Entity\Component
 */
final class BlockComponentTest extends ComponentSourceTestBase {

  use BlockComponentTreeTestTrait;
  use ConstraintViolationsTestTrait;
  use CrawlerTrait;
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'block',
    'xb_test_block',
  ];

  /**
   * {@inheritdoc}
   */
  public function setUp(): void {
    parent::setUp();
    // Set up a test user "bob"
    $this->setUpCurrentUser(['name' => 'bob', 'uid' => 2]);
  }

  /**
   * All test module blocks must either have a Component or a reason why not.
   *
   * @covers ::checkRequirements()
   * @covers \Drupal\experience_builder\Plugin\BlockManager::setCachedDefinitions()
   */
  public function testDiscovery(): array {
    // Nothing discovered initially.
    self::assertSame([], $this->findIneligibleComponents(BlockComponent::SOURCE_PLUGIN_ID, 'xb_test_block'));
    self::assertSame([], $this->findCreatedComponentConfigEntities(BlockComponent::SOURCE_PLUGIN_ID, 'xb_test_block'));

    // Trigger component generation, as if the test module was just installed.
    // (Kernel tests don't trigger all hooks that are triggered in reality.)
    $this->generateComponentConfig();

    self::assertSame([
      'block.xb_test_block_input_unvalidatable' => [
        'Block plugin settings must be fully validatable',
      ],
      'block.xb_test_block_requires_contexts' => [
        'Block plugins that require context values are not supported.',
      ],
    ], $this->findIneligibleComponents(BlockComponent::SOURCE_PLUGIN_ID, 'xb_test_block'));
    $auto_created_components = $this->findCreatedComponentConfigEntities(BlockComponent::SOURCE_PLUGIN_ID, 'xb_test_block');
    self::assertSame([
      'block.xb_test_block_input_none',
      'block.xb_test_block_input_validatable',
      'block.xb_test_block_input_validatable_crash',
      'block.xb_test_block_optional_contexts',
    ], $auto_created_components);

    return array_combine($auto_created_components, $auto_created_components);
  }

  /**
   * Tests the 'default_settings' generated for the eligible Block plugins.
   *
   * @depends testDiscovery
   */
  public function testSettings(array $component_ids): void {
    self::assertSame([
      'block.xb_test_block_input_none' => [
        'default_settings' => [
          'id' => 'xb_test_block_input_none',
          'label' => 'Test block with no settings.',
          'label_display' => '0',
          'provider' => 'xb_test_block',
        ],
      ],
      'block.xb_test_block_input_validatable' => [
        'default_settings' => [
          'id' => 'xb_test_block_input_validatable',
          'label' => 'Test Block with settings',
          'label_display' => '0',
          'provider' => 'xb_test_block',
          // This block has a single setting.
          'name' => 'XB',
        ],
      ],
      'block.xb_test_block_input_validatable_crash' => [
        'default_settings' => [
          'id' => 'xb_test_block_input_validatable_crash',
          'label' => "Test Block with settings, crashes when 'crash' setting is TRUE",
          'label_display' => '0',
          'provider' => 'xb_test_block',
          // This block has two settings.
          'name' => 'XB',
          'crash' => FALSE,
        ],
      ],
      'block.xb_test_block_optional_contexts' => [
        'default_settings' => [
          'id' => 'xb_test_block_optional_contexts',
          'label' => 'Test Block with optional contexts',
          'label_display' => '0',
          'provider' => 'xb_test_block',
        ],
      ],
    ], $this->getAllSettings($component_ids));
  }

  /**
   * @param array<ComponentConfigEntityId> $component_ids
   * @covers ::getReferencedPluginClass()
   * @depends testDiscovery
   */
  public function testGetReferencedPluginClass(array $component_ids): void {
    self::assertSame([
      'block.xb_test_block_input_none' => XbTestBlockInputNone::class,
      'block.xb_test_block_input_validatable' => XbTestBlockInputValidatable::class,
      'block.xb_test_block_input_validatable_crash' => XbTestBlockInputValidatableCrash::class,
      'block.xb_test_block_optional_contexts' => XbTestBlockOptionalContexts::class,
    ], $this->getReferencedPluginClasses($component_ids));
  }

  /**
   * @covers ::componentIdFromBlockPluginId()
   * @testWith ["foo", "block.foo"]
   *           ["system_menu_block:footer", "block.system_menu_block.footer"]
   */
  public function testComponentIdFromBlockPluginId(string $input, string $expected_output): void {
    self::assertSame($expected_output, BlockComponent::componentIdFromBlockPluginId($input));
  }

  /**
   * @param array<ComponentConfigEntityId> $component_ids
   * @covers ::renderComponent()
   * @depends testDiscovery
   */
  public function testRenderComponentLive(array $component_ids): void {
    $this->assertNotEmpty($component_ids);
    $rendered = $this->renderComponentsLive(
      $component_ids,
      get_default_input: fn (Component $component) => [BlockComponent::EXPLICIT_INPUT_NAME => $component->getSettings()['default_settings']],
    );

    $default_render_cache_contexts = [
      'languages:language_interface',
      'theme',
      'user.permissions',
    ];
    $default_cacheability = (new CacheableMetadata())
      ->setCacheContexts($default_render_cache_contexts);
    $this->assertEquals([
      'block.xb_test_block_input_none' => [
        'html' => <<<HTML
<div id="block-some-uuid">


      <div>Hello bob, from XB!</div>
  </div>

HTML,
        'cacheability' => (clone $default_cacheability)
          // @phpstan-ignore-next-line
          ->addCacheableDependency(User::load(2))
          ->setCacheContexts([
            'languages:language_interface',
            'theme',
            'user',
            'user.permissions',
          ]),
        'attachments' => [],
      ],
      'block.xb_test_block_input_validatable' => [
        'html' => <<<HTML
<div id="block-some-uuid--2">


      <div>Hello, XB!</div>
  </div>

HTML,
        'cacheability' => $default_cacheability,
        'attachments' => [],
      ],
      'block.xb_test_block_input_validatable_crash' => [
        'html' => <<<HTML
<div id="block-some-uuid--3">


      <div>Hello, XB!</div>
  </div>

HTML,
        'cacheability' => $default_cacheability,
        'attachments' => [],
      ],
      'block.xb_test_block_optional_contexts' => [
        'html' => <<<HTML
<div id="block-some-uuid--4">


      Test Block with optional context value: @todo in https://www.drupal.org/i/3485502
  </div>

HTML,
        'cacheability' => $default_cacheability,
        'attachments' => [],
      ],
    ], $rendered);
  }

  /**
   * {@inheritdoc}
   */
  public static function getExpectedClientSideInfo(): array {
    return [
      'block.xb_test_block_input_none' => [
        'expected_output_selectors' => ['div:contains("Hello bob, from XB!")'],
      ],
      'block.xb_test_block_input_validatable' => [
        'expected_output_selectors' => ['div:contains("Hello, XB!")'],
      ],
      'block.xb_test_block_input_validatable_crash' => [
        'expected_output_selectors' => ['div:contains("Hello, XB!")'],
      ],
      'block.xb_test_block_optional_contexts' => [
        'expected_output_selectors' => ['div:contains("Test Block with optional context value: @todo in https://www.drupal.org/i/3485502")'],
      ],
    ];
  }

  /**
   * @covers ::getExplicitInput()
   * @dataProvider getValidTreeTestCases
   */
  public function testGetExplicitInput(array $componentItemValue): void {
    $this->generateComponentConfig();

    $this->container->get('module_installer')->install(['xb_test_config_node_article']);
    $node = Node::create([
      'title' => 'Test node',
      'type' => 'article',
      'field_xb_test' => $componentItemValue,
    ]);
    $node->save();
    $xb_field_item = $node->field_xb_test[0];
    $this->assertInstanceOf(ComponentTreeItem::class, $xb_field_item);

    $component = $xb_field_item->getComponent();
    assert($component instanceof Component);

    $explicit = $component->getComponentSource()->getExplicitInput($xb_field_item->getUuid(), $xb_field_item);
    $componentSettings = $explicit;
    $componentSettingsOriginal = $componentItemValue[0]['inputs'];

    $this->assertSame($componentSettingsOriginal, $componentSettings);
  }

  public static function providerRenderComponentFailure(): \Generator {
    $block_settings = [
      'label' => 'crash dummy',
      'label_display' => FALSE,
      'name' => 'XB',
    ];

    yield "Block with valid props, without exception" => [
      'component_id' => 'block.xb_test_block_input_validatable_crash',
      'inputs' => [
        'crash' => FALSE,
      ] + $block_settings,
      'expected_validation_errors' => [],
      'expected_exception' => NULL,
      'expected_output_selector' => \sprintf('[id*="block-%s"]:contains("Hello, XB!")', static::UUID_CRASH_TEST_DUMMY),
    ];

    yield "Block with valid props, with exception" => [
      'component_id' => 'block.xb_test_block_input_validatable_crash',
      'inputs' => [
        'crash' => TRUE,
      ] + $block_settings,
      'expected_validation_errors' => [],
      'expected_exception' => [
        'class' => \Exception::class,
        'message' => "Intentional test exception.",
      ],
      'expected_output_selector' => NULL,
    ];
  }

  /**
   * @covers ::calculateDependencies()
   * @depends testDiscovery
   */
  public function testCalculateDependencies(array $component_ids): void {
    // Note: the module providing the Block plugin is depended upon directly.
    // @see \Drupal\experience_builder\Entity\Component::$provider
    $dependencies = ['module' => ['xb_test_block']];
    self::assertSame([
      'block.xb_test_block_input_none' => $dependencies,
      'block.xb_test_block_input_validatable' => $dependencies,
      'block.xb_test_block_input_validatable_crash' => $dependencies,
      'block.xb_test_block_optional_contexts' => $dependencies,
    ], $this->callSourceMethodForEach('calculateDependencies', $component_ids));
  }

  protected function createAndSaveInUseComponentForFallbackTesting(): ComponentInterface {
    $this->generateComponentConfig();
    /** @var \Drupal\experience_builder\Entity\ComponentInterface */
    return Component::load('block.xb_test_block_input_none');
  }

  protected function createAndSaveUnusedComponentForFallbackTesting(): ComponentInterface {
    /** @var \Drupal\experience_builder\Entity\ComponentInterface */
    return Component::load('block.xb_test_block_input_validatable');
  }

  protected function forceComponentFallback(ComponentInterface $used_component, ComponentInterface $unused_component): void {
    \Drupal::service(ModuleInstallerInterface::class)->uninstall(['xb_test_block']);
  }

  protected function recoverComponentFallback(ComponentInterface $component): void {
    \Drupal::service(ModuleInstallerInterface::class)->install(['xb_test_block']);
    $this->generateComponentConfig();
  }

  /**
   * @covers ::onDependencyRemoval()
   */
  public function testConfigDependencyDelete(): void {
    // Install the default menus provided by system.module.
    $this->installConfig(['system']);
    $this->generateComponentConfig();

    $this->assertContains('block.system_menu_block.footer', $this->findCreatedComponentConfigEntities(BlockComponent::SOURCE_PLUGIN_ID, 'system'));

    $menu = $this->config('experience_builder.component.block.system_menu_block.footer');
    $this->assertSame([
      'config' => ['system.menu.footer'],
      'module' => ['system'],
    ], $menu->get('dependencies'));

    $menu = Menu::load('footer');
    assert($menu instanceof Menu);

    // Deleting dependency of unused Component results in deletion of Component.
    $menu->delete();
    $this->assertNotContains('block.system_menu_block.footer', $this->findCreatedComponentConfigEntities(BlockComponent::SOURCE_PLUGIN_ID, 'system'));

    $this->assertContains('block.system_menu_block.main', $this->findCreatedComponentConfigEntities(BlockComponent::SOURCE_PLUGIN_ID, 'system'));
    $menu = $this->config('experience_builder.component.block.system_menu_block.main');
    $this->assertSame([
      'config' => ['system.menu.main'],
      'module' => ['system'],
    ], $menu->get('dependencies'));
    Pattern::create([
      'id' => 'test_pattern',
      'label' => 'Test pattern',
      'component_tree' => [
        [
          'uuid' => '75144f9b-1bfc-4874-b848-b5889f066217',
          'component_id' => 'block.system_menu_block.main',
          'component_version' => '1890264ee53dc1f4',
          'inputs' => [
            'label' => 'Main navigation',
            'label_display' => '',
            'level' => 1,
            'depth' => 0,
            'expand_all_items' => TRUE,
          ],
        ],
      ],
    ])->save();

    $menu = Menu::load('main');
    assert($menu instanceof Menu);

    // Deleting dependency of used Component results in "fallback" Component.
    $menu->delete();
    $this->assertContains('block.system_menu_block.main', $this->findCreatedComponentConfigEntities(BlockComponent::SOURCE_PLUGIN_ID, 'system'));
    $component = Component::load('block.system_menu_block.main');
    assert($component instanceof Component);
    $this->assertFalse($component->status());
    $this->assertTrue($component->getComponentSource() instanceof Fallback);
  }

  /**
   * @covers ::onDependencyRemoval()
   */
  public function testPluginDependencyUninstall(): void {
    $this->generateComponentConfig();

    // Component entity based on block, unused.
    $this->assertContains('block.xb_test_block_input_none', $this->findCreatedComponentConfigEntities(BlockComponent::SOURCE_PLUGIN_ID, 'xb_test_block'));

    // Component entity based on block, used in Pattern.
    $this->assertContains('block.xb_test_block_input_validatable', $this->findCreatedComponentConfigEntities(BlockComponent::SOURCE_PLUGIN_ID, 'xb_test_block'));
    Pattern::create([
      'id' => 'test_pattern',
      'label' => 'Test pattern',
      'component_tree' => [
        [
          'uuid' => '4b26c295-c8cc-4b2d-a38a-235c6cfa1ffa',
          'component_id' => 'block.xb_test_block_input_validatable',
          'component_version' => '9bc2091e7da4816c',
          'inputs' => [
            'name' => 'test',
            'label' => 'test',
            'label_display' => '',
          ],
        ],
      ],
    ])->save();

    $this->container->get('module_installer')->uninstall(['xb_test_block']);

    $this->assertNotContains('block.xb_test_block_input_none', $this->findCreatedComponentConfigEntities(BlockComponent::SOURCE_PLUGIN_ID, 'xb_test_block'));
    $this->assertContains('block.xb_test_block_input_validatable', $this->findCreatedComponentConfigEntities(BlockComponent::SOURCE_PLUGIN_ID, 'xb_test_block'));
    $component = Component::load('block.xb_test_block_input_validatable');
    assert($component instanceof Component);
    $this->assertFalse($component->status());
    $this->assertTrue($component->getComponentSource() instanceof Fallback);
  }

  /**
   * @covers \Drupal\experience_builder\Plugin\BlockManager::setCachedDefinitions()
   */
  public function testDependencyUpdate(): void {
    // Install the default menus provided by system.module.
    $this->installConfig(['system']);
    $this->generateComponentConfig();

    $config = 'experience_builder.component.block.system_menu_block.footer';
    $this->assertSame('Footer', $this->config($config)->get('label'));

    $menu = Menu::load('footer');
    assert($menu instanceof Menu);
    $label = 'Old footer menu';
    $menu->set('label', $label)->save();

    $this->generateComponentConfig();

    $this->assertSame($label, $this->config($config)->get('label'));
  }

}
