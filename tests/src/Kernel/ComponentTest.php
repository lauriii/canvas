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
use Drupal\experience_builder\Plugin\ExperienceBuilder\ComponentSource\SingleDirectoryComponent;
use Drupal\experience_builder\PropSource\StaticPropSource;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\experience_builder\Traits\ContribStrictConfigSchemaTestTrait;
use Drupal\Tests\experience_builder\Traits\GenerateComponentConfigTrait;
use Drupal\user\Plugin\Block\UserLoginBlock;

class ComponentTest extends KernelTestBase {

  use ContribStrictConfigSchemaTestTrait;
  use GenerateComponentConfigTrait;

  const MODULE_COMPONENT_ID = 'sdc_test:my-cta';
  const MODULE_CONFIG_ENTITY_ID = 'sdc.sdc_test.my-cta';
  const MISSING_COMPONENT_ID = 'experience_builder:missing-component';
  const MISSING_CONFIG_ENTITY_ID = 'sdc.experience_builder.missing-component';
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
    'link',
    'system',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->componentPluginManager = $this->container->get(ComponentPluginManager::class);
    $this->state = $this->container->get('state');
  }

  protected function midTestSetUp(): void {
    // The Standard install profile's "image" media type must be installed when
    // the media_library module gets installed.
    // @see core/profiles/standard/config/optional/media.type.image.yml
    $this->enableModules(['media']);
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

  public function testComponentCreation(): void {
    $this->assertEmpty(Component::loadMultiple());

    $module_component = Component::create([
      'id' => self::MODULE_CONFIG_ENTITY_ID,
      'label' => self::LABEL,
      'source' => SingleDirectoryComponent::SOURCE_PLUGIN_ID,
      'settings' => [
        'plugin_id' => self::MODULE_COMPONENT_ID,
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
    $this->assertSame(self::MODULE_COMPONENT_ID, $module_component->getComponentPluginId());
    $this->assertSame(self::MODULE_CONFIG_ENTITY_ID, $module_component->id());
    $this->assertSame(self::MODULE_COMPONENT_ID, $module_component->getComponentSource()->getConfiguration()['plugin_id']);

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
   * @param array<string, array{'compatible': bool, 'reason'?: bool}> $components
   * @param array<string> $classes
   *
   * @dataProvider provider
   */
  public function testComponentAutoCreate(array $modules, array $components, array $classes): void {
    // Initial state: no Component config entities.
    $this->assertEmpty(Component::loadMultiple());

    $this->enableModules($modules);
    if (in_array('block', $modules)) {
      // system.module provides some default menus in config, installing that
      // allows us to test menu block derivatives here.
      $this->installConfig(['system']);
    }
    // Installing a module with SDCs should result in Component config entities
    // being generated, but in kernel tests we have to explicitly trigger the
    // hooks that would normally do this.
    $this->generateComponentConfig();

    $reasons = $this->state->get(ComponentPluginManager::REASONS_STATE_KEY, []);
    $expected_plugins = [];
    foreach ($components as $component_id => $component_entity) {
      [$type, $plugin_id] = explode('.', $component_id, 2);
      $plugin_id = str_replace('.', ':', $plugin_id);
      $expected_plugins[$type][] = $plugin_id;
      $this->assertSame($component_entity['compatible'], Component::load($component_id) instanceof Component, $plugin_id . ' and modules: ' . implode(', ', $modules));
      $this->assertSame($component_entity['reason'] ?? NULL, isset($reasons[$plugin_id]) ? (string) $reasons[$plugin_id] : NULL, $plugin_id);
    }

    $this->assertEqualsCanonicalizing($expected_plugins['sdc'], array_keys($this->componentPluginManager->getDefinitions()));
    if (in_array('block', $modules)) {
      $this->assertEqualsCanonicalizing($expected_plugins['block'], array_keys($this->container->get('plugin.manager.block')->getDefinitions()));
    }

    $this->assertEqualsCanonicalizing($classes, Component::getClasses(array_keys($components)));
  }

  public static function provider(): \Generator {
    $defaults = [
      'sdc.experience_builder.obsolete' => [
        'compatible' => FALSE,
        'reason' => 'Component has "obsolete" status',
      ],
      'sdc.experience_builder.druplicon' => [
        'compatible' => TRUE,
      ],
      'sdc.experience_builder.experimental' => [
        'compatible' => TRUE,
      ],
      'sdc.experience_builder.deprecated' => [
        'compatible' => TRUE,
      ],
      'sdc.experience_builder.image' => [
        'compatible' => TRUE,
      ],
      'sdc.experience_builder.two_column' => [
        'compatible' => TRUE,
      ],
      'sdc.experience_builder.one_column' => [
        'compatible' => TRUE,
      ],
      'sdc.experience_builder.shoe_tab_group' => [
        'compatible' => TRUE,
      ],
      'sdc.experience_builder.video' => [
        'compatible' => FALSE,
        'reason' => 'Experience Builder does not know of a field type/widget to allow populating the <code>src</code> prop, with the shape <code>{"type":"string","format":"uri","pattern":"\\\.(mp4|webm)(\\\?.*)?(#.*)?$"}</code>.',
      ],
      'sdc.experience_builder.shoe_tab_panel' => [
        'compatible' => TRUE,
      ],
      'sdc.experience_builder.shoe_badge' => [
        'compatible' => TRUE,
      ],
      'sdc.experience_builder.shoe_button' => [
        'compatible' => FALSE,
        'reason' => 'Experience Builder does not know of a field type/widget to allow populating the <code>icon</code> prop, with the shape <code>{"type":"object","$ref":"json-schema-definitions://experience_builder.module/shoe-icon"}</code>.',
      ],
      'sdc.experience_builder.shoe_icon' => [
        'compatible' => TRUE,
      ],
      'sdc.experience_builder.shoe_tab' => [
        'compatible' => TRUE,
      ],
      'sdc.experience_builder.heading' => [
        'compatible' => TRUE,
      ],
      'sdc.experience_builder.shoe_details' => [
        'compatible' => FALSE,
        'reason' => 'Experience Builder does not know of a field type/widget to allow populating the <code>expand_icon</code> prop, with the shape <code>{"type":"object","$ref":"json-schema-definitions://experience_builder.module/shoe-icon"}</code>.',
      ],
      'sdc.experience_builder.my-hero' => [
        'compatible' => TRUE,
      ],
      'sdc.experience_builder.my-section' => [
        'compatible' => TRUE,
      ],
      'sdc.sdc_test.array-to-object' => [
        'compatible' => FALSE,
        'reason' => 'Experience Builder does not know of a field type/widget to allow populating the <code>testProp</code> prop, with the shape <code>{"type":"object"}</code>.',
      ],
      'sdc.sdc_test.my-button' => [
        'compatible' => TRUE,
      ],
      'sdc.sdc_test.my-cta' => [
        'compatible' => TRUE,
      ],
      'sdc.sdc_test.no-props' => [
        'compatible' => TRUE,
      ],
      'sdc.sdc_test.my-banner' => [
        'compatible' => TRUE,
      ],
    ];

    yield 'initial set of components from experience_builder and sdc_test' => [
      'modules' => [],
      'components' => $defaults,
      'interfaces' => [
        'Drupal\Core\Plugin\Component',
      ],
    ];

    yield 'installing xb_test_sdc creates props-no-slots and props-slots components' => [
      'modules' => ['xb_test_sdc'],
      'components' => $defaults + [
        'sdc.xb_test_sdc.props-no-slots' => [
          'compatible' => TRUE,
        ],
        'sdc.xb_test_sdc.props-slots' => [
          'compatible' => TRUE,
        ],
        'sdc.xb_test_sdc.props-no-title' => [
          'compatible' => FALSE,
          'reason' => 'Prop "heading" must have title',
        ],
        'sdc.xb_test_sdc.props-no-examples' => [
          'compatible' => FALSE,
          'reason' => 'Prop "heading" is required, but does not have example value',
        ],
      ],
      'interfaces' => [
        'Drupal\Core\Plugin\Component',
      ],
    ];

    yield 'installing sdc_test_all_props creates sdc_test_all_props:all-props creates component' => [
      'modules' => ['xb_test_sdc', 'sdc_test_all_props'],
      'components' => $defaults + [
        'sdc.sdc_test_all_props.all-props' => [
          'compatible' => TRUE,
        ],
        'sdc.xb_test_sdc.props-no-slots' => [
          'compatible' => TRUE,
        ],
        'sdc.xb_test_sdc.props-slots' => [
          'compatible' => TRUE,
        ],
        'sdc.xb_test_sdc.props-no-title' => [
          'compatible' => FALSE,
          'reason' => 'Prop "heading" must have title',
        ],
        'sdc.xb_test_sdc.props-no-examples' => [
          'compatible' => FALSE,
          'reason' => 'Prop "heading" is required, but does not have example value',
        ],
      ],
      'interfaces' => [
        'Drupal\Core\Plugin\Component',
      ],
    ];

    [$version] = explode('.', \Drupal::VERSION);
    yield 'installing block creates block components including menus' => [
      // `system` and `user` are the two only required core modules.
      'modules' => ['system', 'user', 'block'],
      'components' => $defaults + [
        'block.broken' => [
          'compatible' => FALSE,
        ],
        'block.local_actions_block' => [
          'compatible' => $version > 10,
        ],
        'block.local_tasks_block' => [
          'compatible' => TRUE,
        ],
        'block.page_title_block' => [
          'compatible' => TRUE,
        ],
        'block.system_branding_block' => [
          'compatible' => TRUE,
        ],
        'block.system_breadcrumb_block' => [
          'compatible' => TRUE,
        ],
        'block.system_main_block' => [
          'compatible' => TRUE,
        ],
        'block.system_messages_block' => [
          'compatible' => TRUE,
        ],
        'block.system_powered_by_block' => [
          'compatible' => $version > 10,
        ],
        'block.system_menu_block.account' => [
          'compatible' => TRUE,
        ],
        'block.system_menu_block.admin' => [
          'compatible' => TRUE,
        ],
        'block.system_menu_block.footer' => [
          'compatible' => TRUE,
        ],
        'block.system_menu_block.main' => [
          'compatible' => TRUE,
        ],
        'block.system_menu_block.tools' => [
          'compatible' => TRUE,
        ],
        'block.user_login_block' => [
          'compatible' => TRUE,
        ],
      ],
      'classes' => array_filter([
        'Drupal\Core\Plugin\Component',
        'Drupal\Core\Block\Plugin\Block\PageTitleBlock',
        $version > 10 ? 'Drupal\Core\Menu\Plugin\Block\LocalActionsBlock' : '',
        'Drupal\Core\Menu\Plugin\Block\LocalTasksBlock',
        'Drupal\system\Plugin\Block\SystemBrandingBlock',
        'Drupal\system\Plugin\Block\SystemBreadcrumbBlock',
        'Drupal\system\Plugin\Block\SystemMainBlock',
        'Drupal\system\Plugin\Block\SystemMessagesBlock',
        $version > 10 ? 'Drupal\system\Plugin\Block\SystemPoweredByBlock' : '',
        'Drupal\system\Plugin\Block\SystemMenuBlock',
        UserLoginBlock::class,
      ]),
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
    $this->assertArrayHasKey('sdc.experience_builder.image', $initial_components);
    $this->assertSame('image', $initial_components['sdc.experience_builder.image']->get('settings')['props']['image']['field_type']);

    $this->midTestSetUp();
    $updated_component = Component::load('sdc.experience_builder.image');
    assert($updated_component instanceof Component);
    $this->assertSame('entity_reference', $updated_component->get('settings')['props']['image']['field_type']);
  }

  public function testObsoleteStatusHandling(): void {
    $this->componentPluginManager->getDefinitions();
    $id = 'sdc.experience_builder.obsolete';
    $this->assertNull(Component::load($id));
    $component = SingleDirectoryComponent::createConfigEntity($this->componentPluginManager->find('experience_builder:obsolete'));
    $this->assertSame($id, $component->id());
    $this->assertFalse($component->status());
    $component->enable();
    $this->assertTrue($component->status());
    $component->save();

    // Trigger component update that will disable 'obsolete' component.
    $this->generateComponentConfig();

    $component = Component::load($component->id());
    assert($component instanceof Component);
    $this->assertFalse($component->status());
  }

}
