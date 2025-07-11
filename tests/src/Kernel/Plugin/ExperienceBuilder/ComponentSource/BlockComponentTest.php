<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Kernel\Plugin\ExperienceBuilder\ComponentSource;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\experience_builder\Entity\Component;
use Drupal\experience_builder\Entity\ComponentInterface;
use Drupal\experience_builder\Plugin\ExperienceBuilder\ComponentSource\BlockComponent;
use Drupal\experience_builder\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\node\Entity\Node;
use Drupal\system\Entity\Menu;
use Drupal\Tests\experience_builder\Traits\BlockComponentTreeTestTrait;
use Drupal\Tests\experience_builder\Traits\ConstraintViolationsTestTrait;
use Drupal\Tests\experience_builder\Traits\CrawlerTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\Entity\User;
use Drupal\xb_test_block\Plugin\Block\XbTestBlockInputNone;
use Drupal\xb_test_block\Plugin\Block\XbTestBlockInputSchemaChangePoc;
use Drupal\xb_test_block\Plugin\Block\XbTestBlockInputValidatable;
use Drupal\xb_test_block\Plugin\Block\XbTestBlockInputValidatableCrash;
use Drupal\xb_test_block\Plugin\Block\XbTestBlockOptionalContexts;

/**
 * @coversDefaultClass \Drupal\experience_builder\Plugin\ExperienceBuilder\ComponentSource\BlockComponent
 * @group experience_builder
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
    'node',
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
        'Block plugin settings must opt into strict validation. Use the FullyValidatable constraint. See https://www.drupal.org/node/3404425',
      ],
      'block.xb_test_block_requires_contexts' => [
        'Block plugins that require context values are not supported.',
      ],
    ], $this->findIneligibleComponents(BlockComponent::SOURCE_PLUGIN_ID, 'xb_test_block'));
    $auto_created_components = $this->findCreatedComponentConfigEntities(BlockComponent::SOURCE_PLUGIN_ID, 'xb_test_block');
    self::assertSame([
      'block.xb_test_block_input_none',
      'block.xb_test_block_input_schema_change_poc',
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
      'block.xb_test_block_input_schema_change_poc' => [
        'default_settings' => [
          'id' => 'xb_test_block_input_schema_change_poc',
          'label' => 'Test block for Input Schema Change POC.',
          'label_display' => '0',
          'provider' => 'xb_test_block',
          'foo' => 'bar',
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
      'block.xb_test_block_input_schema_change_poc' => XbTestBlockInputSchemaChangePoc::class,
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
      'block.xb_test_block_input_schema_change_poc' => [
        'html' => <<<HTML
<div id="block-some-uuid--2">


      Current foo value: bar
  </div>

HTML,
        'cacheability' => $default_cacheability,
        'attachments' => [],
      ],
      'block.xb_test_block_input_validatable' => [
        'html' => <<<HTML
<div id="block-some-uuid--3">


      <div>Hello, XB!</div>
  </div>

HTML,
        'cacheability' => $default_cacheability,
        'attachments' => [],
      ],
      'block.xb_test_block_input_validatable_crash' => [
        'html' => <<<HTML
<div id="block-some-uuid--4">


      <div>Hello, XB!</div>
  </div>

HTML,
        'cacheability' => $default_cacheability,
        'attachments' => [],
      ],
      'block.xb_test_block_optional_contexts' => [
        'html' => <<<HTML
<div id="block-some-uuid--5">


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
      'block.xb_test_block_input_schema_change_poc' => [
        'expected_output_selectors' => ['div:contains("Current foo value: bar")'],
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

    $this->installEntitySchema('node');
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
      'block.xb_test_block_input_schema_change_poc' => $dependencies,
      'block.xb_test_block_input_validatable' => $dependencies,
      'block.xb_test_block_input_validatable_crash' => $dependencies,
      'block.xb_test_block_optional_contexts' => $dependencies,
    ], $this->callSourceMethodForEach('calculateDependencies', $component_ids));
  }

  protected function createAndSaveInUseComponentForFallbackTesting(): ComponentInterface {
    $this->installConfig(['system']);
    $this->generateComponentConfig();
    /** @var \Drupal\experience_builder\Entity\ComponentInterface */
    return Component::load('block.system_menu_block.footer');
  }

  protected function createAndSaveUnusedComponentForFallbackTesting(): ComponentInterface {
    /** @var \Drupal\experience_builder\Entity\ComponentInterface */
    return Component::load('block.system_menu_block.admin');
  }

  protected static function getPropsForComponentFallbackTesting(): array {
    return [
      'label' => 'Main navigation',
      'label_display' => '',
      'level' => 1,
      'depth' => NULL,
      'expand_all_items' => TRUE,
    ];
  }

  protected function deleteConfigAndTriggerComponentFallback(ComponentInterface $used_component, ComponentInterface $unused_component): void {
    $menu = Menu::load('footer');
    \assert($menu instanceof Menu);
    $menu->delete();

    $menu = Menu::load('admin');
    \assert($menu instanceof Menu);
    $menu->delete();
  }

  protected function recoverComponentFallback(ComponentInterface $component): void {
    $menu = Menu::create([
      'id' => 'footer',
      'label' => 'Footer',
      'description' => 'Site information links',
    ]);
    $menu->save();
    $this->generateComponentConfig();
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

  public function testVersionDeterminability(): void {
    $this->generateComponentConfig();
    $original_component = Component::load('block.xb_test_block_input_validatable');
    assert($original_component instanceof Component);
    $original_version = $original_component->getActiveVersion();

    // Trigger an alter to the schema which should result in a new version as
    // validation may make previous versions no longer valid.
    // @see \Drupal\xb_test_block\Hook\XbTestBlockHooks::configSchemaInfoAlter
    \Drupal::keyValue('xb_test_block')->set('i_can_haz_alter?', TRUE);
    \Drupal::service(TypedConfigManagerInterface::class)->clearCachedDefinitions();
    $this->generateComponentConfig();

    $new_component = Component::load('block.xb_test_block_input_validatable');
    assert($new_component instanceof Component);

    $new_version = $new_component->getActiveVersion();
    self::assertNotEquals($new_version, $original_version);
  }

  protected function createAndSaveInUseComponentForUninstallValidationTesting(): ComponentInterface {
    $this->enableModules(['help']);
    $this->generateComponentConfig();
    /** @var \Drupal\experience_builder\Entity\ComponentInterface */
    return Component::load('block.xb_test_block_input_none');
  }

  protected function createAndSaveUnusedComponentForUninstallValidationTesting(): ComponentInterface {
    /** @var \Drupal\experience_builder\Entity\ComponentInterface */
    return Component::load('block.help_block');
  }

  protected function getAllowedModuleForUninstallValidatorTesting(): string {
    return 'help';
  }

  protected function getNotAllowedModuleForUninstallValidatorTesting(): string {
    return 'xb_test_block';
  }

}
