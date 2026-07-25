<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\Plugin\Validation;

use Drupal\canvas\Entity\Component;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItemListInstantiatorTrait;
use Drupal\canvas\Plugin\Validation\Constraint\ComponentTreeStructureConstraint;
use Drupal\canvas\Plugin\Validation\Constraint\ComponentTreeStructureConstraintValidator;
use Drupal\canvas\SlotRestrictions;
use Drupal\Core\Config\Entity\ConfigEntityStorageInterface;
use Drupal\Core\Validation\BasicRecursiveValidatorFactory;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\canvas\Traits\ConstraintViolationsTestTrait;
use Drupal\Tests\canvas\Traits\GenerateComponentConfigTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests Drupal\canvas\Plugin\Validation\Constraint\ComponentTreeStructureConstraintValidator.
 */
#[RunTestsInSeparateProcesses]
#[CoversClass(ComponentTreeStructureConstraintValidator::class)]
#[Group('canvas')]
final class ComponentTreeStructureConstraintValidatorTest extends CanvasKernelTestBase {

  use ConstraintViolationsTestTrait;
  use GenerateComponentConfigTrait;
  use ComponentTreeItemListInstantiatorTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    // Provides the SDCs whose slots declare restrictions.
    // @see \Drupal\canvas\SlotRestrictions
    'canvas_test_slot_restrictions',
  ];

  private const CONTAINER = 'sdc.canvas_test_slot_restrictions.restricted-container';
  private const TAGGED_CHILD = 'sdc.canvas_test_slot_restrictions.restricted-child';
  private const NAMED_CHILD = 'sdc.canvas_test_sdc.props-no-slots';
  private const OTHER_CHILD = 'sdc.canvas_test_sdc.props-slots';

  /**
 * Tests validation.
 */
  #[DataProvider('providerValidation')]
  public function testValidation(array $items, array $expected_violations): void {
    $this->generateComponentConfig();
    $validator = \Drupal::service(BasicRecursiveValidatorFactory::class)->createValidator();
    $violations = $validator->validate($items, new ComponentTreeStructureConstraint(['basePropertyPath' => 'layout']));
    $this->assertSame($expected_violations, self::violationsToArray($violations));
  }

  /**
 * Tests validation item list.
 */
  #[DataProvider('providerValidationItemList')]
  public function testValidationItemList(array $items, array $expected_violations): void {
    $this->generateComponentConfig();
    $item_list = $this->createDanglingComponentTreeItemList();
    $item_list->setValue($items);
    $validator = \Drupal::service(BasicRecursiveValidatorFactory::class)->createValidator();
    $violations = $validator->validate($item_list, new ComponentTreeStructureConstraint(['basePropertyPath' => 'layout']));
    $this->assertSame($expected_violations, self::violationsToArray($violations));
  }

  public static function providerValidationItemList(): array {
    $cases = self::providerValidation();
    // Setting these very invalid cases into a field item causes some
    // manipulation to match the defined properties so the error messages are
    // slightly different.
    $cases['INVALID: component instance keys wrong, string instead of arrays'][1] = [
      'layout.0.uuid' => 'This value should not be blank.',
      'layout.1.uuid' => 'This value should not be blank.',
      'layout.1.component_id' => 'This value should not be blank.',
      'layout.1.component_version' => 'This value should not be blank.',
      'layout.2.uuid' => 'This value should not be blank.',
      'layout.2.component_id' => 'This value should not be blank.',
      'layout.2.component_version' => 'This value should not be blank.',
      'layout.3.uuid' => 'This value should not be blank.',
      'layout.3.component_id' => 'This value should not be blank.',
      'layout.3.component_version' => 'This value should not be blank.',
    ];
    $cases['INVALID: no uuid, version or component_id'][1] = [
      'layout.0.uuid' => 'This value should not be blank.',
      'layout.0.component_id' => 'This value should not be blank.',
      'layout.0.component_version' => 'This value should not be blank.',
    ];
    return $cases;
  }

  public static function providerValidation(): array {
    return [
      'INVALID: component instance keys wrong, string instead of arrays' => [
        [
            ['component_id' => 'sdc.canvas_test_sdc.props-slots'],
            ['wrong-key' => 'a value'],
          "string",
          'uuid-in-root' => [
            'the_body' => [
                ['wrong-key' => 'a value'],
              "string",
            ],
            "string",
          ],
        ],
        [
          'layout.0.uuid' => 'This field is missing.',
          'layout.0.component_version' => 'This field is missing.',
          'layout.1.uuid' => 'This field is missing.',
          'layout.1.component_id' => 'This field is missing.',
          'layout.1.component_version' => 'This field is missing.',
          // TRICKY: this is due to a bug in \Drupal\Core\Validation\DrupalTranslator::trans() — it should replace `{{ … }}` in the message.
          'layout.2' => 'This value should be of type {{ type }}.',
          'layout.uuid-in-root.uuid' => 'This field is missing.',
          'layout.uuid-in-root.component_id' => 'This field is missing.',
          'layout.uuid-in-root.component_version' => 'This field is missing.',
        ],
      ],
      'INVALID: no uuid, version or component_id' => [
        ['other-uuid' => []],
        [
          'layout.other-uuid.uuid' => 'This field is missing.',
          'layout.other-uuid.component_id' => 'This field is missing.',
          'layout.other-uuid.component_version' => 'This field is missing.',
        ],
      ],
      'VALID: only root' => [
        [],
        [],
      ],
      'VALID: valid tree, only root, component has slots but empty' => [
        [
          [
            'uuid' => '2886421e-4ede-4bfb-956c-8afcd4ee8103',
            'component_id' => 'sdc.canvas_test_sdc.props-slots',
            'component_version' => '0e79e884426a53ae',
          ],
        ],
        [],
      ],
      'VALID: valid tree, with top level, component has slots, slots have correct names' => [
        [
          [
            'uuid' => '2886421e-4ede-4bfb-956c-8afcd4ee8103',
            'component_id' => 'sdc.canvas_test_sdc.props-slots',
            'component_version' => '0e79e884426a53ae',
          ],
          [
            'parent_uuid' => '2886421e-4ede-4bfb-956c-8afcd4ee8103',
            'slot' => 'the_body',
            'uuid' => '80bf49ec-3d3f-4e76-98ed-2ce147397643',
            'component_id' => 'sdc.canvas_test_sdc.props-no-slots',
            'component_version' => 'd34b93534777207a',
          ],
        ],
        [],
      ],
      'INVALID: valid tree, with top level, component has slots, used 3x, 2x with slots have wrong names' => [
        [
          [
            'uuid' => '80bf49ec-3d3f-4e76-98ed-2ce147397643',
            'component_id' => 'sdc.canvas_test_sdc.props-slots',
            'component_version' => '0e79e884426a53ae',
          ],
          [
            'uuid' => 'bcf003b2-a81b-48b6-bb4c-772814edaa2a',
            'component_id' => 'sdc.canvas_test_sdc.props-slots',
            'component_version' => '0e79e884426a53ae',
          ],

          [
            'uuid' => '5067ea49-f893-4d9a-8587-6586e459bd6c',
            'component_id' => 'sdc.canvas_test_sdc.props-slots',
            'parent_uuid' => '50330afa-a840-4527-bc37-5921d99addf1',
            'slot' => 'the_body',
            'component_version' => '0e79e884426a53ae',
          ],
          [
            'uuid' => '9b654898-2e58-4d3a-a160-bfde52796a11',
            'component_id' => 'sdc.canvas_test_sdc.props-no-slots',
            'parent_uuid' => 'bcf003b2-a81b-48b6-bb4c-772814edaa2a',
            'slot' => 'slot1',
            'component_version' => 'd34b93534777207a',
          ],
          [
            'uuid' => 'e685308a-0d0f-44dd-830d-1ec7731810e7',
            'component_id' => 'sdc.canvas_test_sdc.props-no-slots',
            'parent_uuid' => 'bcf003b2-a81b-48b6-bb4c-772814edaa2a',
            'slot' => 'slot2',
            'component_version' => 'd34b93534777207a',
          ],
          [
            'uuid' => '8bc0f436-1930-4a25-b891-632e55d07e27',
            'component_id' => 'sdc.canvas_test_sdc.props-no-slots',
            'parent_uuid' => 'bcf003b2-a81b-48b6-bb4c-772814edaa2a',
            'slot' => 'the_body',
            'component_version' => 'd34b93534777207a',
          ],
          [
            'uuid' => '0df965c3-dda3-44a0-b3bb-b3dcd62a6817',
            'component_id' => 'sdc.canvas_test_sdc.props-no-slots',
            'parent_uuid' => '8bc0f436-1930-4a25-b891-632e55d07e27',
            'slot' => 'slot3',
            'component_version' => 'd34b93534777207a',
          ],
        ],
        [
          'layout.2.parent_uuid' => 'Invalid component tree item with UUID <em class="placeholder">5067ea49-f893-4d9a-8587-6586e459bd6c</em> references an invalid parent <em class="placeholder">50330afa-a840-4527-bc37-5921d99addf1</em>.',
          'layout.3.slot' => 'Invalid component subtree. This component subtree contains an invalid slot name for component <em class="placeholder">sdc.canvas_test_sdc.props-slots</em>: <em class="placeholder">slot1</em>. Valid slot names are: <em class="placeholder">the_body, the_footer, the_colophon</em>.',
          'layout.4.slot' => 'Invalid component subtree. This component subtree contains an invalid slot name for component <em class="placeholder">sdc.canvas_test_sdc.props-slots</em>: <em class="placeholder">slot2</em>. Valid slot names are: <em class="placeholder">the_body, the_footer, the_colophon</em>.',
          'layout.6.parent_uuid' => 'Invalid component subtree. A component subtree must only exist for components with >=1 slot, but the component <em class="placeholder">sdc.canvas_test_sdc.props-no-slots</em> has no slots, yet a subtree exists for the instance with UUID <em class="placeholder">8bc0f436-1930-4a25-b891-632e55d07e27</em>.',
        ],
      ],
      'INVALID: valid tree, with top level, under own branch' => [
        [
          [
            'uuid' => 'ad51078a-d1d5-4385-8693-2beaefcf30bf',
            'component_id' => 'sdc.canvas_test_sdc.props-no-slots',
            'component_version' => 'd34b93534777207a',
          ],
          [
            'uuid' => 'f67147cb-be50-459a-915d-34d8646012f4',
            'component_id' => 'sdc.canvas_test_sdc.props-slots',
            'parent_uuid' => 'f67147cb-be50-459a-915d-34d8646012f4',
            'slot' => 'the_body',
            'component_version' => '0e79e884426a53ae',
          ],
        ],
        [
          'layout.1.parent_uuid' => 'Invalid component tree item with UUID <em class="placeholder">f67147cb-be50-459a-915d-34d8646012f4</em> claims to be parent of itself.',
        ],
      ],
      'VALID: valid tree, multiple levels' => [
        [
          [
            'uuid' => '8d2e68e5-fd4a-47dc-a641-06062723525d',
            'component_id' => 'sdc.canvas_test_sdc.props-slots',
            'component_version' => '0e79e884426a53ae',
          ],
          [
            'uuid' => 'a022682d-d94b-4f66-bfad-034f0eba5906',
            'component_id' => 'sdc.canvas_test_sdc.props-slots',
            'parent_uuid' => '8d2e68e5-fd4a-47dc-a641-06062723525d',
            'slot' => 'the_body',
            'component_version' => '0e79e884426a53ae',
          ],
          [
            'uuid' => 'ffa4aa03-2bba-4d9b-81d7-37a412836838',
            'component_id' => 'sdc.canvas_test_sdc.props-no-slots',
            'parent_uuid' => '8d2e68e5-fd4a-47dc-a641-06062723525d',
            'slot' => 'the_body',
            'component_version' => 'd34b93534777207a',
          ],
        ],
        [],
      ],
      'INVALID: duplicate UUID' => [
        [
          [
            'uuid' => '8d2e68e5-fd4a-47dc-a641-06062723525d',
            'component_id' => 'sdc.canvas_test_sdc.props-slots',
            'component_version' => '0e79e884426a53ae',
          ],
          [
            'uuid' => '8d2e68e5-fd4a-47dc-a641-06062723525d',
            'component_id' => 'sdc.canvas_test_sdc.props-slots',
            'component_version' => '0e79e884426a53ae',
          ],
        ],
        [
          'layout' => 'Not all component instance UUIDs in this component tree are unique.',
        ],
      ],
      'INVALID: valid tree, with unknown parent' => [
        [
          [
            'uuid' => '01703ce1-3eaa-4171-91d9-5b6fe22da2af',
            'component_id' => 'sdc.canvas_test_sdc.props-no-slots',
            'component_version' => 'd34b93534777207a',
          ],
          [
            'uuid' => 'cffc81cb-df7e-4481-83eb-d3ea71bba987',
            'component_id' => 'sdc.canvas_test_sdc.props-slots',
            'component_version' => '0e79e884426a53ae',
          ],
          [
            'uuid' => 'd823d3c9-be9f-4053-8bc9-ad36914c345c',
            'component_id' => 'sdc.canvas_test_sdc.props-slots',
            'parent_uuid' => 'cffc81cb-df7e-4481-83eb-d3ea71bba987',
            'slot' => 'the_body',
            'component_version' => '0e79e884426a53ae',
          ],
          [
            'uuid' => '357963ff-2eed-4e34-b768-0517cfb52207',
            'component_id' => 'sdc.canvas_test_sdc.props-no-slots',
            'parent_uuid' => 'd823d3c9-be9f-4053-8bc9-ad36914c345c',
            'slot' => 'the_body',
            'component_version' => 'd34b93534777207a',
          ],
          [
            'uuid' => 'aa595654-57c9-463b-ad33-61f47dc7049b',
            'component_id' => 'sdc.canvas_test_sdc.props-no-slots',
            'parent_uuid' => '7e090562-0f3b-4bec-8e43-f19e7408a4d9',
            'slot' => 'the_body',
            'component_version' => 'd34b93534777207a',
          ],
        ],
        [
          'layout.4.parent_uuid' => 'Invalid component tree item with UUID <em class="placeholder">aa595654-57c9-463b-ad33-61f47dc7049b</em> references an invalid parent <em class="placeholder">7e090562-0f3b-4bec-8e43-f19e7408a4d9</em>.',
        ],
      ],
      'INVALID: valid tree, with parent but not slot' => [
        [
          [
            'uuid' => '01703ce1-3eaa-4171-91d9-5b6fe22da2af',
            'component_id' => 'sdc.canvas_test_sdc.props-no-slots',
            'component_version' => 'd34b93534777207a',
          ],
          [
            'uuid' => 'd823d3c9-be9f-4053-8bc9-ad36914c345c',
            'component_id' => 'sdc.canvas_test_sdc.props-slots',
            'parent_uuid' => '01703ce1-3eaa-4171-91d9-5b6fe22da2af',
            'component_version' => '0e79e884426a53ae',
          ],
        ],
        [
          'layout.1.slot' => 'Invalid component tree item with UUID <em class="placeholder">d823d3c9-be9f-4053-8bc9-ad36914c345c</em>. A slot name must be present if a parent uuid is provided.',
        ],
      ],
      'INVALID: child references parent with invalid component_version' => [
        [
          [
            'uuid' => '2886421e-4ede-4bfb-956c-8afcd4ee8103',
            'component_id' => 'sdc.canvas_test_sdc.props-slots',
            'component_version' => 'bad-version',
          ],
          [
            'uuid' => '80bf49ec-3d3f-4e76-98ed-2ce147397643',
            'component_id' => 'sdc.canvas_test_sdc.props-no-slots',
            'component_version' => 'd34b93534777207a',
            'parent_uuid' => '2886421e-4ede-4bfb-956c-8afcd4ee8103',
            'slot' => 'the_body',
          ],
          // No additional validation errors for the invalid version, no matter how many children that component instance contains.
          [
            'uuid' => '80bf49ec-3d3f-4e76-98ed-2ce147397644',
            'component_id' => 'sdc.canvas_test_sdc.props-no-slots',
            'component_version' => 'd34b93534777207a',
            'parent_uuid' => '2886421e-4ede-4bfb-956c-8afcd4ee8103',
            'slot' => 'the_body',
          ],
        ],
        [
          'layout.0.component_version' => "'bad-version' is not a version that exists on component config entity 'sdc.canvas_test_sdc.props-slots'. Available versions: '0e79e884426a53ae'.",
        ],
      ],
      'INVALID: valid tree, with unknown components' => [
        [
          [
            'uuid' => '80bf49ec-3d3f-4e76-98ed-2ce147397643',
            'component_id' => 'sdc.canvas_test_sdc.missing-component-1',
            'component_version' => 'irrelevant',
          ],
          [
            'uuid' => 'bcf003b2-a81b-48b6-bb4c-772814edaa2a',
            'component_id' => 'sdc.canvas_test_sdc.missing-component-1',
            'component_version' => 'irrelevant',
          ],
          [
            'uuid' => '50330afa-a840-4527-bc37-5921d99addf1-3',
            'component_id' => 'sdc.canvas_test_sdc.props-no-slots',
            'component_version' => 'd34b93534777207a',
          ],
          [
            'uuid' => '9b654898-2e58-4d3a-a160-bfde52796a11',
            'component_id' => 'sdc.canvas_test_sdc.missing-component-1',
            'parent_uuid' => '1be63e02-d343-4d67-a1fe-7fa533fba2c6',
            'slot' => 'the_body',
            'component_version' => 'irrelevant',
          ],
          [
            'uuid' => 'e685308a-0d0f-44dd-830d-1ec7731810e7',
            'component_id' => 'sdc.canvas_test_sdc.missing-component-2',
            'parent_uuid' => '1be63e02-d343-4d67-a1fe-7fa533fba2c6',
            'slot' => 'the_body',
            'component_version' => 'irrelevant',
          ],
          [
            'uuid' => '8bc0f436-1930-4a25-b891-632e55d07e27',
            'component_id' => 'sdc.canvas_test_sdc.missing-component-2',
            'parent_uuid' => '1be63e02-d343-4d67-a1fe-7fa533fba2c6',
            'slot' => 'the_body',
            'component_version' => 'irrelevant',
          ],
          [
            'uuid' => '9b6a4cf9-e707-48a1-babf-cb726b86726a',
            'component_id' => 'sdc.canvas_test_sdc.props-no-slots',
            'parent_uuid' => '1be63e02-d343-4d67-a1fe-7fa533fba2c6',
            'slot' => 'the_body',
            'component_version' => 'd34b93534777207a',
          ],
        ],
        [
          'layout.0.component_id' => "The 'canvas.component.sdc.canvas_test_sdc.missing-component-1' config does not exist.",
          'layout.1.component_id' => "The 'canvas.component.sdc.canvas_test_sdc.missing-component-1' config does not exist.",
          'layout.2.uuid' => 'This is not a valid UUID.',
          'layout.3.component_id' => "The 'canvas.component.sdc.canvas_test_sdc.missing-component-1' config does not exist.",
          'layout.4.component_id' => "The 'canvas.component.sdc.canvas_test_sdc.missing-component-2' config does not exist.",
          'layout.5.component_id' => "The 'canvas.component.sdc.canvas_test_sdc.missing-component-2' config does not exist.",
          'layout.6.parent_uuid' => 'Invalid component tree item with UUID <em class="placeholder">9b6a4cf9-e707-48a1-babf-cb726b86726a</em> references an invalid parent <em class="placeholder">1be63e02-d343-4d67-a1fe-7fa533fba2c6</em>.',
        ],
      ],
    ];
  }

  /**
   * Tests the slot restrictions declared by component metadata.
   *
   * @see \Drupal\canvas\SlotRestrictions
   * @see https://www.drupal.org/i/3514072
   */
  #[DataProvider('providerSlotRestrictions')]
  public function testSlotRestrictions(string $slot, array $children, array $expected_violations): void {
    $this->generateComponentConfig();
    $items = [self::treeItem('11111111-1111-4111-8111-111111111111', self::CONTAINER)];
    foreach ($children as $index => $child_component_id) {
      $items[] = self::treeItem(
        \sprintf('2222222%d-2222-4222-8222-222222222222', $index),
        $child_component_id,
        '11111111-1111-4111-8111-111111111111',
        $slot,
      );
    }
    $validator = \Drupal::service(BasicRecursiveValidatorFactory::class)->createValidator();
    $violations = $validator->validate($items, new ComponentTreeStructureConstraint(['basePropertyPath' => 'layout']));
    $this->assertSame($expected_violations, self::violationsToArray($violations));
  }

  public static function providerSlotRestrictions(): \Generator {
    yield 'VALID: `expected` entry naming an SDC plugin ID' => [
      'items',
      [self::NAMED_CHILD],
      [],
    ];
    yield 'VALID: `expected` entry matching a component tag' => [
      'items',
      [self::TAGGED_CHILD],
      [],
    ];
    yield 'VALID: exactly `maxItems` children' => [
      'items',
      [self::NAMED_CHILD, self::TAGGED_CHILD],
      [],
    ];
    yield 'VALID: a slot declaring no restrictions accepts anything' => [
      'anything',
      [self::OTHER_CHILD, self::OTHER_CHILD, self::OTHER_CHILD],
      [],
    ];
    yield 'VALID: `expected` that resolves to nothing fails open' => [
      'typo',
      [self::OTHER_CHILD],
      [],
    ];
    yield 'INVALID: a component the slot does not expect' => [
      'items',
      [self::OTHER_CHILD],
      [
        'layout.1.slot' => 'Component <em class="placeholder">Canvas test SDC with props and slots</em> is not expected in the <em class="placeholder">Items</em> slot of <em class="placeholder">Canvas test SDC with a restricted slot</em>. Expected: <em class="placeholder">Canvas test SDC with props, no slots, Canvas test SDC matched by tag</em>.',
      ],
    ];
    yield 'INVALID: more children than `maxItems`, reported on the surplus only' => [
      'items',
      [self::NAMED_CHILD, self::NAMED_CHILD, self::NAMED_CHILD],
      [
        'layout.3.slot' => 'The <em class="placeholder">Items</em> slot of <em class="placeholder">Canvas test SDC with a restricted slot</em> accepts at most 2 components, but 3 were provided.',
      ],
    ];
  }

  /**
   * Tests that a violation the stored tree already contains is not reported.
   *
   * Adding or narrowing a restriction on a component that is already in use may
   * not make existing content unpublishable, so only the violations a write
   * introduces are reported. Moving an instance re-evaluates it.
   *
   * @see \Drupal\canvas\SlotRestrictions::violations()
   */
  public function testPreExistingViolationsAreGrandfathered(): void {
    $this->generateComponentConfig();
    $component_storage = \Drupal::entityTypeManager()->getStorage('component');
    \assert($component_storage instanceof ConfigEntityStorageInterface);
    $stored = [
      self::treeItem('11111111-1111-4111-8111-111111111111', self::CONTAINER),
      self::treeItem('22222222-2222-4222-8222-222222222222', self::OTHER_CHILD, '11111111-1111-4111-8111-111111111111', 'items'),
    ];
    $stored_violations = SlotRestrictions::violations($stored, $component_storage);
    $this->assertCount(1, $stored_violations);

    // Saving the same tree again introduces nothing: the violation is already
    // there, so the diff against the stored tree is empty.
    $this->assertSame([], \array_diff_key(
      SlotRestrictions::violations($stored, $component_storage),
      $stored_violations,
    ));

    // Moving the offending instance to another slot re-evaluates it, because
    // the violation is keyed by the placement and not just by the component.
    $moved = $stored;
    $moved[1]['slot'] = 'items';
    $moved[1]['parent_uuid'] = '33333333-3333-4333-8333-333333333333';
    $moved[] = self::treeItem('33333333-3333-4333-8333-333333333333', self::CONTAINER);
    $this->assertCount(1, \array_diff_key(
      SlotRestrictions::violations($moved, $component_storage),
      $stored_violations,
    ));
  }

  /**
   * Builds one component tree item, resolving the component's active version.
   */
  private static function treeItem(string $uuid, string $component_id, ?string $parent_uuid = NULL, ?string $slot = NULL): array {
    $component = Component::load($component_id);
    \assert($component instanceof Component);
    return \array_filter([
      'uuid' => $uuid,
      'component_id' => $component_id,
      'component_version' => $component->getActiveVersion(),
      'parent_uuid' => $parent_uuid,
      'slot' => $slot,
    ], static fn (?string $value): bool => $value !== NULL);
  }

}
