<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Kernel\Config;

use Drupal\Core\Config\Schema\SchemaIncompleteException;
use Drupal\Core\Theme\ComponentPluginManager as CoreComponentPluginManager;
use Drupal\experience_builder\Entity\Component;
use Drupal\experience_builder\Entity\JavaScriptComponent;
use Drupal\experience_builder\Entity\VersionedConfigEntityBase;
use Drupal\experience_builder\Entity\VersionedConfigEntityInterface;
use Drupal\experience_builder\Plugin\ComponentPluginManager;
use Drupal\experience_builder\Plugin\ExperienceBuilder\ComponentSource\BlockComponent;
use Drupal\experience_builder\Plugin\ExperienceBuilder\ComponentSource\JsComponent;
use Drupal\experience_builder\Plugin\ExperienceBuilder\ComponentSource\SingleDirectoryComponent;
use Drupal\Tests\experience_builder\Kernel\Traits\CiModulePathTrait;
use Drupal\Tests\experience_builder\Traits\BetterConfigDependencyManagerTrait;
use Drupal\Tests\experience_builder\Traits\ContribStrictConfigSchemaTestTrait;
use Drupal\Tests\experience_builder\Traits\GenerateComponentConfigTrait;
use Symfony\Component\Yaml\Yaml;

/**
 * Tests validation of component entities.
 *
 * @group experience_builder
 * @group #slow
 */
class ComponentValidationTest extends BetterConfigEntityValidationTestBase {

  use BetterConfigDependencyManagerTrait;
  use ContribStrictConfigSchemaTestTrait;
  use GenerateComponentConfigTrait;
  use CiModulePathTrait;

  protected CoreComponentPluginManager $componentPluginManager;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'experience_builder',
    'sdc',
    'xb_test_sdc',
    // XB's dependencies (modules providing field types + widgets).
    'datetime',
    'file',
    'image',
    'options',
    'path',
    'link',
    'field',
    'media',
    'media_library',
    'views',
    'user',
    'filter',
  ];

  /**
   * {@inheritdoc}
   */
  protected static array $propertiesWithOptionalValues = [
    'provider',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('media');
    $this->installEntitySchema('user');
    $this->setInstallProfile('standard');
    $this->installConfig(['media']);
    $this->installSchema('file', ['file_usage']);
    $this->installEntitySchema('filter_format');

    $this->entity = Component::create([
      'id' => 'sdc.xb_test_sdc.my-cta',
      'category' => 'Test',
      'source' => SingleDirectoryComponent::SOURCE_PLUGIN_ID,
      'source_local_id' => 'xb_test_sdc:my-cta',
      'active_version' => 'db8fd1116b3fa3dc',
      'versioned_properties' => [
        VersionedConfigEntityBase::ACTIVE_VERSION => [
          'settings' => [
            'prop_field_definitions' => [
              'text' => [
                // @see \Drupal\Core\Field\Plugin\Field\FieldType\StringItem
                'field_type' => 'string',
                // @see \Drupal\Core\Field\Plugin\Field\FieldWidget\StringTextfieldWidget
                'field_widget' => 'string_textfield',
                'default_value' => [0 => ['value' => 'Hello, world!']],
                'expression' => 'ℹ︎string␟value',
              ],
              'href' => [
                // @see \Drupal\Core\Field\Plugin\Field\FieldType\UriItem
                'field_type' => 'uri',
                // @see \Drupal\Core\Field\Plugin\Field\FieldWidget\UriWidget
                'field_widget' => 'uri',
                'default_value' => [0 => ['value' => 'https://drupal.org']],
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
              // @todo This will start failing validation in https://www.drupal.org/i/3525759.
              'image' => [
                'field_type' => 'image',
                'field_storage_settings' => [
                  'target_type' => 'media',
                ],
                'field_instance_settings' => [
                  'handler' => 'default:media',
                  'handler_settings' => [
                    'target_bundles' => [
                      'image' => 'image',
                    ],
                  ],
                ],
                'field_widget' => 'media_library_widget',
                'default_value' => [],
                'expression' => 'ℹ︎image␟{src↝entity␜␜entity:file␝uri␞␟url,alt↠alt,width↠width,height↠height}',
              ],
            ],
          ],
        ],
      ],
      'label' => 'Test',
    ]);
    $this->entity->save();
    $this->componentPluginManager = $this->container->get(ComponentPluginManager::class);
  }

  /**
   * {@inheritdoc}
   */
  public function testEntityIsValid(): void {
    parent::testEntityIsValid();

    // Beyond validity, validate config dependencies are computed correctly.
    $this->assertSame(
      [
        'module' => [
          'image',
          'media_library',
          'options',
          'xb_test_sdc',
        ],
      ],
      $this->entity->getDependencies()
    );
    $this->assertSame([
      'module' => [
        'image',
        'media_library',
        'options',
        'xb_test_sdc',
        'experience_builder',
      ],
    ], $this->getAllDependencies($this->entity));
  }

  /**
   * @covers `type: experience_builder.component_source_settings.*`
   * @covers `type: experience_builder.generated_field_explicit_input_ux`
   * @covers `type: experience_builder.component_source_settings.sdc`
   * @covers `type: experience_builder.component_source_settings.js`
   * @covers `type: experience_builder.component_source_settings.block`
   *
   * - `experience_builder.generated_field_explicit_input_ux` extends the
   * fallback `experience_builder.component_source_settings.*`
   * - The "sdc" and "js" ones both extend
   *   `experience_builder.component_source_settings.*`
   * - The "block" one extends the fallback one.
   *
   * This test method is aimed to test the ComponentSource-specific settings
   */
  public function testComponentSourceSpecificSettings(): void {
    // @see \Drupal\experience_builder\Plugin\ExperienceBuilder\ComponentSource\SingleDirectoryComponent
    assert($this->entity instanceof Component);
    $invalid_settings_due_to_missing_prop_field_definition = $this->entity->getSettings();
    unset($invalid_settings_due_to_missing_prop_field_definition['prop_field_definitions']['target']);
    $this->entity->setSettings($invalid_settings_due_to_missing_prop_field_definition);
    $this->assertValidationErrors([
      'versioned_properties' => 'The version db8fd1116b3fa3dc does not match the hash of the settings for this version, expected 0675f5f03d6059a4.',
      \sprintf('versioned_properties.%s.settings.prop_field_definitions', VersionedConfigEntityInterface::ACTIVE_VERSION) => 'Configuration for the SDC prop "<em class="placeholder">Target</em>" (<em class="placeholder">target</em>) is missing.',
    ]);

    // @see \Drupal\experience_builder\Plugin\ExperienceBuilder\ComponentSource\JsComponent
    // Create a "code component" that has the same explicit inputs as the
    // `xb_test_sdc:my-cta`.
    $sdc_yaml = Yaml::parseFile($this->root . self::getCiModulePath() . '/tests/modules/xb_test_sdc/components/my-cta/my-cta.component.yml');
    $props = array_diff_key(
      $sdc_yaml['props']['properties'],
      // SDC has special infrastructure for a prop named "attributes".
      array_flip(['attributes']),
    );
    // The `xb_test_sdc:my-cta` SDC does not actually meet the requirements.
    $props['href']['examples'][] = 'https://example.com';
    $props['target']['examples'][] = '_blank';
    JavaScriptComponent::create([
      'machineName' => 'my-cta',
      'name' => $this->getRandomGenerator()->sentences(5),
      'status' => FALSE,
      'props' => $props,
      'required' => $sdc_yaml['props']['required'],
      'js' => ['original' => '', 'compiled' => ''],
      'css' => ['original' => '', 'compiled' => ''],
    ])->save();
    assert($this->entity instanceof Component);
    $this->entity = Component::create([
      'id' => 'js.my-cta',
      'category' => 'Test',
      'source' => JsComponent::SOURCE_PLUGIN_ID,
      'source_local_id' => 'my-cta',
      'active_version' => '34d253d05dc58be2',
      'versioned_properties' => [
        VersionedConfigEntityBase::ACTIVE_VERSION => [
          'settings' => [
            'prop_field_definitions' => array_diff_key(
              $this->entity->getSettings()['prop_field_definitions'],
              // Remove the 'target' key to trigger a validation error.
              // Remove the 'image' because the property is not in the JS component
              // created above.
              // @todo Remove "image" from this in https://www.drupal.org/i/3525759.
              array_flip(['target', 'image']),
            ),
          ],
        ],
      ],
      'label' => 'Test',
    ]);
    $this->assertValidationErrors([
      \sprintf('versioned_properties.%s.settings.prop_field_definitions', VersionedConfigEntityInterface::ACTIVE_VERSION) => "'target' is a required key.",
      // @see \Drupal\experience_builder\Entity\Component::preSave()
      \sprintf('versioned_properties.%s', VersionedConfigEntityInterface::ACTIVE_VERSION) => "'fallback_metadata' is a required key because versioned_properties.%key is active (see config schema type experience_builder.component.versioned.active.*).",
    ]);

    // @see \Drupal\experience_builder\Plugin\ExperienceBuilder\ComponentSource\BlockComponent
    $this->enableModules(['block']);
    $this->installConfig(['system']);
    $defaults = [];

    $this->entity = Component::create([
      'id' => 'block.system_branding_block',
      'category' => 'Test',
      'source' => BlockComponent::SOURCE_PLUGIN_ID,
      'source_local_id' => 'system_branding_block',
      'active_version' => '7a2bdba02d8b7911',
      'versioned_properties' => [
        VersionedConfigEntityBase::ACTIVE_VERSION => [
          'settings' => [
            'default_settings' => [
              // For `type: block_settings`.
              'id' => 'system_branding_block',
              'provider' => 'system',
              'label' => 'Site branding',
              // For `type: block.settings.system_branding_block`, which extends
              // the above.
              // @see \Drupal\system\Plugin\Block\SystemBrandingBlock::defaultConfiguration()
              'use_site_logo' => TRUE,
              'use_site_name' => FALSE,
              // But intentionally omitted `use_site_slogan`, which SHOULD
              // trigger a validation error.
              // 'use_site_slogan' => FALSE,
              // @todo Upstream core bug in `type: block_settings`: `label_display` should be a boolean but has `type: label` — change to FALSE once https://www.drupal.org/i/2544708 is fixed
              'label_display' => '0',
            ] + $defaults,
          ],
        ],
      ],
      'label' => 'Test',
    ]);
    $this->assertValidationErrors([
      \sprintf('versioned_properties.%s.settings.default_settings', VersionedConfigEntityInterface::ACTIVE_VERSION) => "'use_site_slogan' is a required key because source_local_id is system_branding_block (see config schema type block.settings.system_branding_block).",
      // @see \Drupal\experience_builder\Entity\Component::preSave()
      \sprintf('versioned_properties.%s', VersionedConfigEntityInterface::ACTIVE_VERSION) => "'fallback_metadata' is a required key because versioned_properties.%key is active (see config schema type experience_builder.component.versioned.active.*).",
    ]);
  }

  /**
   * Data provider for ::testInvalidMachineNameCharacters().
   *
   * @return array<string, array<int, bool|string>>
   *   The test cases.
   */
  public static function providerInvalidMachineNameCharacters(): array {
    return [
      'INVALID: missing components' => ['sdc.sdc', FALSE],
      'INVALID: space separated' => ['sdc.space separated.space separated', FALSE],
      'INVALID: uppercase letters' => ['sdc.Uppercase_Letters.Uppercase_Letters', FALSE],
      // @todo period separated should be valid for the final identifier.
      'INVALID: period separated' => ['sdc.provider.period.separated', FALSE],
      'INVALID: only underscore separated' => ['sdc.underscore_separated_underscore_separated', FALSE],
      'VALID: dot instead of colon' => ['sdc.provider.component', TRUE],
      'VALID: dash separated' => ['sdc.dash-separated.dash-separated', TRUE],
      'VALID: underscore separated' => ['sdc.underscore_separated.underscore_separated', TRUE],
    ];
  }

  /**
   * Machine name of \Drupal\experience_builder\Entity\Component needs to be joined with +.
   */
  protected function randomMachineName($length = 8): string {
    return 'sdc.' . parent::randomMachineName(intdiv($length, 2)) . '.' . parent::randomMachineName(intdiv($length, 2));
  }

  /**
   * Tests validating a component with a SDC machine name.
   */
  public function testInvalidId(): void {
    $this->entity->set('id', 'invalid:name');
    $this->assertValidationErrors([
      '' => "The 'id' property cannot be changed.",
      'id' => "Expected 'sdc.xb_test_sdc.my-cta', not 'invalid:name'. Format: '&lt;%parent.source&gt;.&lt;%parent.source_local_id&gt;'.",
    ]);
  }

  public function testImmutableProperties(array $valid_values = []): void {
    $valid_values = [
      'id' => 'sdc.sdc_test.no-props',
      'source' => 'test',
      'source_local_id' => 'sdc_test:no-props',
    ];
    $additional_validation_errors = [
      'id' => [
        'id' => "Expected 'sdc.xb_test_sdc.my-cta', not 'sdc.sdc_test.no-props'. Format: '&lt;%parent.source&gt;.&lt;%parent.source_local_id&gt;'.",
      ],
      'source' => [
        'id' => "Expected 'test.xb_test_sdc.my-cta', not 'sdc.xb_test_sdc.my-cta'. Format: '&lt;%parent.source&gt;.&lt;%parent.source_local_id&gt;'.",
        'source' => "The 'test' plugin does not exist.",
        \sprintf('versioned_properties.%s.settings', VersionedConfigEntityInterface::ACTIVE_VERSION) => "'prop_field_definitions' is an unknown key because source is test (see config schema type experience_builder.component_source_settings.*).",
      ],
      'source_local_id' => [
        'id' => "Expected 'sdc.sdc_test.no-props', not 'sdc.xb_test_sdc.my-cta'. Format: '&lt;%parent.source&gt;.&lt;%parent.source_local_id&gt;'.",
        'source_local_id' => "The 'sdc_test:no-props' plugin does not exist.",
      ],
    ];

    // @todo Update parent method to accept a `$additional_validation_errors` parameter in addition to `$valid_values`, and uncomment the next line, remove all lines after it.
    // parent::testImmutableProperties($valid_values);
    $constraints = $this->entity->getEntityType()->getConstraints();
    $this->assertNotEmpty($constraints['ImmutableProperties'], 'All config entities should have at least one immutable ID property.');

    foreach ($constraints['ImmutableProperties'] as $property_name) {
      $original_value = $this->entity->get($property_name);
      $this->entity->set($property_name, $valid_values[$property_name] ?? $this->randomMachineName());
      try {
        $this->assertValidationErrors([
          '' => "The '$property_name' property cannot be changed.",
        ] + ($additional_validation_errors[$property_name] ?? []));
      }
      catch (SchemaIncompleteException) {
        // Safe to ignore, because the validation error for the immutable
        // property *did* occur.
      }
      $this->entity->set($property_name, $original_value);
    }
  }

  /**
   * @dataProvider providerTestCategory
   */
  public function testCategory(?string $category, array $errors): void {
    $this->entity->set('category', $category);
    $this->assertValidationErrors($errors);
  }

  public static function providerTestCategory(): \Generator {
    yield 'valid string' => ['foo', []];
    yield 'empty string' => ['', ['category' => 'This value should not be blank.']];
    yield 'null' => [NULL, ['category' => 'This value should not be null.']];
  }

  public function testStatusWithSdc(): void {
    $component = Component::load('sdc.xb_test_sdc.image-required-without-example');
    $this->assertNull($component);
    $component = SingleDirectoryComponent::createConfigEntity($this->componentPluginManager->find('xb_test_sdc:image-required-without-example'));
    $component->setStatus(FALSE);
    $this->assertEquals(SAVED_NEW, $component->save());
    $component->setStatus(TRUE);
    $this->entity = $component;
    $this->assertValidationErrors([
      'status' => [
        'The component \'<em class="placeholder">sdc.xb_test_sdc.image-required-without-example</em>\' cannot be enabled because it does not meet the requirements of Experience Builder.',
        'Prop "image" is required, but does not have example value',
      ],
    ]);
  }

  public function testStatusWithBlock(): void {
    $this->enableModules(['node', 'block']);
    $this->generateComponentConfig();

    $component = Component::create([
      'id' => 'block.node_syndicate_block',
      'status' => FALSE,
      'label' => 'Test',
      'category' => 'test',
      'source' => BlockComponent::SOURCE_PLUGIN_ID,
      'source_local_id' => 'node_syndicate_block',
      'active_version' => 'f1de6f216b243742',
      'versioned_properties' => [
        VersionedConfigEntityBase::ACTIVE_VERSION => [
          'settings' => [
            'default_settings' => [
              'id' => 'node_syndicate_block',
              'label' => 'Syndicate',
              // @todo Change this to FALSE once https://drupal.org/i/2544708
              //   is fixed.
              'label_display' => '0',
              'provider' => 'node',
              'block_count' => 10,
            ],
          ],
        ],
      ],
    ]);

    $this->assertTrue($component instanceof Component);
    $this->assertFalse($component->status());
    $this->assertEquals(SAVED_NEW, $component->save());

    $component->setStatus(TRUE);
    $this->assertTrue($component->status());

    $this->entity = $component;
    $this->assertValidationErrors([
      'status' => [
        'The component \'<em class="placeholder">block.node_syndicate_block</em>\' cannot be enabled because it does not meet the requirements of Experience Builder.',
        'Block plugin settings must be fully validatable',
      ],
    ]);
  }

}
