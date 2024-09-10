<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Kernel;

use Drupal\Core\Field\Plugin\Field\FieldWidget\OptionsSelectWidget;
use Drupal\Core\Field\Plugin\Field\FieldWidget\StringTextfieldWidget;
use Drupal\Core\Field\Plugin\Field\FieldWidget\UriWidget;
use Drupal\Core\State\StateInterface;
use Drupal\Core\Theme\ComponentPluginManager as CoreComponentPluginManager;
use Drupal\experience_builder\Plugin\ComponentPluginManager;
use Drupal\experience_builder\Entity\Component;
use Drupal\experience_builder\PropSource\StaticPropSource;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\experience_builder\Traits\ContribStrictConfigSchemaTestTrait;

class ComponentTest extends KernelTestBase {

  use ContribStrictConfigSchemaTestTrait;

  const MODULE_COMPONENT_ID = 'sdc_test:my-cta';
  const MODULE_CONFIG_ENTITY_ID = 'sdc_test+my-cta';
  const MISSING_COMPONENT_ID = 'experience_builder:missing-component';
  const MISSING_CONFIG_ENTITY_ID = 'experience_builder+missing-component';
  const LABEL = 'Test Component';

  protected CoreComponentPluginManager $componentPluginManager;
  protected StateInterface $state;

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
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->componentPluginManager = $this->container->get(ComponentPluginManager::class);
    $this->state = $this->container->get('state');
  }

  /**
   * {@inheritdoc}
   */
  protected function enableModules(array $modules): void {
    parent::enableModules($modules);
    // Installing a module with SDCs should result in Component config entities
    // being generated. This requires hook_module_preinstall() and subsequently
    // hook_modules_installed() to be invoked, but `::enableModules()` does not
    // do that, for performance reasons. Simulate it.
    // @see \Drupal\KernelTests\KernelTestBase::enableModules()
    // @see \Drupal\Tests\ckeditor5\Kernel\CKEditor5PluginManagerTest::enableModules()

    // 1. Simulate hook_module_preinstall() getting invoked.
    // @see experience_builder_module_preinstall()
    $this->componentPluginManager->clearCachedDefinitions();

    // 2. Simulate experience_builder_modules_installed() getting invoked.
    // @see experience_builder_modules_installed()
    $this->componentPluginManager->getDefinitions();
  }

  protected function midTestSetUp(): void {
    // The Standard install profile's "image" media type must be installed when
    // the media_library module gets installed.
    // @see core/profiles/standard/config/optional/media.type.image.yml
    $this->enableModules(['media']);
    $this->setInstallProfile('standard');
    $this->container->get('config.installer')->installOptionalConfig();

    $modules = [
      'media_library',
      'views',
      'user',
      'filter',
    ];
    $this->enableModules($modules);
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

  public function testMachineNameAndIdConversion(): void {
    // @todo This confusing because in both cases both the input and output are something we call an "ID" but
    //   Then it is supposed to be conversion to/from a "machine name".
    $this->assertSame(self::MODULE_CONFIG_ENTITY_ID, Component::convertMachineNameToId(self::MODULE_COMPONENT_ID));
    $this->assertSame(self::MODULE_COMPONENT_ID, Component::convertIdToMachineName(self::MODULE_CONFIG_ENTITY_ID));
  }

  public function testComponentCreation(): void {
    $this->assertEmpty(Component::loadMultiple());

    $module_component = Component::create([
      'component' => self::MODULE_COMPONENT_ID,
      'id' => self::MODULE_CONFIG_ENTITY_ID,
      'label' => self::LABEL,
      'defaults' => [
        'props' => [
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
    $this->assertSame(['module' => ['options', 'sdc_test']], $module_component->getDependencies());
    $this->assertSame(self::MODULE_COMPONENT_ID, $module_component->getComponentMachineName());
    $this->assertSame(self::MODULE_CONFIG_ENTITY_ID, $module_component->id());

    $text_default_static_prop_source = $module_component->getDefaultStaticPropSource('text');
    $this->assertInstanceOf(StaticPropSource::class, $text_default_static_prop_source);
    $this->assertSame('static:field_item:string', $text_default_static_prop_source->getSourceType());
    $this->assertInstanceOf(StringTextfieldWidget::class, $text_default_static_prop_source->getWidget('text', $this->randomString(), NULL));
    $this->assertSame('{"sourceType":"static:field_item:string","value":"Hello, world!","expression":"ℹ︎string␟value"}', (string) $text_default_static_prop_source);

    $href_default_static_prop_source = $module_component->getDefaultStaticPropSource('href');
    $this->assertInstanceOf(StaticPropSource::class, $href_default_static_prop_source);
    $this->assertSame('static:field_item:uri', $href_default_static_prop_source->getSourceType());
    $this->assertInstanceOf(UriWidget::class, $href_default_static_prop_source->getWidget('href', $this->randomString(), NULL));
    $this->assertSame('{"sourceType":"static:field_item:uri","value":"https:\/\/drupal.org","expression":"ℹ︎uri␟value"}', (string) $href_default_static_prop_source);

    $target_default_static_prop_source = $module_component->getDefaultStaticPropSource('target');
    $this->assertInstanceOf(StaticPropSource::class, $target_default_static_prop_source);
    $this->assertSame('static:field_item:list_string', $target_default_static_prop_source->getSourceType());
    $this->assertInstanceOf(OptionsSelectWidget::class, $target_default_static_prop_source->getWidget('target', $this->randomString(), NULL));
    $this->assertSame('{"sourceType":"static:field_item:list_string","value":null,"expression":"ℹ︎list_string␟value","sourceTypeSettings":{"storage":{"allowed_values":[{"value":"foo","label":"foo"},{"value":"bar","label":"bar"}]}}}', (string) $target_default_static_prop_source);
  }

  /**
   * @param array<string> $modules
   * @param array<string, array{'compatible': bool, 'reason'?: bool}> $sdcs
   *
   * @dataProvider provider
   */
  public function testComponentAutoCreate(array $modules, array $sdcs): void {
    // Initial state: no Component config entities.
    $this->assertEmpty(Component::loadMultiple());

    // Installing a module with SDCs should result in Component config entities being generated.
    $this->enableModules($modules);
    $reasons = $this->state->get(ComponentPluginManager::REASONS_STATE_KEY, []);
    foreach ($sdcs as $plugin_id => $component_entity) {
      $this->assertSame($component_entity['compatible'], Component::load(Component::convertMachineNameToId($plugin_id)) instanceof Component, $plugin_id . ' and modules: ' . implode(', ', $modules));
      $this->assertSame($component_entity['reason'] ?? NULL, isset($reasons[$plugin_id]) ? (string) $reasons[$plugin_id] : NULL, $plugin_id);
    }

    $found_sdcs = array_keys($this->componentPluginManager->getDefinitions());
    sort($found_sdcs);
    $expected_sdcs = array_keys($sdcs);
    sort($expected_sdcs);
    $this->assertSame($expected_sdcs, $found_sdcs);
  }

  public static function provider(): \Generator {

    yield 'initial set of components from experience_builder and sdc_test' => [
      'modules' => [],
      'sdcs' => [
        'experience_builder:obsolete' => [
          'compatible' => FALSE,
          'reason' => 'Component has "obsolete" status',
        ],
        'experience_builder:experimental' => [
          'compatible' => TRUE,
        ],
        'experience_builder:deprecated' => [
          'compatible' => TRUE,
        ],
        'experience_builder:image' => [
          'compatible' => TRUE,
        ],
        'experience_builder:two_column' => [
          'compatible' => TRUE,
        ],
        'experience_builder:one_column' => [
          'compatible' => TRUE,
        ],
        'experience_builder:shoe_tab_group' => [
          'compatible' => TRUE,
        ],
        'experience_builder:video' => [
          'compatible' => FALSE,
          'reason' => 'Experience Builder does not know of a field type/widget to allow populating the <code>src</code> prop, with the shape <code>{"type":"string","format":"uri","pattern":"\\\.(mp4|webm)(\\\?.*)?(#.*)?$"}</code>.',
        ],
        'experience_builder:shoe_tab_panel' => [
          'compatible' => TRUE,
        ],
        'experience_builder:shoe_badge' => [
          'compatible' => TRUE,
        ],
        'experience_builder:shoe_button' => [
          'compatible' => FALSE,
          'reason' => 'Experience Builder does not know of a field type/widget to allow populating the <code>icon</code> prop, with the shape <code>{"type":"object","$ref":"json-schema-definitions://experience_builder.module/shoe-icon"}</code>.',
        ],
        'experience_builder:shoe_icon' => [
          'compatible' => TRUE,
        ],
        'experience_builder:shoe_tab' => [
          'compatible' => TRUE,
        ],
        'experience_builder:heading' => [
          'compatible' => TRUE,
        ],
        'experience_builder:shoe_details' => [
          'compatible' => FALSE,
          'reason' => 'Experience Builder does not know of a field type/widget to allow populating the <code>expand_icon</code> prop, with the shape <code>{"type":"object","$ref":"json-schema-definitions://experience_builder.module/shoe-icon"}</code>.',
        ],
        'experience_builder:my-hero' => [
          'compatible' => TRUE,
        ],
        'experience_builder:my-section' => [
          'compatible' => TRUE,
        ],
        'sdc_test:array-to-object' => [
          'compatible' => FALSE,
          'reason' => 'Experience Builder does not know of a field type/widget to allow populating the <code>testProp</code> prop, with the shape <code>{"type":"object"}</code>.',
        ],
        'sdc_test:my-button' => [
          'compatible' => TRUE,
        ],
        'sdc_test:my-cta' => [
          'compatible' => TRUE,
        ],
        'sdc_test:no-props' => [
          'compatible' => TRUE,
        ],
        'sdc_test:my-banner' => [
          'compatible' => TRUE,
        ],
      ],
    ];

    yield 'installing xb_test_sdc creates props-no-slots and props-slots components' => [
      'modules' => ['xb_test_sdc'],
      'sdcs' => [
        'experience_builder:obsolete' => [
          'compatible' => FALSE,
          'reason' => 'Component has "obsolete" status',
        ],
        'experience_builder:experimental' => [
          'compatible' => TRUE,
        ],
        'experience_builder:deprecated' => [
          'compatible' => TRUE,
        ],
        'experience_builder:image' => [
          'compatible' => TRUE,
        ],
        'experience_builder:two_column' => [
          'compatible' => TRUE,
        ],
        'experience_builder:one_column' => [
          'compatible' => TRUE,
        ],
        'experience_builder:shoe_tab_group' => [
          'compatible' => TRUE,
        ],
        'experience_builder:video' => [
          'compatible' => FALSE,
          'reason' => 'Experience Builder does not know of a field type/widget to allow populating the <code>src</code> prop, with the shape <code>{"type":"string","format":"uri","pattern":"\\\.(mp4|webm)(\\\?.*)?(#.*)?$"}</code>.',
        ],
        'experience_builder:shoe_tab_panel' => [
          'compatible' => TRUE,
        ],
        'experience_builder:shoe_badge' => [
          'compatible' => TRUE,
        ],
        'experience_builder:shoe_button' => [
          'compatible' => FALSE,
          'reason' => 'Experience Builder does not know of a field type/widget to allow populating the <code>icon</code> prop, with the shape <code>{"type":"object","$ref":"json-schema-definitions://experience_builder.module/shoe-icon"}</code>.',
        ],
        'experience_builder:shoe_icon' => [
          'compatible' => TRUE,
        ],
        'experience_builder:shoe_tab' => [
          'compatible' => TRUE,
        ],
        'experience_builder:heading' => [
          'compatible' => TRUE,
        ],
        'experience_builder:shoe_details' => [
          'compatible' => FALSE,
          'reason' => 'Experience Builder does not know of a field type/widget to allow populating the <code>expand_icon</code> prop, with the shape <code>{"type":"object","$ref":"json-schema-definitions://experience_builder.module/shoe-icon"}</code>.',
        ],
        'experience_builder:my-hero' => [
          'compatible' => TRUE,
        ],
        'experience_builder:my-section' => [
          'compatible' => TRUE,
        ],
        'sdc_test:array-to-object' => [
          'compatible' => FALSE,
          'reason' => 'Experience Builder does not know of a field type/widget to allow populating the <code>testProp</code> prop, with the shape <code>{"type":"object"}</code>.',
        ],
        'sdc_test:my-button' => [
          'compatible' => TRUE,
        ],
        'sdc_test:my-cta' => [
          'compatible' => TRUE,
        ],
        'sdc_test:no-props' => [
          'compatible' => TRUE,
        ],
        'sdc_test:my-banner' => [
          'compatible' => TRUE,
        ],
        'xb_test_sdc:props-no-slots' => [
          'compatible' => TRUE,
        ],
        'xb_test_sdc:props-slots' => [
          'compatible' => TRUE,
        ],
        'xb_test_sdc:props-no-title' => [
          'compatible' => FALSE,
          'reason' => 'Prop "heading" must have title',
        ],
        'xb_test_sdc:props-no-examples' => [
          'compatible' => FALSE,
          'reason' => 'Prop "heading" is required, but does not have example value',
        ],
      ],
    ];
    yield 'installing sdc_test_all_props creates sdc_test_all_props:all-props creates component' => [
      'modules' => ['xb_test_sdc', 'sdc_test_all_props'],
      'sdcs' => [
        'experience_builder:obsolete' => [
          'compatible' => FALSE,
          'reason' => 'Component has "obsolete" status',
        ],
        'experience_builder:experimental' => [
          'compatible' => TRUE,
        ],
        'experience_builder:deprecated' => [
          'compatible' => TRUE,
        ],
        'experience_builder:image' => [
          'compatible' => TRUE,
        ],
        'experience_builder:two_column' => [
          'compatible' => TRUE,
        ],
        'experience_builder:one_column' => [
          'compatible' => TRUE,
        ],
        'experience_builder:shoe_tab_group' => [
          'compatible' => TRUE,
        ],
        'experience_builder:video' => [
          'compatible' => FALSE,
          'reason' => 'Experience Builder does not know of a field type/widget to allow populating the <code>src</code> prop, with the shape <code>{"type":"string","format":"uri","pattern":"\\\.(mp4|webm)(\\\?.*)?(#.*)?$"}</code>.',
        ],
        'experience_builder:shoe_tab_panel' => [
          'compatible' => TRUE,
        ],
        'experience_builder:shoe_badge' => [
          'compatible' => TRUE,
        ],
        'experience_builder:shoe_button' => [
          'compatible' => FALSE,
          'reason' => 'Experience Builder does not know of a field type/widget to allow populating the <code>icon</code> prop, with the shape <code>{"type":"object","$ref":"json-schema-definitions://experience_builder.module/shoe-icon"}</code>.',
        ],
        'experience_builder:shoe_icon' => [
          'compatible' => TRUE,
        ],
        'experience_builder:shoe_tab' => [
          'compatible' => TRUE,
        ],
        'experience_builder:heading' => [
          'compatible' => TRUE,
        ],
        'experience_builder:shoe_details' => [
          'compatible' => FALSE,
          'reason' => 'Experience Builder does not know of a field type/widget to allow populating the <code>expand_icon</code> prop, with the shape <code>{"type":"object","$ref":"json-schema-definitions://experience_builder.module/shoe-icon"}</code>.',
        ],
        'experience_builder:my-hero' => [
          'compatible' => TRUE,
        ],
        'experience_builder:my-section' => [
          'compatible' => TRUE,
        ],
        'sdc_test:array-to-object' => [
          'compatible' => FALSE,
          'reason' => 'Experience Builder does not know of a field type/widget to allow populating the <code>testProp</code> prop, with the shape <code>{"type":"object"}</code>.',
        ],
        'sdc_test:my-button' => [
          'compatible' => TRUE,
        ],
        'sdc_test:my-cta' => [
          'compatible' => TRUE,
        ],
        'sdc_test:no-props' => [
          'compatible' => TRUE,
        ],
        'sdc_test:my-banner' => [
          'compatible' => TRUE,
        ],
        'sdc_test_all_props:all-props' => [
          'compatible' => TRUE,
        ],
        'xb_test_sdc:props-no-slots' => [
          'compatible' => TRUE,
        ],
        'xb_test_sdc:props-slots' => [
          'compatible' => TRUE,
        ],
        'xb_test_sdc:props-no-title' => [
          'compatible' => FALSE,
          'reason' => 'Prop "heading" must have title',
        ],
        'xb_test_sdc:props-no-examples' => [
          'compatible' => FALSE,
          'reason' => 'Prop "heading" is required, but does not have example value',
        ],
      ],
    ];

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
    $this->assertArrayHasKey('experience_builder+image', $initial_components);
    $this->assertSame('image', $initial_components['experience_builder+image']->get('defaults')['props']['image']['field_type']);

    $this->midTestSetUp();
    $updated_component = Component::load('experience_builder+image');
    assert($updated_component instanceof Component);
    $this->assertSame('entity_reference', $updated_component->get('defaults')['props']['image']['field_type']);
  }

  public function testObsoleteStatusHandling(): void {
    $this->componentPluginManager->getDefinitions();
    $this->assertNull(Component::load(Component::convertMachineNameToId('experience_builder:obsolete')));
    $component = Component::createFromComponentPlugin($this->componentPluginManager->find('experience_builder:obsolete'));
    $this->assertFalse($component->status());
    $component->enable();
    $this->assertTrue($component->status());
    $component->save();

    // Trigger component update that will disable 'obsolete' component.
    $this->componentPluginManager->clearCachedDefinitions();
    $this->componentPluginManager->getDefinitions();

    $component = Component::load($component->id());
    assert($component instanceof Component);
    $this->assertFalse($component->status());
  }

}
