<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Kernel\Plugin\ExperienceBuilder\ComponentSource;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\experience_builder\Entity\Component;
use Drupal\experience_builder\Plugin\ExperienceBuilder\ComponentSource\BlockComponent;
use Drupal\experience_builder\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\node\Entity\Node;
use Drupal\Tests\experience_builder\Traits\BlockComponentTreeTestTrait;
use Drupal\Tests\experience_builder\Traits\ConstraintViolationsTestTrait;
use Drupal\Tests\experience_builder\Traits\CrawlerTrait;
use Drupal\xb_test_block\Plugin\Block\XbTestBlockInputNone;
use Drupal\xb_test_block\Plugin\Block\XbTestBlockInputValidatable;
use Drupal\xb_test_block\Plugin\Block\XbTestBlockInputValidatableCrash;

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

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'block',
    'xb_test_block',
  ];

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
    ], $this->findIneligibleComponents(BlockComponent::SOURCE_PLUGIN_ID, 'xb_test_block'));
    $auto_created_components = $this->findCreatedComponentConfigEntities(BlockComponent::SOURCE_PLUGIN_ID, 'xb_test_block');
    self::assertSame([
      'block.xb_test_block_input_none',
      'block.xb_test_block_input_validatable',
      'block.xb_test_block_input_validatable_crash',
    ], $auto_created_components);

    return $auto_created_components;
  }

  /**
   * Tests the 'default_settings' generated for the eligible Block plugins.
   *
   * @depends testDiscovery
   */
  public function testSettings(array $component_ids): void {
    self::assertSame([
      'block.xb_test_block_input_none' => [
        'plugin_id' => 'xb_test_block_input_none',
        'default_settings' => [
          'id' => 'xb_test_block_input_none',
          'label' => 'Test block with no settings.',
          'label_display' => '',
          'provider' => 'xb_test_block',
        ],
      ],
      'block.xb_test_block_input_validatable' => [
        'plugin_id' => 'xb_test_block_input_validatable',
        'default_settings' => [
          'id' => 'xb_test_block_input_validatable',
          'label' => 'Test Block with settings',
          'label_display' => '',
          'provider' => 'xb_test_block',
          // This block has a single setting.
          'name' => 'XB',
        ],
      ],
      'block.xb_test_block_input_validatable_crash' => [
        'plugin_id' => 'xb_test_block_input_validatable_crash',
        'default_settings' => [
          'id' => 'xb_test_block_input_validatable_crash',
          'label' => "Test Block with settings, crashes when 'crash' setting is TRUE",
          'label_display' => '',
          'provider' => 'xb_test_block',
          // This block has two settings.
          'name' => 'XB',
          'crash' => FALSE,
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


      <div>Hello, XB!</div>
  </div>

HTML,
        'cacheability' => $default_cacheability,
      ],
      'block.xb_test_block_input_validatable' => [
        'html' => <<<HTML
<div id="block-some-uuid--2">


      <div>Hello, XB!</div>
  </div>

HTML,
        'cacheability' => $default_cacheability,
      ],
      'block.xb_test_block_input_validatable_crash' => [
        'html' => <<<HTML
<div id="block-some-uuid--3">


      <div>Hello, XB!</div>
  </div>

HTML,
        'cacheability' => $default_cacheability,
      ],
    ], $rendered);
  }

  /**
   * @covers ::getClientSideInfo()
   * @depends testDiscovery
   */
  public function testGetClientSideInfo(array $component_ids): void {
    $this->assertNotEmpty($component_ids);
    $this->assertCount(0, $this->componentStorage->loadMultiple());
    $this->generateComponentConfig();

    $actual_client_side_info = [];
    foreach ($this->componentStorage->loadMultiple($component_ids) as $component_id => $component) {
      assert($component instanceof Component);
      $client_side_info = $component->getComponentSource()
        ->getClientSideInfo($component);
      $client_side_info['build'] = (string) $this->renderer->renderInIsolation($client_side_info['build']);
      // For the preview provided in the client-side info, XB always uses the
      // Component config entity UUID as a fake component instance UUID. Make
      // this easy to target in the expectations.
      $client_side_info['build'] = str_replace($component->uuid(), '::COMPONENT_CONFIG_ENTITY_UUID::', $client_side_info['build']);
      // Strip trailing whitespace to make heredocs easier to write.
      $client_side_info['build'] = preg_replace('/ +$/m', '', $client_side_info['build']);
      $actual_client_side_info[$component_id] = $client_side_info;
    }
    $this->assertEquals([
      'block.xb_test_block_input_none' => [
        'build' => <<<HTML
<div id="block-::COMPONENT_CONFIG_ENTITY_UUID::">


      <div>Hello, XB!</div>
  </div>

HTML,
      ],
      'block.xb_test_block_input_validatable' => [
        'build' => <<<HTML
<div id="block-::COMPONENT_CONFIG_ENTITY_UUID::">


      <div>Hello, XB!</div>
  </div>

HTML,
      ],
      'block.xb_test_block_input_validatable_crash' => [
        'build' => <<<HTML
<div id="block-::COMPONENT_CONFIG_ENTITY_UUID::">


      <div>Hello, XB!</div>
  </div>

HTML,
      ],
    ], $actual_client_side_info);
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
    $componentId = $xb_field_item->get('tree')->getComponentId($componentItemValue['uuid']);

    $component = Component::load($componentId);
    assert($component instanceof Component);

    $explicit = $component->getComponentSource()->getExplicitInput($componentItemValue['uuid'], $xb_field_item);
    $componentSettings = $explicit;
    $componentSettingsOriginal = json_decode($componentItemValue['inputs'], TRUE)[$componentItemValue['uuid']];

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
      'expected_output_selector' => '#block-crash-test-dummy--2:contains("Hello, XB!")',
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
    self::assertSame([
      'block.xb_test_block_input_none' => [],
      'block.xb_test_block_input_validatable' => [],
      'block.xb_test_block_input_validatable_crash' => [],
    ], $this->getAllCalculatedDependencies($component_ids));
  }

}
