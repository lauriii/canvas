<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Kernel\Plugin\ExperienceBuilder\ComponentSource;

use Drupal\Component\Serialization\Json;
use Drupal\Component\Utility\Crypt;
use Drupal\Core\Asset\AssetResolverInterface;
use Drupal\Core\Asset\AttachedAssets;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Site\Settings;
use Drupal\Core\StreamWrapper\StreamWrapperInterface;
use Drupal\Core\StreamWrapper\StreamWrapperManagerInterface;
use Drupal\experience_builder\AutoSave\AutoSaveManager;
use Drupal\experience_builder\Entity\Component;
use Drupal\experience_builder\Entity\JavaScriptComponent;
use Drupal\experience_builder\Plugin\ExperienceBuilder\ComponentSource\JsComponent;
use Drupal\experience_builder\PropSource\StaticPropSource;
use Drupal\Tests\experience_builder\Traits\CrawlerTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;

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

}
