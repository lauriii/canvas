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
  ];

  /**
   * {@inheritdoc}
   */
  protected static array $propertiesWithRequiredKeys = [
    'defaults' => "'props' is a required key.",
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->entity = Component::create([
      'id' => 'sdc+sdc_test+my-cta',
      'component' => 'sdc_test:my-cta',
      'label' => 'Test',
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
      'INVALID: missing components' => ['sdc+sdc', FALSE],
      'INVALID: space separated' => ['sdc+space separated+space separated', FALSE],
      'INVALID: uppercase letters' => ['sdc+Uppercase_Letters+Uppercase_Letters', FALSE],
      'INVALID: period separated' => ['sdc+period.separated+period.separated]', FALSE],
      'INVALID: only underscore separated' => ['sdc+underscore_separated_underscore_separated', FALSE],
      'VALID: plus instead of colon' => ['sdc+provider+component', TRUE],
      'VALID: dash separated' => ['sdc+dash-separated+dash-separated', TRUE],
      'VALID: underscore separated' => ['sdc+underscore_separated+underscore_separated', TRUE],
    ];
  }

  /**
   * Machine name of \Drupal\experience_builder\Entity\Component needs to be joined with +.
   * @param $length
   *
   * @return string
   */
  protected function randomMachineName($length = 8): string {
    return 'sdc+' . parent::randomMachineName(intdiv($length, 2)) . '+' . parent::randomMachineName(intdiv($length, 2));
  }

  /**
   * Tests validating a component with a SDC machine name.
   */
  public function testInvalidId(): void {
    $this->entity->set('id', 'sdc_test:my-cta');
    $this->assertValidationErrors([
      '' => "The 'id' property cannot be changed.",
      'id' => 'The <em class="placeholder">&quot;sdc_test:my-cta&quot;</em> machine name is not valid.',
    ]);
  }

  /**
   * @testWith ["sdc_test+my-cta", true, true]
   *           ["invalid", true, true]
   *           ["experience_builder:non_existent", false, true]
   */
  public function testInvalidComponent(string $component, bool $expect_error_for_invalid_name, bool $expect_error_for_non_existent_plugin): void {
    $expected_error_messages = [];
    if ($expect_error_for_invalid_name) {
      $expected_error_messages[] = sprintf('The <em class="placeholder">&quot;%s&quot;</em> machine name is not valid.', $component);
    }
    if ($expect_error_for_non_existent_plugin) {
      $expected_error_messages[] = sprintf("The '%s' plugin does not exist.", $component);
    }

    $this->entity->set('component', $component);
    // @phpstan-ignore-next-line
    $this->assertValidationErrors([
      '' => "The 'component' property cannot be changed.",
      'component' => count($expected_error_messages) > 1
        ? $expected_error_messages
        : reset($expected_error_messages),
    ]);
  }

  public function testImmutableProperties(array $valid_values = []): void {
    $valid_values = [
      'component' => 'sdc_test:no-props',
      'id' => 'sdc+sdc_test+no-props',
      'defaults' => ['props' => []],
    ];
    parent::testImmutableProperties($valid_values);
  }

}
