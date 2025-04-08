<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Kernel\Config;

use Drupal\Core\Theme\ComponentPluginManager as CoreComponentPluginManager;
use Drupal\experience_builder\Entity\Component;
use Drupal\experience_builder\Entity\JavaScriptComponent;
use Drupal\experience_builder\Plugin\ComponentPluginManager;
use Drupal\experience_builder\Plugin\ExperienceBuilder\ComponentSource\BlockComponent;
use Drupal\experience_builder\Plugin\ExperienceBuilder\ComponentSource\JsComponent;
use Drupal\experience_builder\Plugin\ExperienceBuilder\ComponentSource\SingleDirectoryComponent;
use Drupal\KernelTests\Core\Config\ConfigEntityValidationTestBase;
use Drupal\Tests\experience_builder\Traits\ContribStrictConfigSchemaTestTrait;
use Drupal\Tests\experience_builder\Traits\GenerateComponentConfigTrait;
use Symfony\Component\Yaml\Yaml;

/**
 * Tests validation of component entities.
 *
 * @group experience_builder
 */
class ComponentValidationTest extends ConfigEntityValidationTestBase {

  use ContribStrictConfigSchemaTestTrait;
  use GenerateComponentConfigTrait;

  protected CoreComponentPluginManager $componentPluginManager;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'experience_builder',
    'sdc',
    'sdc_test',
    'xb_test_sdc',
    // XB's dependencies (modules providing field types + widgets).
    'datetime',
    'file',
    'image',
    'options',
    'path',
    'link',
  ];

  /**
   * {@inheritdoc}
   *
   * @phpstan-ignore property.defaultValue
   */
  protected static array $propertiesWithRequiredKeys = [
    'settings' => [
      "'plugin_id' is a required key.",
      "'prop_field_definitions' is a required key because source is sdc (see config schema type experience_builder.component_source_settings.sdc).",
    ],
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

    $this->entity = Component::create([
      'id' => 'sdc.sdc_test.my-cta',
      'category' => 'Test',
      'source' => SingleDirectoryComponent::SOURCE_PLUGIN_ID,
      'settings' => [
        'plugin_id' => 'sdc_test:my-cta',
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
      'label' => 'Test',
    ]);
    $this->entity->save();
    $this->componentPluginManager = $this->container->get(ComponentPluginManager::class);
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
      'settings.prop_field_definitions' => 'Configuration for the SDC prop "<em class="placeholder">Target</em>" (<em class="placeholder">target</em>) is missing.',
    ]);

    // @see \Drupal\experience_builder\Plugin\ExperienceBuilder\ComponentSource\JsComponent
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
      'settings' => [
        'plugin_id' => 'my-cta',
        'prop_field_definitions' => array_diff_key(
          $this->entity->getSettings()['prop_field_definitions'],
          array_flip(['target']),
        ),
      ],
      'label' => 'Test',
    ]);
    $this->assertValidationErrors([
      'settings.prop_field_definitions' => "'target' is a required key.",
    ]);

    // @see \Drupal\experience_builder\Plugin\ExperienceBuilder\ComponentSource\BlockComponent
    $this->enableModules(['block']);
    $this->installConfig(['system']);
    $defaults = [];

    $this->entity = Component::create([
      'id' => 'block.system_branding_block',
      'category' => 'Test',
      'source' => BlockComponent::SOURCE_PLUGIN_ID,
      'settings' => [
        'plugin_id' => 'system_branding_block',
        'default_settings' => [
          // For `type: block_settings`.
          'id' => 'system_branding_block',
          'provider' => 'system',
          'label' => 'Site branding',
          // For `type: block.settings.system_branding_block`, which extends the
          // above.
          // @see \Drupal\system\Plugin\Block\SystemBrandingBlock::defaultConfiguration()
          'use_site_logo' => TRUE,
          'use_site_name' => FALSE,
          // But intentionally omitted `use_site_slogan`, which SHOULD trigger a
          // validation error.
          // 'use_site_slogan' => FALSE,
          'label_display' => FALSE,
        ] + $defaults,
      ],
      'label' => 'Test',
    ]);
    $this->assertValidationErrors([
      'settings.default_settings' => "'use_site_slogan' is a required key because settings.plugin_id is system_branding_block (see config schema type block.settings.system_branding_block).",
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
      'id' => "Expected 'sdc.sdc_test.my-cta', not 'invalid:name'. Format: '&lt;%parent.source&gt;.&lt;%parent.settings.plugin_id&gt;'.",
    ]);
  }

  public function testImmutableProperties(array $valid_values = []): void {
    $valid_values = [
      'id' => 'sdc.sdc_test.no-props',
      'source' => 'test',
      'plugin_id' => 'sdc_test:no-props',
    ];
    $additional_validation_errors = [
      'id' => [
        'id' => "Expected 'sdc.sdc_test.my-cta', not 'sdc.sdc_test.no-props'. Format: '&lt;%parent.source&gt;.&lt;%parent.settings.plugin_id&gt;'.",
      ],
      'source' => [
        'id' => "Expected 'test.sdc_test.my-cta', not 'sdc.sdc_test.my-cta'. Format: '&lt;%parent.source&gt;.&lt;%parent.settings.plugin_id&gt;'.",
        'settings' => "'prop_field_definitions' is an unknown key because source is test (see config schema type experience_builder.component_source_settings.*).",
        'source' => "The 'test' plugin does not exist.",
      ],
    ];

    // @todo Update parent method to accept a `$additional_validation_errors` parameter in addition to `$valid_values`, and uncomment the next line, remove all lines after it.
    // parent::testImmutableProperties($valid_values);
    $constraints = $this->entity->getEntityType()->getConstraints();
    $this->assertNotEmpty($constraints['ImmutableProperties'], 'All config entities should have at least one immutable ID property.');

    foreach ($constraints['ImmutableProperties'] as $property_name) {
      $original_value = $this->entity->get($property_name);
      $this->entity->set($property_name, $valid_values[$property_name] ?? $this->randomMachineName());
      $this->assertValidationErrors([
        '' => "The '$property_name' property cannot be changed.",
      ] + $additional_validation_errors[$property_name]);
      $this->entity->set($property_name, $original_value);
    }
  }

  public function testRequiredPropertyKeysMissing(?array $additional_expected_validation_errors_when_missing = NULL): void {
    $additional_expected_validation_errors_when_missing['settings']['id'] = 'This validation constraint is configured to inspect the properties <em class="placeholder">%parent.source, %parent.settings.plugin_id</em>, but some do not exist: <em class="placeholder">%parent.settings.plugin_id</em>.';
    $additional_expected_validation_errors_when_missing['settings']['status'] = [
      'The component \'<em class="placeholder">sdc.sdc_test.my-cta</em>\' cannot be enabled because it does not meet the requirements of Experience Builder.',
      'Component has no valid source plugin_id value.',
    ];
    parent::testRequiredPropertyKeysMissing($additional_expected_validation_errors_when_missing);
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
      'settings' => [
        'plugin_id' => 'node_syndicate_block',
        'default_settings' => [],
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
