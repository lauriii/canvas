<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Kernel\DataType;

use Drupal\Core\TypedData\DataDefinition;
use Drupal\experience_builder\Plugin\DataType\ComponentTreeStructure;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\experience_builder\Traits\ConstraintViolationsTestTrait;
use Drupal\Tests\experience_builder\Traits\GenerateComponentConfigTrait;

/**
 * @group experience_builder
 */
class ComponentTreeStructureTest extends KernelTestBase {

  use ConstraintViolationsTestTrait;
  use GenerateComponentConfigTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'experience_builder',
    'xb_test_sdc',
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
    $this->generateComponentConfig();
  }

  /**
   * @coversDefaultClass \Drupal\experience_builder\Plugin\Validation\Constraint\ComponentTreeStructureConstraintValidator
   *
   * @param array $tree
   *   The tree JSON string.
   * @param array<string> $expected_violations
   *   The expected violation messages.
   *
   * @dataProvider providerValidation
   */
  public function testValidation(array $tree, array $expected_violations): void {
    $json = json_encode($tree);
    $data = DataDefinition::create('component_tree_structure');
    $component_tree_structure = new ComponentTreeStructure($data, 'component_tree_structure', NULL);
    $component_tree_structure->setValue($json);
    $violations = $component_tree_structure->validate();
    $this->assertSame($expected_violations, self::violationsToArray($violations));
  }

  public static function providerValidation(): array {
    $root_uuid = ComponentTreeStructure::ROOT_UUID;
    return [
      'INVALID: component instance keys wrong, string instead of arrays' => [
        [
          $root_uuid => [
            ['component' => 'sdc+xb_test_sdc+props-slots'],
            ['wrong-key' => 'a value'],
            "string",
          ],
          'uuid-in-root' => [
            'the_body' => [
              ['wrong-key' => 'a value'],
              "string",
            ],
            "string",
          ],
        ],
        [
          "[$root_uuid][0][uuid]" => 'This field is missing.',
          "[$root_uuid][1][uuid]" => 'This field is missing.',
          "[$root_uuid][1][component]" => 'This field is missing.',
          "[$root_uuid][1][wrong-key]" => 'This field was not expected.',
          // TRICKY: this is due to a bug in \Drupal\Core\Validation\DrupalTranslator::trans() — it should replace `{{ … }}` in the message.
          "[$root_uuid][2]" => 'This value should be of type {{ type }}.',
          '[uuid-in-root][the_body][0][uuid]' => 'This field is missing.',
          '[uuid-in-root][the_body][0][component]' => 'This field is missing.',
          '[uuid-in-root][the_body][0][wrong-key]' => 'This field was not expected.',
          // TRICKY: this is due to a bug in \Drupal\Core\Validation\DrupalTranslator::trans() — it should replace `{{ … }}` in the message.
          '[uuid-in-root][the_body][1]' => 'This value should be of type {{ type }}.',
          // TRICKY: this is due to a bug in \Drupal\Core\Validation\DrupalTranslator::trans() — it should replace `{{ … }}` in the message.
          '[uuid-in-root][0]' => 'This value should be of type {{ type }}.',
          '[uuid-in-root]' => 'Dangling component subtree. This component subtree claims to be for a component instance with UUID <em class="placeholder">uuid-in-root</em>, but no such component instance can be found.',
        ],
      ],
      'INVALID: no root uuid, other UUID not in tree, and has empty slots' => [
        ['other-uuid' => []],
        [
          "[$root_uuid]" => 'The root UUID is missing.',
          '[other-uuid]' => [
            'Empty component subtree. A component subtree must contain >=1 populated slot (with >=1 component instance). Empty component subtrees must be omitted.',
            'Dangling component subtree. This component subtree claims to be for a component instance with UUID <em class="placeholder">other-uuid</em>, but no such component instance can be found.',
          ],
        ],
      ],
      'VALID: only root' => [
        [ComponentTreeStructure::ROOT_UUID => []],
        [],
      ],
      'VALID: valid tree, only root, component has slots but empty' => [
        [
          ComponentTreeStructure::ROOT_UUID => [
            ['uuid' => 'uuid-in-root', 'component' => 'sdc+xb_test_sdc+props-slots'],
          ],
        ],
        [],
      ],
      'VALID: valid tree, with top level, component has slots, slots have correct names' => [
        [
          ComponentTreeStructure::ROOT_UUID => [
            ['uuid' => 'uuid-in-top-level', 'component' => 'sdc+xb_test_sdc+props-slots'],
          ],
          'uuid-in-top-level' => [
            'the_body' => [
              ['uuid' => 'uuid-under-top-level', 'component' => 'sdc+xb_test_sdc+props-no-slots'],
            ],
          ],
        ],
        [],
      ],
      'INVALID: valid tree, with top level, component has slots, used 3x, 2x with slots have wrong names' => [
        [
          ComponentTreeStructure::ROOT_UUID => [
            ['uuid' => 'uuid-in-top-level-1', 'component' => 'sdc+xb_test_sdc+props-slots'],
            ['uuid' => 'uuid-in-top-level-2', 'component' => 'sdc+xb_test_sdc+props-slots'],
          ],
          'uuid-in-top-level-1' => [
            'the_body' => [
              ['uuid' => 'uuid-under-top-level-5', 'component' => 'sdc+xb_test_sdc+props-slots'],
            ],
          ],
          'uuid-in-top-level-2' => [
            'slot1' => [
              ['uuid' => 'uuid-under-top-level-1', 'component' => 'sdc+xb_test_sdc+props-no-slots'],
            ],
            'slot2' => [
              ['uuid' => 'uuid-under-top-level-2', 'component' => 'sdc+xb_test_sdc+props-no-slots'],
            ],
            'the_body' => [
              ['uuid' => 'uuid-under-top-level-3', 'component' => 'sdc+xb_test_sdc+props-slots'],
            ],
          ],
          'uuid-under-top-level-3' => [
            'slot3' => [
              ['uuid' => 'uuid-under-2nd-level2', 'component' => 'sdc+xb_test_sdc+props-no-slots'],
            ],
          ],
        ],
        [
          '[uuid-in-top-level-2][slot1]' => 'Invalid component subtree. This component subtree contains an invalid slot name for component <em class="placeholder">sdc+xb_test_sdc+props-slots</em>: <em class="placeholder">slot1</em>. Valid slot names are: <em class="placeholder">the_body, the_footer, the_colophon</em>.',
          '[uuid-in-top-level-2][slot2]' => 'Invalid component subtree. This component subtree contains an invalid slot name for component <em class="placeholder">sdc+xb_test_sdc+props-slots</em>: <em class="placeholder">slot2</em>. Valid slot names are: <em class="placeholder">the_body, the_footer, the_colophon</em>.',
          '[uuid-under-top-level-3][slot3]' => 'Invalid component subtree. This component subtree contains an invalid slot name for component <em class="placeholder">sdc+xb_test_sdc+props-slots</em>: <em class="placeholder">slot3</em>. Valid slot names are: <em class="placeholder">the_body, the_footer, the_colophon</em>.',
        ],
      ],
      'INVALID: valid tree, only root, component with no slots, listed at top level' => [
        [
          ComponentTreeStructure::ROOT_UUID => [
            ['uuid' => 'uuid-in-root-1', 'component' => 'sdc+xb_test_sdc+props-no-slots'],
            ['uuid' => 'uuid-in-root-2', 'component' => 'sdc+xb_test_sdc+props-no-slots'],
          ],
          'uuid-in-root-2' => [],
        ],
        [
          '[uuid-in-root-2]' => [
            'Invalid component subtree. A component subtree must only exist for components with >=1 slot, but the component <em class="placeholder">sdc+xb_test_sdc+props-no-slots</em> has no slots, yet a subtree exists for the instance with UUID <em class="placeholder">sdc+xb_test_sdc+props-no-slots</em>.',
            'Empty component subtree. A component subtree must contain >=1 populated slot (with >=1 component instance). Empty component subtrees must be omitted.',
          ],
        ],
      ],
      'INVALID: valid tree, only root, component with slots, listed at top level but slot array empty' => [
        [
          ComponentTreeStructure::ROOT_UUID => [
            ['uuid' => 'uuid-in-root', 'component' => 'sdc+xb_test_sdc+props-slots'],
          ],
          'uuid-in-root' => [],
        ],
        [
          '[uuid-in-root]' => 'Empty component subtree. A component subtree must contain >=1 populated slot (with >=1 component instance). Empty component subtrees must be omitted.',
        ],
      ],
      'INVALID: valid tree, only root, component with slots, listed at top level with slot set but empty' => [
        [
          ComponentTreeStructure::ROOT_UUID => [
            ['uuid' => 'uuid-in-root', 'component' => 'sdc+xb_test_sdc+props-slots'],
          ],
          'uuid-in-root' => [
            'slot1' => [],
            'the_body' => [],
          ],
        ],
        [
          '[uuid-in-root][slot1]' => [
            'Invalid component subtree. This component subtree contains an invalid slot name for component <em class="placeholder">sdc+xb_test_sdc+props-slots</em>: <em class="placeholder">slot1</em>. Valid slot names are: <em class="placeholder">the_body, the_footer, the_colophon</em>.',
            'Empty slot. Slots without component instances must be omitted.',
          ],
          '[uuid-in-root][the_body]' => 'Empty slot. Slots without component instances must be omitted.',
        ],
      ],
      'INVALID: valid tree, with top level, under own branch' => [
        [
          ComponentTreeStructure::ROOT_UUID => [
            ['uuid' => 'uuid-in-top-level-not-match', 'component' => 'sdc+xb_test_sdc+props-no-slots'],
          ],
          'uuid-in-top-level-recursive' => [
            'the_body' => [
              ['uuid' => 'uuid-in-top-level-recursive', 'component' => 'sdc+xb_test_sdc+props-slots'],
            ],
          ],
        ],
        [
          '[uuid-in-top-level-recursive]' => 'Dangling component subtree. This component subtree claims to be for a component instance with UUID <em class="placeholder">uuid-in-top-level-recursive</em>, but no such component instance can be found.',
        ],
      ],
      'VALID: valid tree, multiple levels' => [
        [
          ComponentTreeStructure::ROOT_UUID => [['uuid' => 'uuid-in-top-level1', 'component' => 'sdc+xb_test_sdc+props-slots']],
          'uuid-in-top-level1' => [
            'the_body' => [
              ['uuid' => 'uuid-in-top-level2', 'component' => 'sdc+xb_test_sdc+props-slots'],
            ],
          ],
          'uuid-in-top-level2' => [
            'the_body' => [
              ['uuid' => 'uuid-under-top-level2', 'component' => 'sdc+xb_test_sdc+props-no-slots'],
            ],
          ],
        ],
        [],
      ],
      'INVALID: valid tree, with unknown/dangling branch' => [
        [
          ComponentTreeStructure::ROOT_UUID => [
            ['uuid' => 'uuid-in-root-only', 'component' => 'sdc+xb_test_sdc+props-no-slots'],
            ['uuid' => 'uuid-in-root-and-top-level', 'component' => 'sdc+xb_test_sdc+props-slots'],
          ],
          'uuid-in-root-and-top-level' => [
            'the_body' => [
              ['uuid' => 'uuid-1st-tier-top-level', 'component' => 'sdc+xb_test_sdc+props-slots'],
            ],
          ],
          'uuid-1st-tier-top-level' => [
            'the_body' => [
              ['uuid' => 'uuid-under-1st-tier', 'component' => 'sdc+xb_test_sdc+props-no-slots'],
            ],
          ],
          'uuid-unknown-top-level' => [
            'the_body' => [
              ['uuid' => 'uuid-under-top-level', 'component' => 'sdc+xb_test_sdc+props-no-slots'],
            ],
          ],
        ],
        [
          '[uuid-unknown-top-level]' => 'Dangling component subtree. This component subtree claims to be for a component instance with UUID <em class="placeholder">uuid-unknown-top-level</em>, but no such component instance can be found.',
        ],
      ],
      'INVALID: valid tree, with unknown components' => [
        [
          ComponentTreeStructure::ROOT_UUID => [
            ['uuid' => 'uuid-in-top-level-1', 'component' => 'sdc+xb_test_sdc+missing-component-1'],
            ['uuid' => 'uuid-in-top-level-2', 'component' => 'sdc+xb_test_sdc+missing-component-1'],
            ['uuid' => 'uuid-in-top-level-3', 'component' => 'sdc+xb_test_sdc+props-no-slots'],
          ],
          'uuid-unknown-top-level-1' => [
            'the_body' => [
              ['uuid' => 'uuid-under-top-level-1', 'component' => 'sdc+xb_test_sdc+missing-component-1'],
              ['uuid' => 'uuid-under-top-level-2', 'component' => 'sdc+xb_test_sdc+missing-component-2'],
              ['uuid' => 'uuid-under-top-level-3', 'component' => 'sdc+xb_test_sdc+missing-component-2'],
              ['uuid' => 'uuid-under-top-level-4', 'component' => 'sdc+xb_test_sdc+props-no-slots'],
            ],
          ],
        ],
        [
          '[' . ComponentTreeStructure::ROOT_UUID . '][0]' => 'The component <em class="placeholder">sdc+xb_test_sdc+missing-component-1</em> does not exist.',
          '[' . ComponentTreeStructure::ROOT_UUID . '][1]' => 'The component <em class="placeholder">sdc+xb_test_sdc+missing-component-1</em> does not exist.',
          '[uuid-unknown-top-level-1][the_body][0]' => 'The component <em class="placeholder">sdc+xb_test_sdc+missing-component-1</em> does not exist.',
          '[uuid-unknown-top-level-1][the_body][1]' => 'The component <em class="placeholder">sdc+xb_test_sdc+missing-component-2</em> does not exist.',
          '[uuid-unknown-top-level-1][the_body][2]' => 'The component <em class="placeholder">sdc+xb_test_sdc+missing-component-2</em> does not exist.',
          // Even though 'xb_test_sdc:missing-component-2' is under 'uuid-unknown-top-level-1' which is not in the tree, it should still be validated.
          '[uuid-unknown-top-level-1]' => 'Dangling component subtree. This component subtree claims to be for a component instance with UUID <em class="placeholder">uuid-unknown-top-level-1</em>, but no such component instance can be found.',
        ],
      ],
    ];
  }

}
