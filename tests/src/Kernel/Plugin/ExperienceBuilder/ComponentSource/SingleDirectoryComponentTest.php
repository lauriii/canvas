<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Kernel\Plugin\ExperienceBuilder\ComponentSource;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\Extension\ModuleInstallerInterface;
use Drupal\Core\File\FileExists;
use Drupal\Core\StreamWrapper\PublicStream;
use Drupal\Core\Theme\ComponentPluginManager;
use Drupal\experience_builder\Entity\Component;
use Drupal\Core\Plugin\Component as SdcPlugin;
use Drupal\experience_builder\Plugin\ExperienceBuilder\ComponentSource\GeneratedFieldExplicitInputUxComponentSourceBase;
use Drupal\experience_builder\Entity\ComponentInterface;
use Drupal\experience_builder\Plugin\ExperienceBuilder\ComponentSource\SingleDirectoryComponent;
use Drupal\experience_builder\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\experience_builder\Plugin\Field\FieldType\ComponentTreeItemList;
use Drupal\experience_builder\PropExpressions\StructuredData\FieldTypePropExpression;
use Drupal\experience_builder\PropSource\PropSource;
use Drupal\experience_builder\PropSource\StaticPropSource;
use Drupal\file\Entity\File;
use Drupal\media\Entity\Media;
use Drupal\media\Entity\MediaType;
use Drupal\node\Entity\Node;
use Drupal\Tests\experience_builder\Kernel\Traits\CiModulePathTrait;
use Drupal\Tests\experience_builder\Traits\ConstraintViolationsTestTrait;
use Drupal\Tests\experience_builder\Traits\SingleDirectoryComponentTreeTestTrait;
use Drupal\Tests\experience_builder\Traits\ContribStrictConfigSchemaTestTrait;
use Drupal\Tests\experience_builder\Traits\GenerateComponentConfigTrait;
use Drupal\Tests\experience_builder\Traits\CrawlerTrait;
use Drupal\Tests\media\Traits\MediaTypeCreationTrait;
use Drupal\Tests\node\Traits\ContentTypeCreationTrait;
use Drupal\Tests\TestFileCreationTrait;
use Twig\Error\Error;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;

/**
 * @coversDefaultClass \Drupal\experience_builder\Plugin\ExperienceBuilder\ComponentSource\SingleDirectoryComponent
 * @group experience_builder
 * @group #slow
 * @phpstan-import-type ComponentConfigEntityId from \Drupal\experience_builder\Entity\Component
 */
final class SingleDirectoryComponentTest extends ComponentSourceTestBase {

  use ConstraintViolationsTestTrait;
  use ContribStrictConfigSchemaTestTrait;
  use SingleDirectoryComponentTreeTestTrait;
  use GenerateComponentConfigTrait;
  use CiModulePathTrait;
  use CrawlerTrait;
  use MediaTypeCreationTrait;
  use TestFileCreationTrait;
  use ContentTypeCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'xb_test_sdc',
    'media',
    'node',
    'path',
    'user',
    'field',
    'text',
  ];

  /**
   * @depends testDiscovery
   */
  public function testGetClientSideInfo(array $component_ids): void {
    $this->installEntitySchema('node');
    $this->installConfig('node');
    $this->createContentType(['type' => 'article']);
    parent::testGetClientSideInfo($component_ids);
  }

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
      'sdc.xb_test_sdc.empty-enum' => [
        'Prop "pets" has an empty enum value.',
      ],
      'sdc.xb_test_sdc.html-invalid-format' => [
        'Invalid value "invalid" for "x-formatting-context". Valid values are "inline" and "block".',
      ],
      'sdc.xb_test_sdc.image-required-with-invalid-example' => [
        'Prop "image" has invalid example value: [src] The property src is required',
      ],
      'sdc.xb_test_sdc.image-required-without-example' => [
        'Prop "image" is required, but does not have example value',
      ],
      'sdc.xb_test_sdc.obsolete' => [
        'Component has "obsolete" status',
      ],
      'sdc.xb_test_sdc.props-invalid-shapes' => [
        'Prop "invalid_shape" is of type "object" without a $ref, which is not supported',
      ],
      'sdc.xb_test_sdc.props-no-examples' => [
        'Prop "heading" is required, but does not have example value',
      ],
      'sdc.xb_test_sdc.props-no-title' => [
        'Prop "heading" must have title',
      ],
      'sdc.xb_test_sdc.slots-no-title' => [
        'Slot "the_footer" must have title',
      ],
      'sdc.xb_test_sdc.sparkline_min_2' => [
        // Drupal core's Field API only supports specifying "required or not",
        // and required means ">=1 value". There's no (native) ability to
        // configure a minimum number of values for a field.
        // @see https://www.drupal.org/project/unlimited_field_settings
        'Experience Builder does not know of a field type/widget to allow populating the <code>data</code> prop, with the shape <code>{"type":"array","items":{"type":"integer","minimum":-100,"maximum":100},"maxItems":100,"minItems":2}</code>.',
      ],
    ], $this->findIneligibleComponents(SingleDirectoryComponent::SOURCE_PLUGIN_ID, 'xb_test_sdc'));
    $auto_created_components = $this->findCreatedComponentConfigEntities(SingleDirectoryComponent::SOURCE_PLUGIN_ID, 'xb_test_sdc');
    self::assertSame([
      'sdc.xb_test_sdc.crash',
      'sdc.xb_test_sdc.deprecated',
      'sdc.xb_test_sdc.experimental',
      'sdc.xb_test_sdc.grid-container',
      'sdc.xb_test_sdc.image-gallery',
      'sdc.xb_test_sdc.image-optional-with-example',
      'sdc.xb_test_sdc.image-optional-with-example-and-additional-prop',
      'sdc.xb_test_sdc.image-optional-without-example',
      'sdc.xb_test_sdc.image-required-with-example',
      'sdc.xb_test_sdc.my-cta',
      'sdc.xb_test_sdc.props-no-slots',
      'sdc.xb_test_sdc.props-slots',
      'sdc.xb_test_sdc.sparkline',
    ], $auto_created_components);

    return array_combine($auto_created_components, $auto_created_components);
  }

  /**
   * Tests the shape-matched `prop_field_definitions` for the eligible SDCs.
   *
   * @depends testDiscovery
   */
  public function testSettings(array $component_ids): void {
    $settings = $this->getAllSettings($component_ids);
    self::assertSame(self::getExpectedSettings(), $settings);

    // Slightly more scrutiny for ComponentSources with a generated field-based
    // input UX: verifying this results in working `StaticPropSource`s is
    // sufficient, everything beyond that is covered by PropShapeRepositoryTest.
    // @see \Drupal\Tests\experience_builder\Kernel\PropShapeRepositoryTest::testPropShapesYieldWorkingStaticPropSources()
    // @see \Drupal\experience_builder\Plugin\ExperienceBuilder\ComponentSource\GeneratedFieldExplicitInputUxComponentSourceBase
    $components = $this->componentStorage->loadMultiple($component_ids);
    foreach ($components as $component_id => $component) {
      // Use reflection to test the private ::getDefaultStaticPropSource() method.
      assert($component instanceof Component);
      $source = $component->getComponentSource();
      $private_method = new \ReflectionMethod($source, 'getDefaultStaticPropSource');
      $private_method->setAccessible(TRUE);
      foreach (array_keys($settings[$component_id]['prop_field_definitions']) as $prop) {
        $static_prop_source = $private_method->invoke($source, $prop);
        $this->assertInstanceOf(StaticPropSource::class, $static_prop_source);
      }
    }
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
    $this->installEntitySchema('node');
    $this->installConfig('node');
    $this->createContentType(['type' => 'article']);
    $this->assertNotEmpty($component_ids);

    $rendered = $this->renderComponentsLive(
      $component_ids,
      get_default_input: [__CLASS__, 'getDefaultInputForGeneratedInputUx'],
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
  <div>A text field</div>
</div>

HTML,
        'cacheability' => $default_cacheability,
        'attachments' => [
          'library' => [
            'core/components.xb_test_sdc--deprecated',
            'core/components.xb_test_sdc--deprecated',
          ],
        ],
      ],
      'sdc.xb_test_sdc.experimental' => [
        'html' => <<<HTML
<div  data-component-id="xb_test_sdc:experimental">
  <h1>Experimental SDC component</h1>
  <div>A text field</div>
</div>

HTML,
        'cacheability' => $default_cacheability,
        'attachments' => [
          'library' => [
            'core/components.xb_test_sdc--experimental',
            'core/components.xb_test_sdc--experimental',
          ],
        ],
      ],
      'sdc.xb_test_sdc.grid-container' => [
        'html' => '
<div  data-component-id="xb_test_sdc:grid-container" class="grid-container direction-horizontal">

  </div>
',
        'cacheability' => $default_cacheability,
        'attachments' => [
          'library' => [
            'core/components.xb_test_sdc--grid-container',
            'core/components.xb_test_sdc--grid-container',
          ],
        ],
      ],
      'sdc.xb_test_sdc.image-gallery' => [
        'html' => '<figure>
      <img src="::XB_MODULE_PATH::/tests/modules/xb_test_sdc/components/image-gallery/gracie.jpg" alt="A good dog" />
      <img src="::XB_MODULE_PATH::/tests/modules/xb_test_sdc/components/image-gallery/gracie.jpg" alt="Still a good dog" />
      <img src="::XB_MODULE_PATH::/tests/modules/xb_test_sdc/components/image-gallery/UPPERCASE-GRACIE.JPG" alt="THE BEST DOG!" />
    </figure>
',
        'cacheability' => $default_cacheability,
        'attachments' => [
          'library' => [
            'core/components.xb_test_sdc--image-gallery',
            'core/components.xb_test_sdc--image-gallery',
          ],
        ],
      ],
      'sdc.xb_test_sdc.image-optional-with-example' => [
        'html' => '<img src="https://example.com/cat.jpg" alt="Boring placeholder" />',
        'cacheability' => $default_cacheability,
        'attachments' => [
          'library' => [
            'core/components.xb_test_sdc--image-optional-with-example',
            'core/components.xb_test_sdc--image-optional-with-example',
          ],
        ],
      ],
      'sdc.xb_test_sdc.image-optional-with-example-and-additional-prop' => [
        'html' => '<img src="::XB_MODULE_PATH::/tests/modules/xb_test_sdc/components/image-optional-with-example-and-additional-prop/gracie.jpg" alt="A good dog" width="601" height="402"></img>',
        'cacheability' => $default_cacheability,
        'attachments' => [
          'library' => [
            'core/components.xb_test_sdc--image-optional-with-example-and-additional-prop',
            'core/components.xb_test_sdc--image-optional-with-example-and-additional-prop',
          ],
        ],
      ],
      'sdc.xb_test_sdc.image-optional-without-example' => [
        'html' => '',
        'cacheability' => $default_cacheability,
        'attachments' => [
          'library' => [
            'core/components.xb_test_sdc--image-optional-without-example',
            'core/components.xb_test_sdc--image-optional-without-example',
          ],
        ],
      ],
      'sdc.xb_test_sdc.image-required-with-example' => [
        'html' => '<img src="https://example.com/cat.jpg" alt="Boring placeholder" />',
        'cacheability' => $default_cacheability,
        'attachments' => [
          'library' => [
            'core/components.xb_test_sdc--image-required-with-example',
            'core/components.xb_test_sdc--image-required-with-example',
          ],
        ],
      ],
      'sdc.xb_test_sdc.props-no-slots' => [
        'html' => <<<HTML
<div  data-component-id="xb_test_sdc:props-no-slots" style="font-family: Helvetica, Arial, sans-serif; width: 100%; height: 100vh; background-color: #f5f5f5; display: flex; justify-content: center; align-items: center; flex-direction: column; text-align: center; padding: 20px; box-sizing: border-box;">
  <h1 style="font-size: 3em; margin: 0.5em 0; color: #333;">There goes my hero</h1>
</div>

HTML,
        'cacheability' => $default_cacheability,
        'attachments' => [
          'library' => [
            'core/components.xb_test_sdc--props-no-slots',
            'core/components.xb_test_sdc--props-no-slots',
          ],
        ],
      ],
      'sdc.xb_test_sdc.props-slots' => [
        'html' => '<div  data-component-id="xb_test_sdc:props-slots" style="font-family: Helvetica, Arial, sans-serif; width: 100%; height: 100vh; background-color: #f5f5f5; display: flex; justify-content: center; align-items: center; flex-direction: column; text-align: center; padding: 20px; box-sizing: border-box;">
  <h1 style="font-size: 3em; margin: 0.5em 0; color: #333;">There goes my hero</h1>
  <div class="component--props-slots--body">
          </div>
  <div class="component--props-slots--footer">
          </div>
  <div class="component--props-slots--colophon">
          </div>
</div>
',
        'cacheability' => $default_cacheability,
        'attachments' => [
          'library' => [
            'core/components.xb_test_sdc--props-slots',
            'core/components.xb_test_sdc--props-slots',
          ],
        ],
      ],
      'sdc.xb_test_sdc.crash' => [
        'html' => '<h1>test</h1>

',
        'cacheability' => $default_cacheability,
        'attachments' => [
          'library' => [
            'core/components.xb_test_sdc--crash',
            'core/components.xb_test_sdc--crash',
          ],
        ],
      ],
      'sdc.xb_test_sdc.sparkline' => [
        'html' => '

<div class="sparkline-container" style="width: 100px; height: 20px;">
  <svg width="100" height="20" viewBox="0 0 100 20" preserveAspectRatio="none">

            <polygon points="0,20 0,7.5 12.5,5 25,2.5 37.5,0 50,17.5 62.5,20 75,6.25 87.5,5.75 100,5.25 100,20" fill="rgba(26, 115, 232, 0.2)" />

            <polyline points="0,7.5 12.5,5 25,2.5 37.5,0 50,17.5 62.5,20 75,6.25 87.5,5.75 100,5.25" fill="none" stroke="#1a73e8" stroke-width="1" />
      </svg>
</div>
',
        'cacheability' => $default_cacheability,
        'attachments' => [
          'library' => [
            'core/components.xb_test_sdc--sparkline',
            'core/components.xb_test_sdc--sparkline',
          ],
        ],
      ],
      'sdc.xb_test_sdc.my-cta' => [
        'cacheability' => $default_cacheability,
        'html' => '<a  data-component-id="xb_test_sdc:my-cta" href="https://www.drupal.org">
  Press
</a>
',
        'attachments' => [
          'library' => [
            'core/components.xb_test_sdc--my-cta',
            'core/components.xb_test_sdc--my-cta',
          ],
        ],
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
    $this->installEntitySchema('node');
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
        fn (string $uuid) => $xb_field_item->getComponent()?->getComponentSource()->getExplicitInput($uuid, $xb_field_item)['resolved'],
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
    $test_cases['invalid UUID, missing component_id key'] = $invalid_test_cases['invalid UUID, missing component_id key'];
    $test_cases['invalid UUID, missing component_id key'][] = [];
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

  protected function generateCrashTestDummyComponentTree(string $component_id, array $inputs, bool $assertCount = TRUE): ComponentTreeItemList {
    if ($component_id === 'sdc.xb_broken_sdcs.invalid-filter') {
      // This component needs an extra module.
      $this->assertCount(0, $this->componentStorage->loadMultiple());
      \Drupal::service(ModuleInstallerInterface::class)->install(['xb_broken_sdcs']);

      // Now call the parent, but don't assert the count of components, as we've
      // done it here, and installing a module will generate component config.
      return parent::generateCrashTestDummyComponentTree($component_id, $inputs, assertCount: FALSE);
    }
    return parent::generateCrashTestDummyComponentTree($component_id, $inputs);
  }

  public static function providerRenderComponentFailure(): \Generator {
    $generate_static_prop_source = function (string $field_type, mixed $value): array {
      return [
        'sourceType' => "static:field_item:$field_type",
        'value' => $value,
        'expression' => (string) new FieldTypePropExpression($field_type, 'value'),
      ];
    };

    yield "SDC with valid props, without exception" => [
      'component_id' => 'sdc.xb_test_sdc.crash',
      'inputs' => [
        'crash' => $generate_static_prop_source('boolean', FALSE),
      ],
      'expected_validation_errors' => [],
      'expected_exception' => NULL,
      'expected_output_selector' => 'h1:contains("test")',
    ];

    yield "SDC with valid props, with exception" => [
      'component_id' => 'sdc.xb_test_sdc.crash',
      'inputs' => [
        'crash' => TRUE,
      ],
      'expected_validation_errors' => [],
      'expected_exception' => [
        'class' => Error::class,
        'message' => 'Intentional test exception in "xb_test_sdc:crash" at line 2.',
      ],
      'expected_output_selector' => NULL,
    ];

    yield "SDC with invalid prop, with exception" => [
      'component_id' => 'sdc.xb_test_sdc.crash',
      'inputs' => [
        'crash' => $generate_static_prop_source('string', 'this is an invalid value for the SDC prop'),
      ],
      'expected_validation_errors' => [
        \sprintf('2.inputs.%s.crash', self::UUID_CRASH_TEST_DUMMY) => 'String value found, but a boolean or an object is required. The provided value is: "this is an invalid value for the SDC prop".',
      ],
      'expected_exception' => [
        'class' => RuntimeError::class,
        'message' => 'An exception has been thrown during the rendering of a template ("[xb_test_sdc:crash/crash] String value found, but a boolean or an object is required. The provided value is: "this is an invalid value for the SDC prop".") in "xb_test_sdc:crash" at line 1.',
      ],
      'expected_output_selector' => NULL,
    ];

    yield "SDC with missing prop, with exception" => [
      'component_id' => 'sdc.xb_test_sdc.crash',
      'inputs' => [],
      'expected_validation_errors' => [
        \sprintf('2.inputs.%s.crash', self::UUID_CRASH_TEST_DUMMY) => 'The property crash is required.',
      ],
      'expected_exception' => [
        'class' => RuntimeError::class,
        'message' => 'An exception has been thrown during the rendering of a template ("[xb_test_sdc:crash/crash] The property crash is required.") in "xb_test_sdc:crash" at line 1.',
      ],
      'expected_output_selector' => NULL,
    ];

    yield "SDC with invalid Twig" => [
      'component_id' => 'sdc.xb_broken_sdcs.invalid-filter',
      'inputs' => [
        'name' => $generate_static_prop_source('string', 'Gaia'),
      ],
      'expected_validation_errors' => [],
      'expected_exception' => [
        'class' => SyntaxError::class,
        'message' => 'Unknown "invalidFilter" filter',
      ],
      'expected_output_selector' => NULL,
    ];
  }

  public static function getExpectedSettings(): array {
    return [
      'sdc.xb_test_sdc.crash' => [
        'prop_field_definitions' => [
          'crash' => [
            'field_type' => 'boolean',
            'field_storage_settings' => [],
            'field_instance_settings' => [],
            'field_widget' => 'boolean_checkbox',
            'default_value' => [0 => ['value' => FALSE]],
            'expression' => 'ℹ︎boolean␟value',
          ],
        ],
      ],
      'sdc.xb_test_sdc.deprecated' => [
        'prop_field_definitions' => [
          'text' => [
            'field_type' => 'string',
            'field_storage_settings' => [],
            'field_instance_settings' => [],
            'field_widget' => 'string_textfield',
            'default_value' => [0 => ['value' => 'A text field']],
            'expression' => 'ℹ︎string␟value',
          ],
        ],
      ],
      'sdc.xb_test_sdc.experimental' => [
        'prop_field_definitions' => [
          'text' => [
            'field_type' => 'string',
            'field_storage_settings' => [],
            'field_instance_settings' => [],
            'field_widget' => 'string_textfield',
            'default_value' => [0 => ['value' => 'A text field']],
            'expression' => 'ℹ︎string␟value',
          ],
        ],
      ],
      'sdc.xb_test_sdc.grid-container' => [
        'prop_field_definitions' => [
          'direction' => [
            'field_type' => 'list_string',
            'field_storage_settings' => [
              'allowed_values' => [
                ['value' => 'horizontal', 'label' => 'horizontal'],
                ['value' => 'vertical', 'label' => 'vertical'],
              ],
            ],
            'field_instance_settings' => [],
            'field_widget' => 'options_select',
            'default_value' => [0 => ['value' => 'horizontal']],
            'expression' => 'ℹ︎list_string␟value',
          ],
        ],
      ],
      'sdc.xb_test_sdc.image-gallery' => [
        'prop_field_definitions' => [
          'caption' => [
            'field_type' => 'string',
            'field_storage_settings' => [],
            'field_instance_settings' => [],
            'field_widget' => 'string_textfield',
            'default_value' => NULL,
            'expression' => 'ℹ︎string␟value',
          ],
          'images' => [
            'field_type' => 'image',
            'cardinality' => FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED,
            'field_storage_settings' => [],
            'field_instance_settings' => [],
            'field_widget' => 'image_image',
            // ⚠️ Empty default value.
            // @see \Drupal\experience_builder\Plugin\ExperienceBuilder\ComponentSource\GeneratedFieldExplicitInputUxComponentSourceBase::exampleValueRequiresEntity()
            'default_value' => [],
            'expression' => 'ℹ︎image␟{src↝entity␜␜entity:file␝uri␞␟url,alt↠alt,width↠width,height↠height}',
          ],
        ],
      ],
      'sdc.xb_test_sdc.image-optional-with-example' => [
        'prop_field_definitions' => [
          'image' => [
            'field_type' => 'image',
            'field_storage_settings' => [],
            'field_instance_settings' => [],
            'field_widget' => 'image_image',
            // ⚠️ Empty default value.
            // @see \Drupal\experience_builder\Plugin\ExperienceBuilder\ComponentSource\GeneratedFieldExplicitInputUxComponentSourceBase::exampleValueRequiresEntity()
            'default_value' => [],
            'expression' => 'ℹ︎image␟{src↝entity␜␜entity:file␝uri␞␟url,alt↠alt,width↠width,height↠height}',
          ],
        ],
      ],
      'sdc.xb_test_sdc.image-optional-with-example-and-additional-prop' => [
        'prop_field_definitions' => [
          'heading' => [
            'field_type' => 'string',
            'field_storage_settings' => [],
            'field_instance_settings' => [],
            'field_widget' => 'string_textfield',
            'default_value' => NULL,
            'expression' => 'ℹ︎string␟value',
          ],
          'image' => [
            'field_type' => 'image',
            'field_storage_settings' => [],
            'field_instance_settings' => [],
            'field_widget' => 'image_image',
            // ⚠️ Empty default value.
            // @see \Drupal\experience_builder\Plugin\ExperienceBuilder\ComponentSource\GeneratedFieldExplicitInputUxComponentSourceBase::exampleValueRequiresEntity()
            'default_value' => [],
            'expression' => 'ℹ︎image␟{src↝entity␜␜entity:file␝uri␞␟url,alt↠alt,width↠width,height↠height}',
          ],
        ],
      ],
      'sdc.xb_test_sdc.image-optional-without-example' => [
        'prop_field_definitions' => [
          'image' => [
            'field_type' => 'image',
            'field_storage_settings' => [],
            'field_instance_settings' => [],
            'field_widget' => 'image_image',
            // ⚠️ Empty default value.
            // @see \Drupal\experience_builder\Plugin\ExperienceBuilder\ComponentSource\GeneratedFieldExplicitInputUxComponentSourceBase::exampleValueRequiresEntity()
            'default_value' => [],
            'expression' => 'ℹ︎image␟{src↝entity␜␜entity:file␝uri␞␟url,alt↠alt,width↠width,height↠height}',
          ],
        ],
      ],
      'sdc.xb_test_sdc.image-required-with-example' => [
        'prop_field_definitions' => [
          'image' => [
            'field_type' => 'image',
            'field_storage_settings' => [],
            'field_instance_settings' => [],
            'field_widget' => 'image_image',
            // ⚠️ Empty default value.
            // @see \Drupal\experience_builder\Plugin\ExperienceBuilder\ComponentSource\GeneratedFieldExplicitInputUxComponentSourceBase::exampleValueRequiresEntity()
            'default_value' => [],
            'expression' => 'ℹ︎image␟{src↝entity␜␜entity:file␝uri␞␟url,alt↠alt,width↠width,height↠height}',
          ],
        ],
      ],
      'sdc.xb_test_sdc.my-cta' => [
        'prop_field_definitions' => [
          'href' => [
            'field_type' => 'link',
            'field_storage_settings' => [],
            'field_instance_settings' => [
              'title' => 0,
            ],
            'field_widget' => 'link_default',
            'default_value' => [
              0 => [
                'uri' => 'https://www.drupal.org',
                'options' => [],
              ],
            ],
            'expression' => 'ℹ︎link␟url',
          ],
          'target' => [
            'field_type' => 'list_string',
            'field_storage_settings' => [
              'allowed_values' => [
                0 => [
                  'value' => '_self',
                  'label' => '_self',
                ],
                1 => [
                  'value' => '_blank',
                  'label' => '_blank',
                ],
              ],
            ],
            'field_instance_settings' => [],
            'field_widget' => 'options_select',
            'default_value' => NULL,
            'expression' => 'ℹ︎list_string␟value',
          ],
          'text' => [
            'field_type' => 'string',
            'field_storage_settings' => [],
            'field_instance_settings' => [],
            'field_widget' => 'string_textfield',
            'default_value' => [
              0 => [
                'value' => 'Press',
              ],
            ],
            'expression' => 'ℹ︎string␟value',
          ],
        ],
      ],
      'sdc.xb_test_sdc.props-no-slots' => [
        'prop_field_definitions' => [
          'heading' => [
            'field_type' => 'string',
            'field_storage_settings' => [],
            'field_instance_settings' => [],
            'field_widget' => 'string_textfield',
            'default_value' => [0 => ['value' => 'There goes my hero']],
            'expression' => 'ℹ︎string␟value',
          ],
        ],
      ],
      'sdc.xb_test_sdc.props-slots' => [
        'prop_field_definitions' => [
          'heading' => [
            'field_type' => 'string',
            'field_storage_settings' => [],
            'field_instance_settings' => [],
            'field_widget' => 'string_textfield',
            'default_value' => [0 => ['value' => 'There goes my hero']],
            'expression' => 'ℹ︎string␟value',
          ],
        ],
      ],
      'sdc.xb_test_sdc.sparkline' => [
        'prop_field_definitions' => [
          'data' => [
            'field_type' => 'integer',
            'cardinality' => 100,
            'field_storage_settings' => [],
            'field_instance_settings' => [
              'min' => -100,
              'max' => 100,
            ],
            'field_widget' => 'number',
            'default_value' => [
              0 => ['value' => 0],
              1 => ['value' => 10],
              2 => ['value' => 20],
              3 => ['value' => 30],
              4 => ['value' => -40],
              5 => ['value' => -50],
              6 => ['value' => 5],
              7 => ['value' => 7],
              8 => ['value' => 9],
            ],
            'expression' => 'ℹ︎integer␟value',
          ],
        ],
      ],
    ];
  }

  /**
   * @covers ::calculateDependencies()
   * @depends testDiscovery
   */
  public function testCalculateDependencies(array $component_ids): void {
    self::assertSame([
      'sdc.xb_test_sdc.crash' => [
        'module' => [
          'core',
          'xb_test_sdc',
        ],
      ],
      'sdc.xb_test_sdc.deprecated' => [
        'module' => [
          'core',
          'xb_test_sdc',
        ],
      ],
      'sdc.xb_test_sdc.experimental' => [
        'module' => [
          'core',
          'xb_test_sdc',
        ],
      ],
      'sdc.xb_test_sdc.grid-container' => [
        'module' => [
          'core',
          'options',
          'xb_test_sdc',
        ],
      ],
      'sdc.xb_test_sdc.image-gallery' => [
        'content' => [],
        'module' => [
          'core',
          'file',
          'image',
          'xb_test_sdc',
        ],
      ],
      'sdc.xb_test_sdc.image-optional-with-example' => [
        'content' => [],
        'module' => [
          'file',
          'image',
          'xb_test_sdc',
        ],
      ],
      'sdc.xb_test_sdc.image-optional-with-example-and-additional-prop' => [
        'content' => [],
        'module' => [
          'core',
          'file',
          'image',
          'xb_test_sdc',
        ],
      ],
      'sdc.xb_test_sdc.image-optional-without-example' => [
        'content' => [],
        'module' => [
          'file',
          'image',
          'xb_test_sdc',
        ],
      ],
      'sdc.xb_test_sdc.image-required-with-example' => [
        'content' => [],
        'module' => [
          'file',
          'image',
          'xb_test_sdc',
        ],
      ],
      'sdc.xb_test_sdc.my-cta' => [
        'module' => [
          'core',
          'link',
          'options',
          'xb_test_sdc',
        ],
      ],
      'sdc.xb_test_sdc.props-no-slots' => [
        'module' => [
          'core',
          'xb_test_sdc',
        ],
      ],
      'sdc.xb_test_sdc.props-slots' => [
        'module' => [
          'core',
          'xb_test_sdc',
        ],
      ],
      'sdc.xb_test_sdc.sparkline' => [
        'module' => [
          'core',
          'xb_test_sdc',
        ],
      ],
    ], $this->callSourceMethodForEach('calculateDependencies', $component_ids));
  }

  /**
   * {@inheritdoc}
   */
  public static function getExpectedClientSideInfo(): array {
    return [
      'sdc.xb_test_sdc.crash' => [
        'expected_output_selectors' => [
          'h1:contains("test")',
        ],
        'source' => 'Module component',
        'metadata' => ['slots' => []],
        'propSources' => [
          'crash' => [
            'required' => TRUE,
            'jsonSchema' => [
              'type' => 'boolean',
            ],
            'sourceType' => 'static:field_item:boolean',
            'expression' => 'ℹ︎boolean␟value',
            'default_values' => [
              'source' => [
                0 => ['value' => FALSE],
              ],
              'resolved' => FALSE,
            ],
          ],
        ],
        'transforms' => [],
      ],
      'sdc.xb_test_sdc.deprecated' => [
        'expected_output_selectors' => [
          'h1:contains("Deprecated SDC component")',
          'div[data-component-id="xb_test_sdc:deprecated"]',
        ],
        'source' => 'Module component',
        'metadata' => ['slots' => []],
        'propSources' => [
          'text' => [
            'required' => FALSE,
            'jsonSchema' => [
              'type' => 'string',
            ],
            'sourceType' => 'static:field_item:string',
            'expression' => 'ℹ︎string␟value',
            'default_values' => [
              'source' => [
                0 => ['value' => 'A text field'],
              ],
              'resolved' => 'A text field',
            ],
          ],
        ],
        'transforms' => [],
      ],
      'sdc.xb_test_sdc.experimental' => [
        'expected_output_selectors' => [
          'h1:contains("Experimental SDC component")',
          'div[data-component-id="xb_test_sdc:experimental"]',
        ],
        'source' => 'Module component',
        'metadata' => ['slots' => []],
        'propSources' => [
          'text' => [
            'required' => FALSE,
            'jsonSchema' => [
              'type' => 'string',
            ],
            'sourceType' => 'static:field_item:string',
            'expression' => 'ℹ︎string␟value',
            'default_values' => [
              'source' => [
                0 => ['value' => 'A text field'],
              ],
              'resolved' => 'A text field',
            ],
          ],
        ],
        'transforms' => [],
      ],
      'sdc.xb_test_sdc.grid-container' => [
        'expected_output_selectors' => [
          'div[data-component-id="xb_test_sdc:grid-container"]',
        ],
        'source' => 'Module component',
        'metadata' => [
          'slots' => [
            'content' => [
              'title' => 'Content',
              'description' => 'The contents of the grid container.',
              'examples' => [
                '<div class="empty-slot">Empty grid slot</div>',
              ],
            ],
          ],
        ],
        'propSources' => [
          'direction' => [
            'required' => TRUE,
            'jsonSchema' => [
              'type' => 'string',
              'enum' => [
                0 => 'horizontal',
                1 => 'vertical',
              ],
            ],
            'sourceType' => 'static:field_item:list_string',
            'expression' => 'ℹ︎list_string␟value',
            'sourceTypeSettings' => [
              'storage' => [
                'allowed_values' => [
                  [
                    'value' => 'horizontal',
                    'label' => 'horizontal',
                  ],
                  [
                    'value' => 'vertical',
                    'label' => 'vertical',
                  ],
                ],
              ],
            ],
            'default_values' => [
              'source' => [
                0 => ['value' => 'horizontal'],
              ],
              'resolved' => 'horizontal',
            ],
          ],
        ],
        'transforms' => [],
      ],
      'sdc.xb_test_sdc.image-gallery' => [
        'expected_output_selectors' => [
          'figure > img[src="' . self::getCiModulePath() . '/tests/modules/xb_test_sdc/components/image-gallery/gracie.jpg"][alt="A good dog"]',
          'figure > img[src="' . self::getCiModulePath() . '/tests/modules/xb_test_sdc/components/image-gallery/gracie.jpg"][alt="Still a good dog"]',
          'figure > img[src="' . self::getCiModulePath() . '/tests/modules/xb_test_sdc/components/image-gallery/UPPERCASE-GRACIE.JPG"][alt="THE BEST DOG!"]',
        ],
        'source' => 'Module component',
        'metadata' => ['slots' => []],
        'propSources' => [
          'caption' => [
            'required' => FALSE,
            'jsonSchema' => [
              'type' => 'string',
            ],
            'sourceType' => 'static:field_item:string',
            'expression' => 'ℹ︎string␟value',
          ],
          'images' => [
            'required' => TRUE,
            'jsonSchema' => [
              'type' => 'array',
              'items' => [
                'title' => 'image',
                'type' => 'object',
                'required' => [
                  'src',
                ],
                'properties' => [
                  'src' => [
                    'title' => 'Image URL',
                    'type' => 'string',
                    'format' => 'uri-reference',
                    'pattern' => '^(/|https?://)?.*\\.([Pp][Nn][Gg]|[Gg][Ii][Ff]|[Jj][Pp][Gg]|[Jj][Pp][Ee][Gg]|[Ww][Ee][Bb][Pp]|[Aa][Vv][Ii][Ff])(\\?.*)?(#.*)?$',
                  ],
                  'alt' => [
                    'title' => 'Alternative text',
                    'type' => 'string',
                  ],
                  'width' => [
                    'title' => 'Image width',
                    'type' => 'integer',
                  ],
                  'height' => [
                    'title' => 'Image height',
                    'type' => 'integer',
                  ],
                ],
              ],
            ],
            'sourceType' => 'static:field_item:image',
            'expression' => 'ℹ︎image␟{src↝entity␜␜entity:file␝uri␞␟url,alt↠alt,width↠width,height↠height}',
            'sourceTypeSettings' => [
              'cardinality' => FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED,
            ],
            'default_values' => [
              'source' => [],
              'resolved' => [
                0 => [
                  'src' => self::getCiModulePath() . '/tests/modules/xb_test_sdc/components/image-gallery/gracie.jpg',
                  'alt' => 'A good dog',
                  'width' => 601,
                  'height' => 402,
                ],
                1 => [
                  'src' => self::getCiModulePath() . '/tests/modules/xb_test_sdc/components/image-gallery/gracie.jpg',
                  'alt' => 'Still a good dog',
                  'width' => 601,
                  'height' => 402,
                ],
                2 => [
                  'src' => self::getCiModulePath() . '/tests/modules/xb_test_sdc/components/image-gallery/UPPERCASE-GRACIE.JPG',
                  'alt' => 'THE BEST DOG!',
                  'width' => 601,
                  'height' => 402,
                ],
              ],
            ],
          ],
        ],
        'transforms' => [],
      ],
      'sdc.xb_test_sdc.image-optional-with-example' => [
        'expected_output_selectors' => [
          'img[src="https://example.com/cat.jpg"]',
        ],
        'source' => 'Module component',
        'metadata' => ['slots' => []],
        'propSources' => [
          'image' => [
            'required' => FALSE,
            'jsonSchema' => [
              'title' => 'image',
              'type' => 'object',
              'required' => [
                'src',
              ],
              'properties' => [
                'src' => [
                  'title' => 'Image URL',
                  'type' => 'string',
                  'format' => 'uri-reference',
                  'pattern' => '^(/|https?://)?.*\\.([Pp][Nn][Gg]|[Gg][Ii][Ff]|[Jj][Pp][Gg]|[Jj][Pp][Ee][Gg]|[Ww][Ee][Bb][Pp]|[Aa][Vv][Ii][Ff])(\\?.*)?(#.*)?$',
                ],
                'alt' => [
                  'title' => 'Alternative text',
                  'type' => 'string',
                ],
                'width' => [
                  'title' => 'Image width',
                  'type' => 'integer',
                ],
                'height' => [
                  'title' => 'Image height',
                  'type' => 'integer',
                ],
              ],
            ],
            'sourceType' => 'static:field_item:image',
            'expression' => 'ℹ︎image␟{src↝entity␜␜entity:file␝uri␞␟url,alt↠alt,width↠width,height↠height}',
            'default_values' => [
              'source' => [],
              'resolved' => [
                'src' => 'https://example.com/cat.jpg',
                'alt' => 'Boring placeholder',
                'width' => 600,
                'height' => 400,
              ],
            ],
          ],
        ],
        'transforms' => [],
      ],
      'sdc.xb_test_sdc.image-optional-with-example-and-additional-prop' => [
        'expected_output_selectors' => [
          'img[src="' . self::getCiModulePath() . '/tests/modules/xb_test_sdc/components/image-optional-with-example-and-additional-prop/gracie.jpg"]',
        ],
        'source' => 'Module component',
        'metadata' => ['slots' => []],
        'propSources' => [
          'heading' => [
            'required' => FALSE,
            'jsonSchema' => [
              'type' => 'string',
            ],
            'sourceType' => 'static:field_item:string',
            'expression' => 'ℹ︎string␟value',
          ],
          'image' => [
            'required' => FALSE,
            'jsonSchema' => [
              'title' => 'image',
              'type' => 'object',
              'required' => [
                'src',
              ],
              'properties' => [
                'src' => [
                  'title' => 'Image URL',
                  'type' => 'string',
                  'format' => 'uri-reference',
                  'pattern' => '^(/|https?://)?.*\\.([Pp][Nn][Gg]|[Gg][Ii][Ff]|[Jj][Pp][Gg]|[Jj][Pp][Ee][Gg]|[Ww][Ee][Bb][Pp]|[Aa][Vv][Ii][Ff])(\\?.*)?(#.*)?$',
                ],
                'alt' => [
                  'title' => 'Alternative text',
                  'type' => 'string',
                ],
                'width' => [
                  'title' => 'Image width',
                  'type' => 'integer',
                ],
                'height' => [
                  'title' => 'Image height',
                  'type' => 'integer',
                ],
              ],
            ],
            'sourceType' => 'static:field_item:image',
            'expression' => 'ℹ︎image␟{src↝entity␜␜entity:file␝uri␞␟url,alt↠alt,width↠width,height↠height}',
            'default_values' => [
              'source' => [],
              'resolved' => [
                'src' => self::getCiModulePath() . '/tests/modules/xb_test_sdc/components/image-optional-with-example-and-additional-prop/gracie.jpg',
                'alt' => 'A good dog',
                'width' => 601,
                'height' => 402,
              ],
            ],
          ],
        ],
        'transforms' => [],
      ],
      'sdc.xb_test_sdc.image-optional-without-example' => [
        'expected_output_selectors' => [],
        'source' => 'Module component',
        'metadata' => ['slots' => []],
        'propSources' => [
          'image' => [
            'required' => FALSE,
            'jsonSchema' => [
              'title' => 'image',
              'type' => 'object',
              'required' => [
                'src',
              ],
              'properties' => [
                'src' => [
                  'title' => 'Image URL',
                  'type' => 'string',
                  'format' => 'uri-reference',
                  'pattern' => '^(/|https?://)?.*\\.([Pp][Nn][Gg]|[Gg][Ii][Ff]|[Jj][Pp][Gg]|[Jj][Pp][Ee][Gg]|[Ww][Ee][Bb][Pp]|[Aa][Vv][Ii][Ff])(\\?.*)?(#.*)?$',
                ],
                'alt' => [
                  'title' => 'Alternative text',
                  'type' => 'string',
                ],
                'width' => [
                  'title' => 'Image width',
                  'type' => 'integer',
                ],
                'height' => [
                  'title' => 'Image height',
                  'type' => 'integer',
                ],
              ],
            ],
            'sourceType' => 'static:field_item:image',
            'expression' => 'ℹ︎image␟{src↝entity␜␜entity:file␝uri␞␟url,alt↠alt,width↠width,height↠height}',
          ],
        ],
        'transforms' => [],
      ],
      'sdc.xb_test_sdc.image-required-with-example' => [
        'expected_output_selectors' => [
          'img[src="https://example.com/cat.jpg"]',
        ],
        'source' => 'Module component',
        'metadata' => ['slots' => []],
        'propSources' => [
          'image' => [
            'required' => TRUE,
            'jsonSchema' => [
              'title' => 'image',
              'type' => 'object',
              'required' => [
                'src',
              ],
              'properties' => [
                'src' => [
                  'title' => 'Image URL',
                  'type' => 'string',
                  'format' => 'uri-reference',
                  'pattern' => '^(/|https?://)?.*\\.([Pp][Nn][Gg]|[Gg][Ii][Ff]|[Jj][Pp][Gg]|[Jj][Pp][Ee][Gg]|[Ww][Ee][Bb][Pp]|[Aa][Vv][Ii][Ff])(\\?.*)?(#.*)?$',
                ],
                'alt' => [
                  'title' => 'Alternative text',
                  'type' => 'string',
                ],
                'width' => [
                  'title' => 'Image width',
                  'type' => 'integer',
                ],
                'height' => [
                  'title' => 'Image height',
                  'type' => 'integer',
                ],
              ],
            ],
            'sourceType' => 'static:field_item:image',
            'expression' => 'ℹ︎image␟{src↝entity␜␜entity:file␝uri␞␟url,alt↠alt,width↠width,height↠height}',
            'default_values' => [
              'source' => [],
              'resolved' => [
                'src' => 'https://example.com/cat.jpg',
                'alt' => 'Boring placeholder',
                'width' => 600,
                'height' => 400,
              ],
            ],
          ],
        ],
        'transforms' => [],
      ],
      'sdc.xb_test_sdc.my-cta' => [
        'expected_output_selectors' => [
          'a:contains("Press")',
          'a[data-component-id="xb_test_sdc:my-cta"]',
        ],
        'source' => 'Module component',
        'metadata' => [
          'slots' => [],
        ],
        'propSources' => [
          'text' => [
            'required' => TRUE,
            'jsonSchema' => [
              'type' => 'string',
            ],
            'sourceType' => 'static:field_item:string',
            'expression' => 'ℹ︎string␟value',
            'default_values' => [
              'source' => [
                0 => [
                  'value' => 'Press',
                ],
              ],
              'resolved' => 'Press',
            ],
          ],
          'href' => [
            'required' => TRUE,
            'jsonSchema' => [
              'type' => 'string',
              'format' => 'uri',
            ],
            'sourceType' => 'static:field_item:link',
            'expression' => 'ℹ︎link␟url',
            'sourceTypeSettings' => [
              'instance' => [
                'title' => 0,
              ],
            ],
            'default_values' => [
              'source' => [
                0 => [
                  'uri' => 'https://www.drupal.org',
                  'options' => [],
                ],
              ],
              'resolved' => 'https://www.drupal.org',
            ],
          ],
          'target' => [
            'required' => FALSE,
            'jsonSchema' => [
              'type' => 'string',
              'enum' => [
                0 => '_self',
                1 => '_blank',
              ],
            ],
            'sourceType' => 'static:field_item:list_string',
            'expression' => 'ℹ︎list_string␟value',
            'sourceTypeSettings' => [
              'storage' => [
                'allowed_values' => [
                  0 => [
                    'value' => '_self',
                    'label' => '_self',
                  ],
                  1 => [
                    'value' => '_blank',
                    'label' => '_blank',
                  ],
                ],
              ],
            ],
          ],
        ],
        'transforms' => [],
      ],
      'sdc.xb_test_sdc.props-no-slots' => [
        'expected_output_selectors' => [
          'h1:contains("There goes my hero")',
          'div[data-component-id="xb_test_sdc:props-no-slots"]',
        ],
        'source' => 'Module component',
        'metadata' => ['slots' => []],
        'propSources' => [
          'heading' => [
            'required' => TRUE,
            'jsonSchema' => [
              'type' => 'string',
              'minLength' => 2,
            ],
            'sourceType' => 'static:field_item:string',
            'expression' => 'ℹ︎string␟value',
            'default_values' => [
              'source' => [
                0 => ['value' => 'There goes my hero'],
              ],
              'resolved' => 'There goes my hero',
            ],
          ],
        ],
        'transforms' => [],
      ],
      'sdc.xb_test_sdc.props-slots' => [
        'expected_output_selectors' => [
          'div[data-component-id="xb_test_sdc:props-slots"]',
          'h1:contains("There goes my hero")',
          'div.component--props-slots--body',
          'div.component--props-slots--footer',
          'div.component--props-slots--colophon',
        ],
        'source' => 'Module component',
        'metadata' => [
          'slots' => [
            'the_body' => [
              'title' => 'The Body',
              'description' => 'The contents of the body.',
              'examples' => [
                '<p>Example value for <strong>the_body</strong> slot in <strong>prop-slots</strong> component.</p>',
              ],
            ],
            'the_footer' => [
              'title' => 'The Footer',
              'description' => 'The contents of the footer.',
              'examples' => [
                'Example value for <strong>the_footer</strong>.',
              ],
            ],
            'the_colophon' => [
              'title' => 'The Colophon',
              'description' => 'The contents of the colophon.',
              'examples' => [],
            ],
          ],
        ],
        'propSources' => [
          'heading' => [
            'required' => TRUE,
            'jsonSchema' => [
              'type' => 'string',
              'minLength' => 2,
            ],
            'sourceType' => 'static:field_item:string',
            'expression' => 'ℹ︎string␟value',
            'default_values' => [
              'source' => [
                0 => ['value' => 'There goes my hero'],
              ],
              'resolved' => 'There goes my hero',
            ],
          ],
        ],
        'transforms' => [],
      ],
      'sdc.xb_test_sdc.sparkline' => [
        'expected_output_selectors' => [
          'div.sparkline-container > svg > polygon[points="0,20 0,7.5 12.5,5 25,2.5 37.5,0 50,17.5 62.5,20 75,6.25 87.5,5.75 100,5.25 100,20"]',
        ],
        'source' => 'Module component',
        'metadata' => ['slots' => []],
        'propSources' => [
          'data' => [
            'required' => TRUE,
            'jsonSchema' => [
              'type' => 'array',
              'items' => [
                'type' => 'integer',
                'minimum' => -100,
                'maximum' => 100,
              ],
              'maxItems' => 100,
            ],
            'sourceType' => 'static:field_item:integer',
            'expression' => 'ℹ︎integer␟value',
            'sourceTypeSettings' => [
              'instance' => ['min' => -100, 'max' => 100],
              'cardinality' => 100,
            ],
            'default_values' => [
              'source' => [
                0 => ['value' => 0],
                1 => ['value' => 10],
                2 => ['value' => 20],
                3 => ['value' => 30],
                4 => ['value' => -40],
                5 => ['value' => -50],
                6 => ['value' => 5],
                7 => ['value' => 7],
                8 => ['value' => 9],
              ],
              'resolved' => [
                0, 10, 20, 30, -40, -50, 5, 7, 9,
              ],
            ],
          ],
        ],
        'transforms' => [],
      ],
    ];
  }

  /**
   * @covers ::inputToClientModel()
   * @dataProvider explicitsInputsProvider
   */
  public function testInputToClientModel(string $component_id, array $explicit_input, array $expected_client_model):void {
    $this->generateComponentConfig();

    // Ensure the explicit input is valid; helps catch incorrect test cases that
    // could lead to misleading test results.
    foreach ($explicit_input['source'] as $prop_name => $prop_source_array) {
      $resolved_source = PropSource::parse($prop_source_array)->evaluate(NULL);
      self::assertSame($resolved_source, $explicit_input['resolved'][$prop_name]);
    }

    $component = Component::load($component_id);
    \assert($component instanceof Component);
    $component_source = $component->getComponentSource();
    // It can only be Code components or SDC.
    \assert($component_source instanceof GeneratedFieldExplicitInputUxComponentSourceBase);
    $actual_model_client = $component_source->inputToClientModel($explicit_input);
    $this->assertEquals($expected_client_model, $actual_model_client);
  }

  public static function explicitsInputsProvider(): \Generator {
    yield 'image' => [
      'sdc.experience_builder.image',
      [
        'source' => [
          'image' => [
            'sourceType' => 'default-relative-url',
            'value' => [
              'src' => '/modules/contrib/experience_builder/components/image/600x400.png',
              'alt' => 'Boring placeholder',
              'width' => 600,
              'height' => 400,
            ],
            'jsonSchema' => [
              'type' => 'object',
              'properties' => [
                'src' => [
                  'type' => 'string',
                  'format' => 'uri-reference',
                  'pattern' => '^(/|https?://)?.*\\.([Pp][Nn][Gg]|[Gg][Ii][Ff]|[Jj][Pp][Gg]|[Jj][Pp][Ee][Gg]|[Ww][Ee][Bb][Pp]|[Aa][Vv][Ii][Ff])(\\?.*)?(#.*)?$',
                ],
                'alt' => [
                  'type' => 'string',
                ],
                'width' => [
                  'type' => 'integer',
                ],
                'height' => [
                  'type' => 'integer',
                ],
              ],
              'required' => [
                'src',
              ],
            ],
            'componentId' => 'sdc.experience_builder.image',
          ],
        ],
        'resolved' => [
          'image' => [
            'src' => '/modules/contrib/experience_builder/components/image/600x400.png',
            'alt' => 'Boring placeholder',
            'width' => 600,
            'height' => 400,
          ],
        ],
      ],
      [
        'source' => [
          'image' => [
            'sourceType' => 'static:field_item:image',
            'expression' => 'ℹ︎image␟{src↝entity␜␜entity:file␝uri␞␟url,alt↠alt,width↠width,height↠height}',
          ],
        ],
        'resolved' => [
          'image' => [
            'src' => '/modules/contrib/experience_builder/components/image/600x400.png',
            'alt' => 'Boring placeholder',
            'width' => 600,
            'height' => 400,
          ],
        ],
      ],
    ];
  }

  protected function createAndSaveInUseComponentForFallbackTesting(): ComponentInterface {
    // Media library depends on the views module and media depends on field
    // config.
    $this->enableModules(['media', 'media_library', 'views', 'field']);
    $this->installEntitySchema('file');
    $this->installSchema('file', 'file_usage');
    $this->installEntitySchema('media');
    $this->createMediaType('image', ['id' => 'image', 'label' => 'Image']);
    /** @var \Drupal\experience_builder\Entity\ComponentInterface */
    return Component::load('sdc.experience_builder.image');
  }

  protected function createAndSaveUnusedComponentForFallbackTesting(): ComponentInterface {
    /** @var \Drupal\experience_builder\Entity\ComponentInterface */
    return Component::load('sdc.xb_test_sdc.image-optional-without-example');
  }

  protected function deleteConfigAndTriggerComponentFallback(ComponentInterface $used_component, ComponentInterface $unused_component): void {
    $type = MediaType::load('image');
    \assert($type instanceof MediaType);
    $type->delete();
  }

  protected static function getPropsForComponentFallbackTesting(): array {
    /** @var \Drupal\Core\File\FileSystemInterface $file_system */
    $file_system = \Drupal::service('file_system');
    $file_uri = 'public://image-2.jpg';
    if (!\file_exists($file_uri)) {
      $file_system->copy(\Drupal::root() . '/core/tests/fixtures/files/image-2.jpg', PublicStream::basePath(), FileExists::Replace);
    }
    $file = File::create([
      'uri' => $file_uri,
      'status' => 1,
    ]);
    $file->save();
    $image = Media::create([
      'bundle' => 'image',
      'name' => 'Amazing image',
      'field_media_image' => [
        [
          'target_id' => $file->id(),
          'alt' => 'An image so amazing that to gaze upon it would melt your face',
          'title' => 'This is an amazing image, just look at it and you will be amazed',
        ],
      ],
    ]);
    $image->save();
    return [
      'image' => [
        'sourceType' => 'static:field_item:entity_reference',
        'value' => ['target_id' => $image->id()],
        // This expression resolves `src` to the image's public URL.
        'expression' => 'ℹ︎entity_reference␟{src↝entity␜␜entity:media:image␝field_media_image␞␟entity␜␜entity:file␝uri␞␟url,alt↝entity␜␜entity:media:image␝field_media_image␞␟alt,width↝entity␜␜entity:media:image␝field_media_image␞␟width,height↝entity␜␜entity:media:image␝field_media_image␞␟height}',
        'sourceTypeSettings' => [
          'storage' => ['target_type' => 'media'],
          'instance' => [
            'handler' => 'default:media',
            'handler_settings' => [
              'target_bundles' => ['image' => 'image'],
            ],
          ],
        ],
      ],
    ];
  }

  protected function recoverComponentFallback(ComponentInterface $component): void {
    $this->createMediaType('image', ['id' => 'image', 'label' => 'Image']);
    $this->generateComponentConfig();
  }

  protected function createAndSaveInUseComponentForUninstallValidationTesting(): ComponentInterface {
    $this->enableModules(['sdc_test']);
    $this->generateComponentConfig();
    /** @var \Drupal\experience_builder\Entity\ComponentInterface */
    return Component::load('sdc.sdc_test.no-props');
  }

  protected function createAndSaveUnusedComponentForUninstallValidationTesting(): ComponentInterface {
    /** @var \Drupal\experience_builder\Entity\ComponentInterface */
    return Component::load('sdc.xb_test_sdc.props-slots');
  }

  protected function getNotAllowedModuleForUninstallValidatorTesting(): string {
    return 'sdc_test';
  }

  protected function getAllowedModuleForUninstallValidatorTesting(): string {
    return 'xb_test_sdc';
  }

}
