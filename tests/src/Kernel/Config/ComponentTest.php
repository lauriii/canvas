<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Kernel\Config;

use Drupal\Core\Entity\EntityListBuilderInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\Plugin\Field\FieldWidget\OptionsSelectWidget;
use Drupal\Core\Field\Plugin\Field\FieldWidget\StringTextfieldWidget;
use Drupal\Core\Field\Plugin\Field\FieldWidget\UriWidget;
use Drupal\Core\Theme\ComponentPluginManager as CoreComponentPluginManager;
use Drupal\experience_builder\ComponentIncompatibilityReasonRepository;
use Drupal\experience_builder\Entity\ComponentInterface;
use Drupal\experience_builder\Entity\JavaScriptComponent;
use Drupal\experience_builder\Plugin\ComponentPluginManager;
use Drupal\experience_builder\Entity\Component;
use Drupal\experience_builder\Plugin\ExperienceBuilder\ComponentSource\JsComponent;
use Drupal\experience_builder\Plugin\ExperienceBuilder\ComponentSource\SingleDirectoryComponent;
use Drupal\experience_builder\PropSource\StaticPropSource;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\experience_builder\Traits\ContribStrictConfigSchemaTestTrait;
use Drupal\Tests\experience_builder\Traits\GenerateComponentConfigTrait;
use Symfony\Component\Yaml\Yaml;

class ComponentTest extends KernelTestBase {

  use ContribStrictConfigSchemaTestTrait;
  use GenerateComponentConfigTrait;

  const MISSING_COMPONENT_ID = 'experience_builder:missing-component';
  const MISSING_CONFIG_ENTITY_ID = 'sdc.experience_builder.missing-component';
  const LABEL = 'Test Component';

  protected CoreComponentPluginManager $componentPluginManager;
  protected ComponentIncompatibilityReasonRepository $repository;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'experience_builder',
    'sdc',
    'sdc_test',
    // XB's dependencies (modules providing field types + widgets).
    'datetime',
    'file',
    'image',
    'options',
    'path',
    'link',
    'system',
    'user',
    'text',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->componentPluginManager = $this->container->get(ComponentPluginManager::class);
    $this->repository = $this->container->get(ComponentIncompatibilityReasonRepository::class);
  }

  protected function midTestSetUp(): void {
    // The Standard install profile's "image" media type must be installed when
    // the media_library module gets installed.
    // @see core/profiles/standard/config/optional/media.type.image.yml
    $this->enableModules(['field', 'file', 'image', 'media']);
    $this->generateComponentConfig();
    $this->setInstallProfile('standard');
    $this->container->get('config.installer')->installOptionalConfig();

    $modules = [
      'media_library',
      'views',
      'user',
      'filter',
    ];
    $this->enableModules($modules);
    $this->generateComponentConfig();
    // @see \Drupal\Core\Field\Plugin\Field\FieldType\EntityReferenceItem::generateSampleValue()
    $this->installEntitySchema('media');

    // @see \Drupal\media_library\Plugin\Field\FieldWidget\MediaLibraryWidget
    $this->installEntitySchema('user');

    // @see core/profiles/standard/config/optional/media.type.image.yml
    $this->installConfig(['media']);

    // A sample value is generated during the test, which needs this table.
    $this->installSchema('file', ['file_usage']);

    // @see \Drupal\media_library\MediaLibraryEditorOpener::__construct()
    $this->installEntitySchema('filter_format');
  }

  public static function providerComponentCreation(): array {
    return [
      'sdc' => [
        'component_config_entity_id' => 'sdc.sdc_test.my-cta',
        'source' => SingleDirectoryComponent::SOURCE_PLUGIN_ID,
        'provider' => 'sdc_test',
        'source_internal_id' => 'sdc_test:my-cta',
        'expected_config_dependencies' => [
          'module' => [
            // Reason: field type + widget.
            'options',
            // Reason: SDC.
            'sdc_test',
          ],
        ],
      ],
      'js' => [
        'component_config_entity_id' => 'js.my-cta',
        'source' => JsComponent::SOURCE_PLUGIN_ID,
        'provider' => NULL,
        'source_internal_id' => 'my-cta',
        'expected_config_dependencies' => [
          'config' => [
            'experience_builder.js_component.my-cta',
          ],
          'module' => [
            // Reason: field type + widget.
            'options',
          ],
        ],
      ],
    ];
  }

  /**
   * @dataProvider providerComponentCreation
   * @todo Remove in https://www.drupal.org/i/3518838
   */
  public function testComponentCreation(string $component_config_entity_id, string $source, ?string $provider, string $source_internal_id, array $expected_config_dependencies): void {
    if ($source === JsComponent::SOURCE_PLUGIN_ID) {
      $this->assertEmpty(JavaScriptComponent::loadMultiple());

      // Create a "code component" that has the same explicit inputs as the
      // `sdc_test:my-cta`.
      $sdc_yaml = Yaml::parseFile($this->root . '/core/modules/system/tests/modules/sdc_test/components/my-cta/my-cta.component.yml');
      $props = array_diff_key(
        $sdc_yaml['props']['properties'],
        // SDC has special infrastructure for a prop named "attributes".
        array_flip(['attributes']),
      );
      // The `sdc_test:my-cta` SDC does not actually meet the requirements.
      $props['href']['examples'][] = 'https://example.com';
      $props['target']['examples'][] = '_blank';

      $js_component = JavaScriptComponent::create([
        'machineName' => 'my-cta',
        'name' => $this->getRandomGenerator()->sentences(5),
        'status' => FALSE,
        'props' => $props,
        'required' => $sdc_yaml['props']['required'],
        'js' => ['original' => '', 'compiled' => ''],
        'css' => ['original' => '', 'compiled' => ''],
      ]);
      $js_component->save();
    }

    $this->assertEmpty(Component::loadMultiple());

    $module_component = Component::create([
      'id' => $component_config_entity_id,
      'label' => self::LABEL,
      'category' => self::LABEL,
      'source' => $source,
      'provider' => $provider,
      'settings' => [
        'plugin_id' => $source_internal_id,
        'prop_field_definitions' => [
          'text' => [
            // @see \Drupal\Core\Field\Plugin\Field\FieldType\StringItem
            'field_type' => 'string',
            // @see \Drupal\Core\Field\Plugin\Field\FieldWidget\StringTextfieldWidget
            'field_widget' => 'string_textfield',
            'default_value' => ['value' => 'Hello, world!'],
            'expression' => 'ℹ︎string␟value',
          ],
          'href' => [
            // @see \Drupal\Core\Field\Plugin\Field\FieldType\UriItem
            'field_type' => 'uri',
            // @see \Drupal\Core\Field\Plugin\Field\FieldWidget\UriWidget
            'field_widget' => 'uri',
            'default_value' => ['value' => 'https://drupal.org'],
            'expression' => 'ℹ︎uri␟value',
          ],
          'target' => [
            // @see \Drupal\options\Plugin\Field\FieldType\ListStringItem
            'field_type' => 'list_string',
            'field_storage_settings' => [
              'allowed_values' => [
                ['value' => 'foo', 'label' => 'foo'],
                ['value' => 'bar', 'label' => 'bar'],
              ],
            ],
            // @see \Drupal\Core\Field\Plugin\Field\FieldWidget\OptionsSelectWidget
            'field_widget' => 'options_select',
            'default_value' => NULL,
            'expression' => 'ℹ︎list_string␟value',
          ],
        ],
      ],
    ]);
    $module_component->save();

    $this->assertNotEmpty(Component::loadMultiple());
    $this->assertSame($expected_config_dependencies, $module_component->getDependencies());
    $this->assertSame($component_config_entity_id, $module_component->id());
    $this->assertSame($source_internal_id, $module_component->getComponentSource()->getConfiguration()['plugin_id']);

    // Use reflection to test the private ::getDefaultStaticPropSource() method.
    $source = $module_component->getComponentSource();
    $private_method = new \ReflectionMethod($source, 'getDefaultStaticPropSource');
    $private_method->setAccessible(TRUE);

    $text_default_static_prop_source = $private_method->invoke($source, 'text');
    $this->assertInstanceOf(StaticPropSource::class, $text_default_static_prop_source);
    $this->assertSame('static:field_item:string', $text_default_static_prop_source->getSourceType());
    $this->assertInstanceOf(StringTextfieldWidget::class, $text_default_static_prop_source->getWidget('text', $this->randomString(), NULL));
    $this->assertSame('{"sourceType":"static:field_item:string","value":"Hello, world!","expression":"ℹ︎string␟value"}', (string) $text_default_static_prop_source);

    $href_default_static_prop_source = $private_method->invoke($source, 'href');
    $this->assertInstanceOf(StaticPropSource::class, $href_default_static_prop_source);
    $this->assertSame('static:field_item:uri', $href_default_static_prop_source->getSourceType());
    $this->assertInstanceOf(UriWidget::class, $href_default_static_prop_source->getWidget('href', $this->randomString(), NULL));
    $this->assertSame('{"sourceType":"static:field_item:uri","value":"https:\/\/drupal.org","expression":"ℹ︎uri␟value"}', (string) $href_default_static_prop_source);

    $target_default_static_prop_source = $private_method->invoke($source, 'target');
    $this->assertInstanceOf(StaticPropSource::class, $target_default_static_prop_source);
    $this->assertSame('static:field_item:list_string', $target_default_static_prop_source->getSourceType());
    $this->assertInstanceOf(OptionsSelectWidget::class, $target_default_static_prop_source->getWidget('target', $this->randomString(), NULL));
    $this->assertSame('{"sourceType":"static:field_item:list_string","value":null,"expression":"ℹ︎list_string␟value","sourceTypeSettings":{"storage":{"allowed_values":[{"value":"foo","label":"foo"},{"value":"bar","label":"bar"}]}}}', (string) $target_default_static_prop_source);
  }

  /**
   * @see media_library_storage_prop_shape_alter()
   * @see \Drupal\Tests\experience_builder\Kernel\MediaLibraryHookStoragePropAlterTest
   */
  public function testComponentAutoUpdate(): void {
    $this->assertEmpty(Component::loadMultiple());
    $this->componentPluginManager->getDefinitions();
    $initial_components = Component::loadMultiple();
    $this->assertNotEmpty($initial_components);
    $this->assertArrayHasKey('sdc.experience_builder.image', $initial_components);
    $this->assertSame('image', $initial_components['sdc.experience_builder.image']->getSettings()['prop_field_definitions']['image']['field_type']);

    $this->midTestSetUp();
    $updated_component = Component::load('sdc.experience_builder.image');
    assert($updated_component instanceof Component);
    $this->assertSame('entity_reference', $updated_component->getSettings()['prop_field_definitions']['image']['field_type']);
  }

  public function testOperations(): void {
    $list_builder = $this->container->get(EntityTypeManagerInterface::class)->getListBuilder(Component::ENTITY_TYPE_ID);
    \assert($list_builder instanceof EntityListBuilderInterface);
    $this->componentPluginManager->getDefinitions();
    $component = Component::load('sdc.experience_builder.image');
    \assert($component instanceof ComponentInterface);
    $operations = $list_builder->getOperations($component);
    self::assertArrayHasKey('disable', $operations);
    self::assertArrayNotHasKey('enable', $operations);
    self::assertArrayNotHasKey('delete', $operations);

    $component->disable()->save();
    $operations = $list_builder->getOperations($component);
    self::assertArrayNotHasKey('disable', $operations);
    self::assertArrayHasKey('enable', $operations);
    self::assertArrayNotHasKey('delete', $operations);
  }

}
