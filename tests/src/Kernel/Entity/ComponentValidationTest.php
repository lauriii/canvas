<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Kernel\Entity;

use Drupal\experience_builder\Entity\Component;
use Drupal\KernelTests\Core\Config\ConfigEntityValidationTestBase;

/**
 * Tests validation of component entities.
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
    // Modules providing field types + widgets for the component props defaults.
    'options',
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
      'component' => 'sdc_test+my-cta',
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
      'INVALID: space separated' => ['space separated+space separated', FALSE],
      'INVALID: uppercase letters' => ['Uppercase_Letters+Uppercase_Letters', FALSE],
      'INVALID: period separated' => ['period.separated+period.separated]', FALSE],
      'INVALID: only underscore separated' => ['underscore_separated_underscore_separated', FALSE],
      'VALID: plus instead of colon' => ['provider+component', TRUE],
      'VALID: dash separated' => ['dash-separated+dash-separated', TRUE],
      'VALID: underscore separated' => ['underscore_separated+underscore_separated', TRUE],
    ];
  }

  /**
   * Machine name of \Drupal\experience_builder\Entity\Component needs to be joined with +.
   * @param $length
   *
   * @return string
   */
  protected function randomMachineName($length = 8): string {
    return parent::randomMachineName(intdiv($length, 2)) . '+' . parent::randomMachineName(intdiv($length, 2));
  }

  /**
   * Tests validating a component with a SDC machine name.
   */
  public function testInvalidComponent(): void {
    $this->entity->set('component', Component::convertIdToMachineName($this->entity->get('component')));
    $this->assertValidationErrors([
      '' => "The 'component' property cannot be changed.",
      'component' => 'The <em class="placeholder">&quot;sdc_test:my-cta&quot;</em> machine name is not valid.',
    ]);
  }

}
