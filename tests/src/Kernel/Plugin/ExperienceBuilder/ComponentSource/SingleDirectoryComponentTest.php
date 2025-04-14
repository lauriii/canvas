<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Kernel\Plugin\ExperienceBuilder\ComponentSource;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Theme\ComponentPluginManager;
use Drupal\experience_builder\Entity\Component;
use Drupal\Core\Plugin\Component as SdcPlugin;
use Drupal\experience_builder\Plugin\ExperienceBuilder\ComponentSource\SingleDirectoryComponent;
use Drupal\experience_builder\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\experience_builder\PropSource\StaticPropSource;
use Drupal\node\Entity\Node;
use Drupal\Tests\experience_builder\Kernel\Traits\CiModulePathTrait;
use Drupal\Tests\experience_builder\Traits\SingleDirectoryComponentTreeTestTrait;
use Drupal\Tests\experience_builder\Traits\ContribStrictConfigSchemaTestTrait;
use Drupal\Tests\experience_builder\Traits\GenerateComponentConfigTrait;

/**
 * @coversDefaultClass \Drupal\experience_builder\Plugin\ExperienceBuilder\ComponentSource\SingleDirectoryComponent
 * @group experience_builder
 * @phpstan-import-type ComponentConfigEntityId from \Drupal\experience_builder\Entity\Component
 */
final class SingleDirectoryComponentTest extends ComponentSourceTestBase {

  use ContribStrictConfigSchemaTestTrait;
  use SingleDirectoryComponentTreeTestTrait;
  use GenerateComponentConfigTrait;
  use CiModulePathTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'xb_test_sdc',
    'media',
    'node',
    'user',
  ];

  /**
   * All test module SDCs must either have a Component or a reason why not.
   *
   * @covers ::checkRequirements()
   * @covers \Drupal\experience_builder\Plugin\ComponentPluginManager::setCachedDefinitions()
   */
  public function testDiscovery(): array {
    // Nothing discovered initially.
    self::assertSame([], $this->findIneligibleComponents(SingleDirectoryComponent::SOURCE_PLUGIN_ID, 'xb_test_sdc'));
    self::assertSame([], $this->findCreatedComponentConfigEntities(SingleDirectoryComponent::SOURCE_PLUGIN_ID, 'xb_test_sdc'));

    // Trigger component generation, as if the test module was just installed.
    // (Kernel tests don't trigger all hooks that are triggered in reality.)
    $this->generateComponentConfig();

    self::assertSame([
      'sdc.xb_test_sdc.html-invalid-format' => [
        'Invalid value "invalid" for "x-formatting-context". Valid values are "inline" and "block".',
      ],
      'sdc.xb_test_sdc.image-required-without-example' => [
        'Prop "image" is required, but does not have example value',
      ],
      'sdc.xb_test_sdc.obsolete' => [
        'Component has "obsolete" status',
      ],
      'sdc.xb_test_sdc.props-no-examples' => [
        'Prop "heading" is required, but does not have example value',
      ],
      'sdc.xb_test_sdc.props-no-title' => [
        'Prop "heading" must have title',
      ],
    ], $this->findIneligibleComponents(SingleDirectoryComponent::SOURCE_PLUGIN_ID, 'xb_test_sdc'));
    $auto_created_components = $this->findCreatedComponentConfigEntities(SingleDirectoryComponent::SOURCE_PLUGIN_ID, 'xb_test_sdc');
    self::assertSame([
      'sdc.xb_test_sdc.deprecated',
      'sdc.xb_test_sdc.experimental',
      'sdc.xb_test_sdc.grid-container',
      'sdc.xb_test_sdc.image-optional-with-example',
      'sdc.xb_test_sdc.image-optional-with-example-and-additional-prop',
      'sdc.xb_test_sdc.image-optional-without-example',
      'sdc.xb_test_sdc.image-required-with-example',
      'sdc.xb_test_sdc.props-no-slots',
      'sdc.xb_test_sdc.props-slots',
    ], $auto_created_components);

    return $auto_created_components;
  }

  /**
   * @param array<ComponentConfigEntityId> $component_ids
   * @covers ::getReferencedPluginClass()
   * @depends testDiscovery
   */
  public function testGetReferencedPluginClass(array $component_ids): void {
    self::assertSame(
      // All SDCs use the same plugin class!
      array_fill_keys($component_ids, SdcPlugin::class),
      $this->getReferencedPluginClasses($component_ids)
    );
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
      get_default_input: fn (Component $component) => [
        SingleDirectoryComponent::EXPLICIT_INPUT_NAME => array_map(
          // @see \Drupal\experience_builder\Plugin\ExperienceBuilder\ComponentSource\GeneratedFieldExplicitInputUxComponentSourceBase::getDefaultStaticPropSource()
          fn (array $prop_field_definition): mixed => match ($prop_field_definition['default_value']) {
            // @see \Drupal\experience_builder\Plugin\ExperienceBuilder\ComponentSource\GeneratedFieldExplicitInputUxComponentSourceBase::exampleValueRequiresEntity()
            // @todo Refine later, to use DefaultRelativeUrlPropSource.
            [] => [
              'src' => 'cat.jpg',
              'alt' => '',
              'width' => 10,
              'height' => 10,
            ],
            default => StaticPropSource::parse([
              'sourceType' => 'static:field_item:' . $prop_field_definition['field_type'],
              'value' => $prop_field_definition['default_value'],
              'expression' => $prop_field_definition['expression'],
              'sourceTypeSettings' => [
                'storage' => $prop_field_definition['field_storage_settings'] ?? [],
                'instance' => $prop_field_definition['field_instance_settings'] ?? [],
              ],
            ])
              // Static prop sources can be evaluated without a host entity.
              ->evaluate(NULL),
          },
          $component->getSettings()['prop_field_definitions']
        ),
      ],
    );

    $default_render_cache_contexts = [
      'languages:language_interface',
      'theme',
      'user.permissions',
    ];
    $default_cacheability = (new CacheableMetadata())
      ->setCacheContexts($default_render_cache_contexts);
    $this->assertEquals([
      'sdc.xb_test_sdc.deprecated' => [
        'html' => <<<HTML
<div  data-component-id="xb_test_sdc:deprecated">
  <h1>Deprecated SDC component</h1>
  <div><!-- xb-prop-start-some-uuid/text -->A text field<!-- xb-prop-end-some-uuid/text --></div>
</div>

HTML,
        'cacheability' => $default_cacheability,
      ],
      'sdc.xb_test_sdc.experimental' => [
        'html' => <<<HTML
<div  data-component-id="xb_test_sdc:experimental">
  <h1>Experimental SDC component</h1>
  <div><!-- xb-prop-start-some-uuid/text -->A text field<!-- xb-prop-end-some-uuid/text --></div>
</div>

HTML,
        'cacheability' => $default_cacheability,
      ],
      'sdc.xb_test_sdc.grid-container' => [
        'html' => '
<div  data-component-id="xb_test_sdc:grid-container" class="grid-container direction-horizontal">

  </div>
',
        'cacheability' => $default_cacheability,
      ],
      'sdc.xb_test_sdc.image-optional-with-example' => [
        'html' => '<img src="cat.jpg" alt="" />',
        'cacheability' => $default_cacheability,
      ],
      'sdc.xb_test_sdc.image-optional-with-example-and-additional-prop' => [
        'html' => '<img src="cat.jpg" alt="" width="10" height="10"></img>',
        'cacheability' => $default_cacheability,
      ],
      'sdc.xb_test_sdc.image-optional-without-example' => [
        'html' => '  <img src="cat.jpg" alt="" />
',
        'cacheability' => $default_cacheability,
      ],
      'sdc.xb_test_sdc.image-required-with-example' => [
        'html' => '<img src="cat.jpg" alt="" />',
        'cacheability' => $default_cacheability,
      ],
      'sdc.xb_test_sdc.props-no-slots' => [
        'html' => <<<HTML
<div  data-component-id="xb_test_sdc:props-no-slots" style="font-family: Helvetica, Arial, sans-serif; width: 100%; height: 100vh; background-color: #f5f5f5; display: flex; justify-content: center; align-items: center; flex-direction: column; text-align: center; padding: 20px; box-sizing: border-box;">
  <h1 style="font-size: 3em; margin: 0.5em 0; color: #333;"><!-- xb-prop-start-some-uuid/heading -->There goes my hero<!-- xb-prop-end-some-uuid/heading --></h1>
</div>

HTML,
        'cacheability' => $default_cacheability,
      ],
      'sdc.xb_test_sdc.props-slots' => [
        'html' => '<div  data-component-id="xb_test_sdc:props-slots" style="font-family: Helvetica, Arial, sans-serif; width: 100%; height: 100vh; background-color: #f5f5f5; display: flex; justify-content: center; align-items: center; flex-direction: column; text-align: center; padding: 20px; box-sizing: border-box;">
  <h1 style="font-size: 3em; margin: 0.5em 0; color: #333;"><!-- xb-prop-start-some-uuid/heading -->There goes my hero<!-- xb-prop-end-some-uuid/heading --></h1>
  <div class="component--props-slots--body">
          </div>
  <div class="component--props-slots--footer">
          </div>
  <div class="component--props-slots--colophon">
          </div>
</div>
',
        'cacheability' => $default_cacheability,
      ],
    ], $rendered);
  }

  public function testRewriteExampleUrl(): void {
    $plugin = \Drupal::service(ComponentPluginManager::class)->createInstance('experience_builder:image');
    $component = SingleDirectoryComponent::createConfigEntity($plugin);
    $source = $component->getComponentSource();
    self::assertInstanceOf(SingleDirectoryComponent::class, $source);

    // Assert that existing files are rewritten to include the module path.
    $module_path = \Drupal::service(ModuleExtensionList::class)->getPath('experience_builder');
    self::assertStringEndsWith($module_path . '/components/image/600x400.png', $source->rewriteExampleUrl('600x400.png'));
    self::assertStringEndsWith($module_path . '/components/image/600x400.png', $source->rewriteExampleUrl('/600x400.png'));
    self::assertStringEndsWith($module_path . '/tests/fixtures/600x400.png', $source->rewriteExampleUrl('../../tests/fixtures/600x400.png'));

    // Assert that non-existing links have a leading slash but do not include the module nor SDC path.
    $url = $source->rewriteExampleUrl('test/path');
    self::assertStringEndsWith('/test/path', $url);
    self::assertStringNotContainsString($module_path, $url);
    self::assertStringNotContainsString('components', $url);

    // Assert that non-existing links with a leading slash are not doubled.
    $url = $source->rewriteExampleUrl('/test/path');
    self::assertStringEndsWith('/test/path', $url);
    self::assertStringNotContainsString('//', $url);

    // Assert that full URLs are left alone.
    self::assertSame('https://www.example.com/', $source->rewriteExampleUrl('https://www.example.com/'));
  }

  /**
   * @covers ::getExplicitInput()
   * @dataProvider providerComponentResolving
   */
  public function testGetExplicitInput(array $component_item_value, array $expected_props_for_uuids): void {
    $this->generateComponentConfig();
    $this->container->get('module_installer')->install(['xb_test_config_node_article']);
    $node = Node::create([
      'title' => 'Test node',
      'type' => 'article',
      'field_xb_test' => $component_item_value,
    ]);
    $xb_field_item = $node->field_xb_test[0];
    $this->assertInstanceOf(ComponentTreeItem::class, $xb_field_item);
    $actual_props = array_combine(
      array_keys($expected_props_for_uuids),
      array_map(
        // @phpstan-ignore-next-line
        fn (string $uuid) => Component::load($xb_field_item->get('tree')->getComponentId($uuid))
          ->getComponentSource()
          ->getExplicitInput($uuid, $xb_field_item)['resolved'],
        array_keys($expected_props_for_uuids)
      )
    );
    $this->assertSame($expected_props_for_uuids, $actual_props);
  }

  public static function providerComponentResolving(): array {
    $test_cases = static::getValidTreeTestCases();
    $invalid_test_cases = static::getInvalidTreeTestCases();
    // Only 1 invalid case will allow to call
    // \Drupal\experience_builder\Plugin\Field\FieldType\ComponentTreeItem::resolveComponentProps()
    // without an exception.
    $test_cases['invalid tree structure, uuid at top of data structure is not in the tree, also has empty slots'] = $invalid_test_cases['invalid tree structure, uuid at top of data structure is not in the tree, also has empty slots'];
    $test_cases['invalid tree structure, uuid at top of data structure is not in the tree, also has empty slots'][] = [];
    $test_cases['valid values using static inputs'][] = [
      'dynamic-static-card2df' => [
        'heading' => 'They say I am static, but I want to believe I can change!',
      ],
    ];
    $test_cases['valid values for propless component'][] = [
      'propless-component-uuid' => [],
    ];
    $test_cases['valid value for optional explicit input using an URL prop shape, with default value'][] = [
      'optional-url-with-default-value' => [
        'heading' => 'Gracie says hi!',
        'image' => [
          'src' => self::getCiModulePath() . '/tests/modules/xb_test_sdc/components/image-optional-with-example-and-additional-prop/gracie.jpg',
          'alt' => 'A good dog',
          'width' => 601,
          'height' => 402,
        ],
      ],
    ];
    return $test_cases;
  }

}
