<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Kernel\Plugin\ExperienceBuilder\ComponentSource;

// cspell:ignore Tilly

use Drupal\Component\Serialization\Json;
use Drupal\Component\Utility\Crypt;
use Drupal\Core\Asset\AssetResolverInterface;
use Drupal\Core\Asset\AttachedAssets;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Site\Settings;
use Drupal\Core\StreamWrapper\StreamWrapperInterface;
use Drupal\Core\StreamWrapper\StreamWrapperManagerInterface;
use Drupal\experience_builder\AutoSave\AutoSaveManager;
use Drupal\experience_builder\Entity\Component;
use Drupal\experience_builder\Entity\ComponentInterface;
use Drupal\experience_builder\Entity\JavaScriptComponent;
use Drupal\experience_builder\Plugin\ExperienceBuilder\ComponentSource\JsComponent;
use Drupal\experience_builder\PropExpressions\StructuredData\FieldTypePropExpression;
use Drupal\experience_builder\PropSource\StaticPropSource;
use Drupal\Tests\experience_builder\Traits\CrawlerTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\xb_test_code_components\Hook\IslandCastaway;
use Twig\Error\RuntimeError;

/**
 * Tests JsComponent.
 *
 * @covers \Drupal\experience_builder\Plugin\ExperienceBuilder\ComponentSource\JsComponent
 * @group experience_builder
 * @group JavaScriptComponents
 *
 * @phpstan-import-type ComponentConfigEntityId from \Drupal\experience_builder\Entity\Component
 */
final class JsComponentTest extends ComponentSourceTestBase {

  use UserCreationTrait;
  use CrawlerTrait;

  protected readonly AssetResolverInterface $assetResolver;
  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'xb_test_code_components',
  ];

  /**
   * {@inheritdoc}
   */
  public function setUp(): void {
    parent::setUp();
    $this->assetResolver = $this->container->get(AssetResolverInterface::class);
  }

  protected function generateComponentConfig(): void {
    parent::generateComponentConfig();
    $this->container->get('config.installer')->installDefaultConfig('module', 'xb_test_code_components');
  }

  public function testDiscovery(): array {
    self::assertSame([], $this->findCreatedComponentConfigEntities(JsComponent::SOURCE_PLUGIN_ID, 'xb_test_code_components'));

    $this->generateComponentConfig();

    // ⚠️ It is impossible to create ineligible JavaScriptComponent config entities!
    // @see \Drupal\Tests\experience_builder\Kernel\Config\JavaScriptComponentValidationTest::providerTestEntityShapes()
    self::assertSame([], $this->findIneligibleComponents(JsComponent::SOURCE_PLUGIN_ID, 'xb_test_code_components'));
    $expected_js_component_ids = array_keys(self::getExpectedSettings());
    $js_components = $this->findCreatedComponentConfigEntities(JsComponent::SOURCE_PLUGIN_ID, 'xb_test_code_components');

    self::assertSame($expected_js_component_ids, $js_components);

    return $js_components;
  }

  /**
   * @param array<ComponentConfigEntityId> $component_ids
   * @covers ::getReferencedPluginClass()
   * @depends testDiscovery
   */
  public function testGetReferencedPluginClass(array $component_ids): void {
    self::assertSame(
      // Code components are not plugins, but config entities!
      array_fill_keys($component_ids, NULL),
      $this->getReferencedPluginClasses($component_ids)
    );
  }

  /**
   * Tests the shape-matched `prop_field_definitions` for all code components.
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

  public static function getExpectedSettings(): array {
    return [
      'js.xb_test_code_components_vanilla_image' => [
        'plugin_id' => 'xb_test_code_components_vanilla_image',
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
      'js.xb_test_code_components_with_no_props' => [
        'plugin_id' => 'xb_test_code_components_with_no_props',
        'prop_field_definitions' => [],
      ],
      'js.xb_test_code_components_with_props' => [
        'plugin_id' => 'xb_test_code_components_with_props',
        'prop_field_definitions' => [
          'age' => [
            'field_type' => 'integer',
            'field_storage_settings' => [],
            'field_instance_settings' => [],
            'field_widget' => 'number',
            'default_value' => ['value' => 40],
            'expression' => 'ℹ︎integer␟value',
          ],
          'name' => [
            'field_type' => 'string',
            'field_storage_settings' => [],
            'field_instance_settings' => [],
            'field_widget' => 'string_textfield',
            'default_value' => ['value' => 'XB'],
            'expression' => 'ℹ︎string␟value',
          ],
        ],
      ],
    ];
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
        JsComponent::EXPLICIT_INPUT_NAME => array_map(
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

    // ⚠️ The `'html'` expectations are tested separately for this very complex
    // rendering.
    // @see ::testRenderComponent()
    $rendered_without_html = array_map(
      fn ($expectations) => array_diff_key($expectations, ['html' => NULL]),
      $rendered,
    );

    $default_render_cache_contexts = [
      'languages:language_interface',
      'theme',
      'user.permissions',
    ];
    $default_cacheability = (new CacheableMetadata())
      ->setCacheContexts($default_render_cache_contexts);
    $this->assertEquals([
      'js.xb_test_code_components_vanilla_image' => [
        'cacheability' => $default_cacheability,
      ],
      'js.xb_test_code_components_with_no_props' => [
        'cacheability' => $default_cacheability,
      ],
      'js.xb_test_code_components_with_props' => [
        'cacheability' => $default_cacheability,
      ],
    ], $rendered_without_html);
  }

  /**
   * For JavaScript components, auto-saves create an extra testing dimension!
   *
   * @depends testDiscovery
   * @testWith [false, false, "live"]
   *           [false, true, "live"]
   *           [true, false, "live"]
   *           [true, true, "draft"]
   */
  public function testRenderJsComponent(bool $preview_requested, bool $auto_save_exists, string $expected_result, array $component_ids): void {
    $this->generateComponentConfig();
    foreach ($this->componentStorage->loadMultiple($component_ids) as $component) {
      assert($component instanceof Component);
      $source = $component->getComponentSource();
      \assert($source instanceof JsComponent);
      $this->assertRenderedAstroIsland($component, $preview_requested, $auto_save_exists, $expected_result);
    }
  }

  /**
   * Helper function to render a component and assert the result.
   *
   * @param \Drupal\experience_builder\Entity\Component $component
   * @param bool $preview_requested
   * @param bool $auto_save_exists
   * @param string $expected_result
   *
   * @return void
   */
  private function assertRenderedAstroIsland(
    Component $component,
    bool $preview_requested,
    bool $auto_save_exists,
    string $expected_result,
  ): void {
    $source = $component->getComponentSource();
    \assert($source instanceof JsComponent);
    $js_component_id = $component->getSettings()['plugin_id'];
    $js_component = $source->getJavaScriptComponent();
    $expected_component_compiled_js = $js_component->getJs();
    $expected_component_compiled_css = $js_component->getCss();
    $expected_component_props = $js_component->getProps();

    // Create auto-save entry if that's expected by this test case.
    if ($auto_save_exists) {
      $this->container->get(AutoSaveManager::class)
        ->save(
          $source->getJavaScriptComponent(),
          // 'imported_js_components' is a value sent by the client that is used to
          // determine Javascript Code component dependencies and is not saved
          // directly on the backend.
          // @see \Drupal\experience_builder\Entity\JavaScriptComponent::addJavaScriptComponentsDependencies().
          $source->getJavaScriptComponent()->normalizeForClientSide()->values + ['imported_js_components' => []],
        );
    }

    $island = $source->renderComponent([
      'props' => $expected_component_props,
    ], 'some-uuid', $preview_requested);
    $crawler = $this->crawlerForRenderArray($island);

    $element = $crawler->filter('astro-island');
    self::assertCount(1, $element);

    // Note that ::renderComponent adds both xb_uuid and xb_slot_ids props but
    // they should not be present as props in the astro-island element.
    // Ternary because empty arrays are encoded as '[]' in Json::encode().
    $json_expected = (empty($expected_component_props)) ? '{}' :
      Json::encode(\array_map(static fn(mixed $value): array => [
        'raw',
        $value,
      ], $expected_component_props));
    self::assertJsonStringEqualsJsonString($json_expected, $element->attr('props') ?? '');

    // Assert rendered code component's JS.
    $asset_wrapper = $this->container->get(StreamWrapperManagerInterface::class)->getViaScheme('assets');
    \assert($asset_wrapper instanceof StreamWrapperInterface);
    \assert(\method_exists($asset_wrapper, 'getDirectoryPath'));
    $directory_path = $asset_wrapper->getDirectoryPath();
    $js_hash = Crypt::hmacBase64($expected_component_compiled_js, Settings::getHashSalt());
    // @phpstan-ignore-next-line
    $expected_js_filename = match ($expected_result) {
      'live' => \sprintf('/%s/astro-island/%s.js', $directory_path, $js_hash),
      'draft' => \sprintf('/xb/api/auto-saves/js/%s/%s', JavaScriptComponent::ENTITY_TYPE_ID, $js_component_id),
    };
    $element_js_script = $element->attr('component-url');
    self::assertEquals($expected_js_filename, $element_js_script);

    $preloads = \array_column($island['#attached']['html_head_link'], 0);
    $hrefs = \array_column($preloads, 'href');
    self::assertContains($expected_js_filename, $hrefs);

    // Assert rendered code component's CSS, if any.
    if ($source->getJavaScriptComponent()->hasCss()) {
      // @phpstan-ignore-next-line
      $expected_css_asset_library = match ($expected_result) {
        'live' => 'experience_builder/astro_island.%s',
        'draft' => 'experience_builder/astro_island.%s.draft',
      };
      self::assertContains(\sprintf($expected_css_asset_library, $js_component_id), $island['#attached']['library']);

      // Assert rendered code component's CSS.
      $css_asset = $this->assetResolver->getCssAssets(AttachedAssets::createFromRenderArray($island), FALSE);
      // @phpstan-ignore-next-line
      $css_filename = match ($expected_result) {
        'live' => \sprintf(
          'assets://astro-island/%s.css',
          Crypt::hmacBase64($expected_component_compiled_css, Settings::getHashSalt()),
        ),
        'draft' => "xb/api/auto-saves/css/js_component/$js_component_id",
      };
      self::assertEquals($css_filename, reset($css_asset)['data']);
    }
  }

  /**
   * @covers ::calculateDependencies()
   * @depends testDiscovery
   */
  public function testCalculateDependencies(array $component_ids): void {
    self::assertSame([
      'js.xb_test_code_components_vanilla_image' => [
        'module' => [
          'image',
          'image',
        ],
        'config' => [
          'experience_builder.js_component.xb_test_code_components_vanilla_image',
        ],
      ],
      'js.xb_test_code_components_with_no_props' => [
        'config' => [
          'experience_builder.js_component.xb_test_code_components_with_no_props',
        ],
      ],
      'js.xb_test_code_components_with_props' => [
        'module' => [
          'core',
          'core',
          'core',
          'core',
        ],
        'config' => [
          'experience_builder.js_component.xb_test_code_components_with_props',
        ],
      ],
    ], $this->getAllCalculatedDependencies($component_ids));
  }

  /**
   * {@inheritdoc}
   */
  public static function providerRenderComponentFailure(): \Generator {
    $generate_static_prop_source = function (string $field_type, mixed $value): array {
      return [
        'sourceType' => "static:field_item:$field_type",
        'value' => $value,
        'expression' => (string) new FieldTypePropExpression($field_type, 'value'),
      ];
    };

    $component_id = JsComponent::componentIdFromJavascriptComponentId('xb_test_code_components_with_props');
    yield "JS Component with valid props, without exception" => [
      'component_id' => $component_id,
      'inputs' => [
        'age' => $generate_static_prop_source('integer', 19),
        'name' => $generate_static_prop_source('string', 'Tilly'),
      ],
      'expected_validation_errors' => [],
      'expected_exception' => NULL,
      'expected_output_selector' => 'astro-island[uid="crash-test-dummy"][props*="Tilly"][props*="19"]',
    ];

    yield "JS Component with valid props, JSON encoding exception" => [
      'component_id' => $component_id,
      'inputs' => [
        'age' => $generate_static_prop_source('integer', 19),
        'name' => $generate_static_prop_source('string', IslandCastaway::WILSON),
      ],
      'expected_validation_errors' => [],
      'expected_exception' => [
        'class' => RuntimeError::class,
        'message' => 'An exception has been thrown during the rendering of a template ("Wilson is a ball, not a person")',
      ],
      'expected_output_selector' => NULL,
    ];

    yield "JS Component with invalid props, validation error" => [
      'component_id' => $component_id,
      'inputs' => [
        'age' => $generate_static_prop_source('string', "It's rude to ask"),
        'name' => $generate_static_prop_source('string', 'Tilly'),
      ],
      'expected_validation_errors' => [
        '.inputs.crash-test-dummy.age' => 'String value found, but an integer or an object is required. The provided value is: "It\'s rude to ask".',
      ],
      'expected_exception' => NULL,
      // JsComponents can recover from invalid inputs.
      'expected_output_selector' => 'astro-island[uid="crash-test-dummy"]',
    ];

    yield "JS Component with missing props, validation error" => [
      'component_id' => $component_id,
      'inputs' => [],
      'expected_validation_errors' => [
        '.inputs.crash-test-dummy.name' => 'The property name is required.',
      ],
      'expected_exception' => NULL,
      // JsComponents can recover from invalid inputs.
      'expected_output_selector' => 'astro-island[uid="crash-test-dummy"]',
    ];
  }

  /**
   * Tests that component dependencies are properly added to import maps.
   *
   * @testWith [false, false, false, "live"]
   *           [false, false, true, "live"]
   *           [false, true, false, "live"]
   *           [false, true, true, "live"]
   *           [true, false, false, "live"]
   *           [true, false, true, "draft"]
   *           [true, true, false, "live"]
   *           [true, true, true, "draft"]
   */
  public function testImportMaps(bool $preview, bool $create_auto_save, bool $create_dependency_auto_save, string $dependencies_expected_result): void {
    assert(in_array($dependencies_expected_result, ['draft', 'live'], TRUE));
    $file_generator = $this->container->get(FileUrlGeneratorInterface::class);
    \assert($file_generator instanceof FileUrlGeneratorInterface);
    // Create a dependency component first
    $dependency_js_component = JavaScriptComponent::create([
      'machineName' => 'dependency_component',
      'name' => 'Dependency Component',
      'status' => TRUE,
      'props' => [],
      'slots' => [],
      'block_override' => NULL,
      'css' => [
        'original' => '.dependency { color: blue; }',
        'compiled' => '.dependency{color:blue;}',
      ],
      'js' => [
        'original' => 'console.log("dependency loaded");',
        'compiled' => 'console.log("dependency loaded");',
      ],
    ]);
    $dependency_js_component->save();

    $dependency_js_component_without_css = JavaScriptComponent::create([
      'machineName' => 'dependency_component_no_css',
      'name' => 'Dependency Component No CSS',
      'status' => TRUE,
      'props' => [],
      'slots' => [],
      'block_override' => NULL,
      'css' => [
        'original' => '',
        'compiled' => '',
      ],
      'js' => [
        'original' => 'console.log("dependency loaded");',
        'compiled' => 'console.log("dependency loaded");',
      ],
    ]);
    $dependency_js_component_without_css->save();

    // Create the main component that depends on the dependency component.
    $js_component = JavaScriptComponent::create([
      'machineName' => $this->randomMachineName(),
      'name' => $this->getRandomGenerator()->sentences(5),
      'status' => TRUE,
      'props' => [
        'title' => [
          'type' => 'string',
          'title' => 'Title',
          'examples' => ['A title'],
        ],
      ],
      'required' => ['title'],
      'slots' => [],
      'block_override' => NULL,
      'css' => [
        'original' => '.test { display: none; }',
        'compiled' => '.test{display:none;}',
      ],
      'js' => [
        'original' => 'console.log( "hey" );',
        'compiled' => 'console.log("hey");',
      ],
    ]);
    // Add the dependency through client API.
    $js_component_data = $js_component->normalizeForClientSide()->values;
    $js_component_data['imported_js_components'] = ['dependency_component', 'dependency_component_no_css'];
    $js_component->updateFromClientSide($js_component_data);
    $js_component->save();

    $autoSave = $this->container->get(AutoSaveManager::class);
    if ($create_auto_save) {
      $autoSave->save(
        $js_component,
        $js_component->normalizeForClientSide()->values +
        [
          'imported_js_components' => ['dependency_component', 'dependency_component_no_css'],
        ]
      );
    }
    if ($create_dependency_auto_save) {
      $autoSave->save(
        $dependency_js_component,
        $dependency_js_component->normalizeForClientSide()->values + ['imported_js_components' => []],
      );
      $autoSave->save(
        $dependency_js_component_without_css,
        $dependency_js_component_without_css->normalizeForClientSide()->values + ['imported_js_components' => []],
      );
    }

    $component = Component::load(JsComponent::componentIdFromJavascriptComponentId((string) $js_component->id()));
    \assert($component instanceof ComponentInterface);
    $source = $component->getComponentSource();
    $rendered_component = $source->renderComponent([], 'test-uuid', $preview);
    self::assertArrayHasKey('#import_maps', $rendered_component);
    $dependency_import_key = '@/components/dependency_component';
    $dependency_without_css_import_key = '@/components/dependency_component_no_css';
    self::assertArrayHasKey($dependency_import_key, $rendered_component['#import_maps']);
    self::assertNotEmpty($rendered_component['#attached']['library']);
    $attached_libraries = $rendered_component['#attached']['library'];
    // The dependency without CSS should not have its library attached.
    self::assertNotContains('experience_builder/astro_island.dependency_component_no_css.draft', $attached_libraries);
    self::assertNotContains('experience_builder/astro_island.dependency_component_no_css', $attached_libraries);
    if ($dependencies_expected_result === 'draft') {
      $dependency_js_path = base_path() . 'xb/api/auto-saves/js/js_component/dependency_component';
      $dependency_without_css_js_path = base_path() . 'xb/api/auto-saves/js/js_component/dependency_component_no_css';
      self::assertContains('experience_builder/astro_island.dependency_component.draft', $attached_libraries);
      self::assertNotContains('experience_builder/astro_island.dependency_component', $attached_libraries);
    }
    else {
      $dependency_js_path = $file_generator->generateString($dependency_js_component->getJsPath());
      $dependency_without_css_js_path = $file_generator->generateString($dependency_js_component_without_css->getJsPath());
      self::assertContains('experience_builder/astro_island.dependency_component', $attached_libraries);
      self::assertNotContains('experience_builder/astro_island.dependency_component.draft', $attached_libraries);
    }
    self::assertEquals($dependency_js_path, $rendered_component['#import_maps'][$dependency_import_key]);
    self::assertEquals($dependency_without_css_js_path, $rendered_component['#import_maps'][$dependency_without_css_import_key]);

    // If we created an auto-save entry for the main component, and we are in
    // preview ensure that if the dependencies are changed in the auto-save
    // entry it is reflected in the import map and attached libraries.
    if ($create_auto_save && $preview) {
      $autoSave->save(
        $js_component,
        // Remove both dependencies from the auto-save entry.
        $js_component->normalizeForClientSide()->values + ['imported_js_components' => []],
      );
      $rendered_component = $source->renderComponent([], 'test-uuid', $preview);
      self::assertArrayHasKey('#import_maps', $rendered_component);
      // Ensure the dependencies are no longer in the import map.
      self::assertArrayNotHasKey($dependency_import_key, $rendered_component['#import_maps']);
      self::assertArrayNotHasKey($dependency_without_css_import_key, $rendered_component['#import_maps']);
      self::assertNotEmpty($rendered_component['#attached']['library']);
      self::assertEmpty(array_filter(
        $rendered_component['#attached']['library'],
        static fn($library) => str_contains($library, 'dependency_component')
      ));
    }
  }

}
