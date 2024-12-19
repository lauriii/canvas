<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Kernel\Entity;

use Drupal\experience_builder\Entity\Component;
use Drupal\KernelTests\Core\Config\ConfigEntityValidationTestBase;

/**
 * Tests validation of component entities.
 *
 * @todo Add `testStatus()` method in https://www.drupal.org/project/experience_builder/issues/3473289
 *
 * @group experience_builder
 */
class ComponentValidationTest extends ConfigEntityValidationTestBase {

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
  ];

  /**
   * {@inheritdoc}
   *
   * @phpstan-ignore property.defaultValue
   */
  protected static array $propertiesWithRequiredKeys = [
    'settings' => [
      "'plugin_id' is a required key because source is sdc (see config schema type experience_builder.component_source_settings.sdc).",
      "'props' is a required key because source is sdc (see config schema type experience_builder.component_source_settings.sdc).",
    ],
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->entity = Component::create([
      'id' => 'sdc.sdc_test.my-cta',
      'category' => 'Test',
      'source' => 'sdc',
      'settings' => [
        'plugin_id' => 'sdc_test:my-cta',
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
      'label' => 'Test',
    ]);
    $this->entity->save();
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
   *
   * @param $length
   *
   * @return string
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
    parent::testRequiredPropertyKeysMissing($additional_expected_validation_errors_when_missing);
  }

  /**
   * @dataProvider providerTestCategory
   */
  public function testCategory(?string $category, array $errors): void {
    $this->entity->set('category', $category);
    $this->assertValidationErrors($errors);
  }

  public function providerTestCategory(): \Generator {
    yield 'valid string' => ['foo', []];
    yield 'empty string' => ['', ['category' => 'This value should not be blank.']];
    yield 'null' => [NULL, ['category' => 'This value should not be null.']];
  }

}
