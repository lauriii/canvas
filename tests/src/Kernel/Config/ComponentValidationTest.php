<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Kernel\Config;

use Drupal\Core\Config\Schema\SchemaIncompleteException;
use Drupal\Core\Theme\ComponentPluginManager as CoreComponentPluginManager;
use Drupal\experience_builder\Entity\Component;
use Drupal\experience_builder\Entity\ComponentInterface;
use Drupal\experience_builder\Entity\JavaScriptComponent;
use Drupal\experience_builder\Entity\VersionedConfigEntityBase;
use Drupal\experience_builder\Entity\VersionedConfigEntityInterface;
use Drupal\experience_builder\Plugin\ComponentPluginManager;
use Drupal\experience_builder\Plugin\ExperienceBuilder\ComponentSource\BlockComponent;
use Drupal\experience_builder\Plugin\ExperienceBuilder\ComponentSource\JsComponent;
use Drupal\experience_builder\Plugin\ExperienceBuilder\ComponentSource\SingleDirectoryComponent;
use Drupal\Tests\experience_builder\Kernel\Traits\CiModulePathTrait;
use Drupal\Tests\experience_builder\Traits\BetterConfigDependencyManagerTrait;
use Drupal\Tests\experience_builder\Traits\ConstraintViolationsTestTrait;
use Drupal\Tests\experience_builder\Traits\ContribStrictConfigSchemaTestTrait;
use Drupal\Tests\experience_builder\Traits\GenerateComponentConfigTrait;
use Symfony\Component\Yaml\Yaml;

/**
 * Tests validation of component entities.
 *
 * @group experience_builder
 */
class ComponentValidationTest extends BetterConfigEntityValidationTestBase {

  use BetterConfigDependencyManagerTrait;
  use ConstraintViolationsTestTrait;
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
    'filter',
    'ckeditor5',
    'editor',
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
  protected static $configSchemaCheckerExclusions = [
    // We need to create a JavaScriptComponent with invalid source-defined slot
    // name in order to test that even Component config entity's fallback slot
    // definitions are validated.
    // @see ::testSlotNameValidation()
    'experience_builder.' . JavaScriptComponent::ENTITY_TYPE_ID . '.invalid_slot',
    'experience_builder.' . Component::ENTITY_TYPE_ID . '.' . JsComponent::SOURCE_PLUGIN_ID . '.invalid_slot',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig('experience_builder');
    $this->entity = Component::create([
      'id' => 'sdc.xb_test_sdc.my-cta',
      'category' => 'Test',
      'source' => SingleDirectoryComponent::SOURCE_PLUGIN_ID,
      'source_local_id' => 'xb_test_sdc:my-cta',
      'active_version' => 'b4cd62533ff9bd99',
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
                  'allowed_values_function' => 'experience_builder_load_allowed_values_for_component_prop',
                ],
                // @see \Drupal\Core\Field\Plugin\Field\FieldWidget\OptionsSelectWidget
                'field_widget' => 'options_select',
                'default_value' => NULL,
                'expression' => 'ℹ︎list_string␟value',
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
          'options',
          'xb_test_sdc',
        ],
      ],
      $this->entity->getDependencies()
    );
    $this->assertSame([
      'module' => [
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
   * This test method is aimed to test the ComponentSource-specific settings.
   *
   * @covers \Drupal\experience_builder\Plugin\Validation\Constraint\SdcPropKeysConstraintValidator
   */
  public function testComponentSourceSpecificSettings(): void {
    // @see \Drupal\experience_builder\Plugin\ExperienceBuilder\ComponentSource\SingleDirectoryComponent
    assert($this->entity instanceof Component);
    $invalid_settings_due_to_extraneous_prop_field_definition = $invalid_settings_due_to_missing_prop_field_definition = $this->entity->getSettings();

    // Too much.
    $this->enableModules(['media', 'media_library', 'views']);
    $invalid_settings_due_to_extraneous_prop_field_definition['prop_field_definitions']['image'] = [
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
      'expression' => 'ℹ︎image␟{src↠src_with_alternate_widths,alt↠alt,width↠width,height↠height}',
    ];
    try {
      $this->entity->createVersion('abcdef12343fa3dc')
        ->setSettings($invalid_settings_due_to_extraneous_prop_field_definition)
        ->save();
    }
    catch (SchemaIncompleteException $e) {
      // We can't use ::assertValidationErrors here because we need to make use
      // of ::save to set fallback metadata.
      self::assertEquals('Schema errors for experience_builder.component.sdc.xb_test_sdc.my-cta with the following errors: 0 [active_version] The version abcdef12343fa3dc does not match the hash of the settings for this version, expected 922675dd0e16c16e., 1 [versioned_properties.active.settings.prop_field_definitions] Configuration present for a non-existent SDC prop: &lt;em class=&quot;placeholder&quot;&gt;image&lt;/em&gt;.', $e->getMessage());
    }

    // Too little.
    $target = $invalid_settings_due_to_missing_prop_field_definition['prop_field_definitions']['target'];
    unset($invalid_settings_due_to_missing_prop_field_definition['prop_field_definitions']['target']);
    try {
      \assert($this->entity instanceof ComponentInterface);
      $this->entity->createVersion('abcdef12343fa3dc')
        ->setSettings($invalid_settings_due_to_missing_prop_field_definition)
        ->save();
    }
    catch (SchemaIncompleteException $e) {
      // We can't use ::assertValidationErrors here because we need to make use
      // of ::save to set fallback metadata.
      self::assertEquals('Schema errors for experience_builder.component.sdc.xb_test_sdc.my-cta with the following errors: 0 [active_version] The version abcdef12343fa3dc does not match the hash of the settings for this version, expected b906907408185f70., 1 [versioned_properties.active.settings.prop_field_definitions] Configuration for the SDC prop &quot;&lt;em class=&quot;placeholder&quot;&gt;Target&lt;/em&gt;&quot; (&lt;em class=&quot;placeholder&quot;&gt;target&lt;/em&gt;) is missing.', $e->getMessage());
    }
    // But an invalid version hash doesn't matter for old versions.
    $invalid_settings_due_to_missing_prop_field_definition['prop_field_definitions']['target'] = $target;
    \assert($this->entity instanceof ComponentInterface);
    $this->entity->createVersion(
      'b4cd62533ff9bd99'
    )->setSettings($invalid_settings_due_to_missing_prop_field_definition)->save();
    // No validation errors even though the old 'abcdef12343fa3dc'
    // version is invalid.
    $this->assertValidationErrors([]);

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
    // @todo Consider supporting this in https://www.drupal.org/i/3514672
    unset($props['target']['default']);
    // @todo Remove these 2 in https://www.drupal.org/i/3516602
    unset($props['target']['meta:enum']);
    unset($props['target']['x-translation-context']);
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
      'active_version' => 'b906907408185f70',
      'versioned_properties' => [
        VersionedConfigEntityBase::ACTIVE_VERSION => [
          'settings' => [
            'prop_field_definitions' => array_diff_key(
              $this->entity->getSettings()['prop_field_definitions'],
              // Remove the 'target' key to trigger a validation error.
              array_flip(['target']),
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
      'active_version' => 'The version 7a2bdba02d8b7911 does not match the hash of the settings for this version, expected f80ad196f2c2cc64.',
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
      'active_version' => '8d6f197567cc882e',
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
        'Block plugin settings must opt into strict validation. Use the FullyValidatable constraint. See https://www.drupal.org/node/3404425',
      ],
    ]);
  }

  // cspell:ignore eird

  /**
   * @testWith ["valid", false, "102d161a6069b0bf"]
   *   ["even_more-valid", false, "b89b9f874769d01e"]
   *   ["-", true, "cd4019731175e414"]
   *   ["--", true, "e6507bfbf8ab4de5"]
   *   ["_", true, "d424855d70852377"]
   *   ["__", true, "823c8d2eb2d05352"]
   *   ["-not_valid", true, "67baf46859e91b91"]
   *   ["_not_valid", true, "3972480bc11893e9"]
   *   ["not_valid-", true, "2af87d83152ee878"]
   *   ["not_valid_", true, "31cc78f610f8147a"]
   *   ["a", true, "86e65a63d5c64c96"]
   *   ["aa", true, "06841b5c562fd150"]
   *   ["aaa", false, "45d801ed93ec2876"]
   *   ["n😈t_valid", true, "95bb0e4d0d0c208b"]
   *   ["spaces aren't okay", true, "b911692027992e7a"]
   *   ["newline\nnot_allowed", true, "c413270ad235c44c"]
   *   ["rm -rf /", true, "d4b25a8c7fa2617c"]
   *   ["slot_\u03E2eird", true, "c33062b3a4641476"]
   *   ["children", true, "1cea66d0113298ef"]
   */
  public function testSlotNameValidation(string $slot_name, bool $is_invalid, string $expected_version): void {
    // For every "code component" (JavaScriptComponent) with `status: true`, a
    // corresponding Component config entity is auto-created. Use this to be
    // able to test
    $js_component_with_invalid_slot = JavaScriptComponent::create([
      'machineName' => 'invalid_slot',
      'name' => $this->getRandomGenerator()->sentences(5),
      'status' => FALSE,
      'props' => [],
      'required' => [],
      'slots' => [
        $slot_name => [
          'title' => 'Bad?',
          'description' => "This slot might have an invalid name.",
          'examples' => [],
        ],
      ],
      'js' => [
        'original' => 'console.log("hey");',
        'compiled' => 'console.log("hey");',
      ],
      'css' => [
        'original' => '.test { display: none; }',
        'compiled' => '.test { display: none; }',
      ],
    ]);
    $violations = $js_component_with_invalid_slot->getTypedData()->validate();
    if ($is_invalid) {
      $expected_violations = [
        "slots.$slot_name" => \sprintf('<em class="placeholder">&quot;%s&quot;</em> is not a valid slot name.', \htmlentities($slot_name)),
      ];
      // Violations could come from ValidSlotNameConstraint but also from json
      // schema where slot properties must match the ^[a-zA-Z0-9_-]+$ pattern.
      // @see core/assets/schemas/v1/metadata-full.schema.json
      if (\preg_match('/^[a-zA-Z0-9_-]+$/', $slot_name) !== 1) {
        $expected_violations = [
          '' => \sprintf('[slots] The property %s is not defined and the definition does not allow additional properties', $slot_name),
        ] + $expected_violations;
      }
      self::assertSame($expected_violations, self::violationsToArray($violations));
    }
    else {
      self::assertCount(0, $violations);
    }

    // Save anyway, because the purpose of this test is to verify that even the
    // slot names in the fallback metadata for a Component are validated.
    $js_component_with_invalid_slot->enable()->save();
    $corresponding_component = Component::load(JsComponent::SOURCE_PLUGIN_ID . '.invalid_slot');
    assert($corresponding_component instanceof Component);

    // Assert that the slot name indeed is present in the auto-generated
    // fallback metadata.
    // @see \Drupal\experience_builder\Entity\Component::preSave()
    self::assertArrayHasKey($slot_name, $corresponding_component->get('fallback_metadata')['slot_definitions']);

    // Make the corresponding Component the entity being tested and validate.
    $this->entity = $corresponding_component;
    self::assertSame([$expected_version], $this->entity->getVersions());
    $expected_errors = [];
    if ($is_invalid) {
      $expected_errors["versioned_properties.active.fallback_metadata.slot_definitions.$slot_name"] = sprintf('<em class="placeholder">&quot;%s&quot;</em> is not a valid slot name.', htmlentities($slot_name));
    }
    $this->assertValidationErrors($expected_errors);

    // Ensure that even when a change in the JavaScriptComponent causes a new
    // version of the Component to be created *without* an invalid slot, that
    // the same validation error is still thrown for the old version, but not
    // for the new version.
    $js_component_with_invalid_slot->set('slots', [])->save();
    $updated_corresponding_component = Component::load(JsComponent::SOURCE_PLUGIN_ID . '.invalid_slot');
    assert($updated_corresponding_component instanceof Component);
    $this->entity = $updated_corresponding_component;
    self::assertSame(['8fe3be948e0194e1', $expected_version], $this->entity->getVersions());
    $expected_errors = [];
    if ($is_invalid) {
      $expected_errors["versioned_properties.$expected_version.fallback_metadata.slot_definitions.$slot_name"] = sprintf('<em class="placeholder">&quot;%s&quot;</em> is not a valid slot name.', htmlentities($slot_name));
    }
    $this->assertValidationErrors($expected_errors);
  }

  /**
   * @see \Drupal\experience_builder\ComponentMetadataRequirementsChecker::check()
   */
  public function testUnmatchedEnumAndMetaEnum(): void {
    $component = Component::load('sdc.xb_test_sdc:component-mismatch-meta-enum');
    $this->assertNull($component);
    $component = SingleDirectoryComponent::createConfigEntity($this->componentPluginManager->find('xb_test_sdc:component-mismatch-meta-enum'));
    $component->setStatus(FALSE);
    $this->assertEquals(SAVED_NEW, $component->save());
    $component->setStatus(TRUE);
    $this->entity = $component;
    $this->assertValidationErrors([
      'status' => [
        'The component \'<em class="placeholder">sdc.xb_test_sdc.component-mismatch-meta-enum</em>\' cannot be enabled because it does not meet the requirements of Experience Builder.',
        'The "meta:enum" keys for the "style" prop enum cannot contain a dot. Offending key: "contains.dots"',
        'The "meta:enum" keys for the "numbers" prop enum cannot contain a dot. Offending key: "3.14"',
        'The values for the "numbers" prop enum must be defined in "meta:enum". Missing keys: "3_14"',
      ],
    ]);
  }

}
