<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Kernel\Config;

use Drupal\experience_builder\Entity\JavaScriptComponent;
use Drupal\KernelTests\Core\Config\ConfigEntityValidationTestBase;

/**
 * Tests validation of JavaScriptComponent entities.
 *
 * @group experience_builder
 */
class JavaScriptComponentValidationTest extends ConfigEntityValidationTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'experience_builder',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->entity = JavaScriptComponent::create([
      'machineName' => 'test',
      'name' => 'Test',
      'status' => TRUE,
      'props' => [
        'text' => [
          'type' => 'string',
          'title' => 'Title',
          'examples' => ['Press', 'Submit now'],
        ],
      ],
      'slots' => [
        'test-slot' => [
          'title' => 'test',
          'description' => 'Title',
          'examples' => [
            'Test 1',
            'Test 2',
          ],
        ],
      ],
      'source_code_js' => 'console.log("Test")',
      'source_code_css' => '.test { display: none; }',
      'compiled_js' => 'console.log("Test")',
      'compiled_css' => '.test{display:none;}',
    ]);
    $this->entity->save();
  }

  /**
   * Tests different permutations of entity values.
   *
   * @param array $shape
   *   Array of entity values.
   * @param array $expected_errors
   *   Expected validation errors.
   *
   * @dataProvider providerTestEntityShapes
   */
  public function testEntityShapes(array $shape, array $expected_errors): void {
    $this->entity = JavaScriptComponent::create($shape);
    $this->entity->save();
    $this->assertValidationErrors($expected_errors);
  }

  public static function providerTestEntityShapes(): array {
    return [
      'Invalid: no JS' => [
        [
          'machineName' => 'test-no-slots-no-props',
          'name' => 'Test',
          'props' => [],
          'slots' => [],
          'source_code_js' => NULL,
          'source_code_css' => '.test { display: none; }',
          'compiled_js' => NULL,
          'compiled_css' => '.test{display:none;}',
        ],
        [
          'source_code_js' => 'This value should not be null.',
          'compiled_js' => 'This value should not be null.',
        ],
      ],
      'Invalid: Unknown prop type' => [
        [
          'machineName' => 'test-unknown-prop-type',
          'name' => 'Test',
          'props' => [
            'mixed_up_prop' => [
              'type' => 'unknown',
              'title' => 'Title',
              'enum' => [
                'Press',
                'Click',
                'Submit',
              ],
              'examples' => ['Press', 'Submit now'],
            ],
          ],
          'slots' => [],
          'source_code_js' => 'console.log("Test")',
          'source_code_css' => '.test { display: none; }',
          'compiled_js' => 'console.log("Test")',
          'compiled_css' => '.test{display:none;}',
        ],
        [
          '' => 'Unable to find class/interface "unknown" specified in the prop "mixed_up_prop" for the component "experience_builder:test-unknown-prop-type".',
          'props.mixed_up_prop.type' => 'The value you selected is not a valid choice.',
        ],
      ],
      'Valid: no props and no slots' => [
        [
          'machineName' => 'test-no-slots-no-props',
          'name' => 'Test',
          'props' => [],
          'slots' => [],
          'source_code_js' => 'console.log("Test")',
          'source_code_css' => '.test { display: none; }',
          'compiled_js' => 'console.log("Test")',
          'compiled_css' => '.test{display:none;}',
        ],
        [],
      ],
      'Valid: props (of all supported types), of which two required and no slots' => [
        [
          'machineName' => 'test-props-no-slots',
          'name' => 'Test',
          'props' => [
            'string' => [
              'type' => 'string',
              'title' => 'Title',
              'examples' => ['Press', 'Submit now'],
            ],
            'boolean' => [
              'type' => 'boolean',
              'title' => 'Truth',
              'examples' => [TRUE, FALSE],
            ],
            'integer' => [
              'type' => 'integer',
              'title' => 'Integer',
              'examples' => [23, 10, 2024],
            ],
            'number' => [
              'type' => 'number',
              'title' => 'Number',
              'examples' => [3.14],
            ],
          ],
          'required' => [
            'string',
            'integer',
          ],
          'slots' => [],
          'source_code_js' => 'console.log("Test")',
          'source_code_css' => '.test { display: none; }',
          'compiled_js' => 'console.log("Test")',
          'compiled_css' => '.test{display:none;}',
        ],
        [],
      ],
      'Invalid: a non-existent required prop' => [
        [
          'machineName' => 'test-non-existent-required-prop',
          'name' => 'Test',
          'props' => [
            'string' => [
              'type' => 'string',
              'title' => 'Title',
              'examples' => ['Press', 'Submit now'],
            ],
          ],
          'required' => [
            'does_not_exist',
          ],
          'slots' => [],
          'source_code_js' => 'console.log("Test")',
          'source_code_css' => '.test { display: none; }',
          'compiled_js' => 'console.log("Test")',
          'compiled_css' => '.test{display:none;}',
        ],
        [
          // ⚠️ SDC does not complain about this!
          // @see \Drupal\Core\Theme\Component\ComponentValidator
          // @todo Update once https://www.drupal.org/project/drupal/issues/3493086 is fixed.
        ],
      ],
      'Valid: props, no slots set' => [
        [
          'machineName' => 'test-props-no-slots',
          'name' => 'Test',
          'props' => [
            'text' => [
              'type' => 'string',
              'title' => 'Title',
              'examples' => ['Press', 'Submit now'],
            ],
          ],
          'source_code_js' => 'console.log("Test")',
          'source_code_css' => '.test { display: none; }',
          'compiled_js' => 'console.log("Test")',
          'compiled_css' => '.test{display:none;}',
        ],
        [],
      ],
      'Valid: enum props' => [
        [
          'machineName' => 'test-props-no-slots',
          'name' => 'Test',
          'props' => [
            'text' => [
              'type' => 'string',
              'title' => 'Title',
              'enum' => [
                'Press',
                'Click',
                'Submit',
              ],
              'examples' => ['Press', 'Submit now'],
            ],
          ],
          'slots' => [],
          'source_code_js' => 'console.log("Test")',
          'source_code_css' => '.test { display: none; }',
          'compiled_js' => 'console.log("Test")',
          'compiled_css' => '.test{display:none;}',
        ],
        [],
      ],
      'Valid: empty JS and CSS, no props, nor slots and "disabled"' => [
        [
          'machineName' => 'test-no-js-no-css-no-props-nor-slots-and-disabled',
          'status' => FALSE,
          'name' => 'Test',
          'props' => [],
          'slots' => [],
          'source_code_js' => '',
          'source_code_css' => '',
          'compiled_js' => '',
          'compiled_css' => '',
        ],
        [],
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public static function providerInvalidMachineNameCharacters(): array {
    return [
      'INVALID: space separated' => ['space separated', FALSE],
      'INVALID: period separated' => ['period.separated', FALSE],
      'VALID: dash separated' => ['dash-separated', TRUE],
      'VALID: underscore separated' => ['underscore_separated', TRUE],
      'VALID: contains uppercase' => ['containsUppercase', TRUE],
      'INVALID: starts uppercase' => ['StartsUppercase', FALSE],
      'VALID: contains number' => ['number1', TRUE],
      'INVALID: starts with number' => ['10th_birthday', FALSE],
    ];
  }

  public function testInvalidSlotIdentifiedByConfigSchema(): void {
    $original_test_slot = $this->entity->get('slots')['test-slot'];
    $this->entity->set('slots', [
      'test-slot' => array_diff_key($original_test_slot, array_flip(['examples'])),
    ]);
    $this->assertValidationErrors([
      'slots.test-slot' => "'examples' is a required key.",
    ]);
    $this->entity->set('slots', [
      '0-slot' => $original_test_slot,
    ]);
    // @todo This test case should have validation errors because '0-slot' is not a valid slot name.
    //   But currently we can not use the 'patternProperties' until
    //   https://www.drupal.org/i/3471064 is fixed.
    $this->assertValidationErrors([]);
    $this->entity->set('slots', ['test-slot' => []]);
    $this->assertValidationErrors([
      'slots.test-slot' => [
        "'title' is a required key.",
        "'description' is a required key.",
        "'examples' is a required key.",
      ],
    ]);
  }

  public function testCollisionBetweenPropsAndSlots(): void {
    $prop_colliding_with_slot = [
      'test-slot' => [
        'title' => 'contrived example',
        'type' => 'string',
        'examples' => ['foo'],
      ],
    ];
    $this->entity->set('props', $prop_colliding_with_slot);
    $this->assertValidationErrors([
      '' => 'The component "experience_builder:test" declared [test-slot] both as a prop and as a slot. Make sure to use different names.',
    ]);

    // Verify that if there's a lower-level problem, that both the low-level and
    // this high-level consistency validation error appear.
    unset($prop_colliding_with_slot['test-slot']['examples']);
    $this->entity->set('props', $prop_colliding_with_slot);
    $this->assertValidationErrors([
      '' => 'The component "experience_builder:test" declared [test-slot] both as a prop and as a slot. Make sure to use different names.',
      'props.test-slot' => "'examples' is a required key.",
    ]);
  }

  protected function assertValidationErrors(array $expected_messages): void {
    // JsComponentHasValidSdcMetadata adds additional validation, but
    // \Drupal\KernelTests\Core\Config\ConfigEntityValidationTestBase::testInvalidMachineNameCharacters()
    // does not provide a way to add additional errors when the machine name is
    // invalid.
    $invalid_id_messages = [
      'machineName' => 'The <em class="placeholder">&quot;' . $this->entity->id() . '&quot;</em> machine name is not valid.',
      '' => "The 'machineName' property cannot be changed.",
    ];
    // 'dash-separated' is valid machine name for component but not for config
    // entity.
    if ($this->entity->id() !== 'dash-separated' && $expected_messages === $invalid_id_messages) {
      $expected_messages[''] = [
        '[id] Does not match the regex pattern ^[a-z]([a-zA-Z0-9_-]*[a-zA-Z0-9])*:[a-z]([a-zA-Z0-9_-]*[a-zA-Z0-9])*$/n[machineName] Does not match the regex pattern ^[a-z]([a-zA-Z0-9_-]*[a-zA-Z0-9])*$',
        $expected_messages[''],
      ];
    }
    parent::assertValidationErrors($expected_messages);
  }

}
