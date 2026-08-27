<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\Plugin\Validation;

use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItemList;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItemListInstantiatorTrait;
use Drupal\canvas\Plugin\Validation\Constraint\ComponentTreeStructureConstraint;
use Drupal\canvas\Plugin\Validation\Constraint\ComponentTreeStructureConstraintValidator;
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
      'INVALID: uppercase component instance UUID' => [
        [
          [
            'uuid' => '2886421E-4EDE-4BFB-956C-8AFCD4EE8103',
            'component_id' => 'sdc.canvas_test_sdc.props-slots',
            'component_version' => '0e79e884426a53ae',
          ],
        ],
        [
          'layout.0.uuid' => 'Invalid component tree item with UUID <em class="placeholder">2886421E-4EDE-4BFB-956C-8AFCD4EE8103</em>. UUIDs must be lowercase.',
        ],
      ],
      'INVALID: parent_uuid differing only in case from its parent' => [
        [
          [
            'uuid' => '2886421e-4ede-4bfb-956c-8afcd4ee8103',
            'component_id' => 'sdc.canvas_test_sdc.props-slots',
            'component_version' => '0e79e884426a53ae',
          ],
          [
            'uuid' => '80bf49ec-3d3f-4e76-98ed-2ce147397643',
            'component_id' => 'sdc.canvas_test_sdc.props-no-slots',
            'component_version' => 'd34b93534777207a',
            'parent_uuid' => '2886421E-4EDE-4BFB-956C-8AFCD4EE8103',
            'slot' => 'the_body',
          ],
        ],
        [
          'layout.1.parent_uuid' => 'Invalid component tree item with UUID <em class="placeholder">80bf49ec-3d3f-4e76-98ed-2ce147397643</em>. Its parent_uuid <em class="placeholder">2886421E-4EDE-4BFB-956C-8AFCD4EE8103</em> must be lowercase.',
        ],
      ],
      'INVALID: slot without parent_uuid' => [
        [
          [
            'uuid' => 'ad51078a-d1d5-4385-8693-2beaefcf30bf',
            'component_id' => 'sdc.canvas_test_sdc.props-no-slots',
            'component_version' => 'd34b93534777207a',
            'slot' => 'the_body',
          ],
        ],
        [
          'layout.0.slot' => 'Invalid component tree item with UUID <em class="placeholder">ad51078a-d1d5-4385-8693-2beaefcf30bf</em>. A parent UUID must be present if a slot name is provided.',
        ],
      ],
      'INVALID: parent cycle of length 2' => [
        [
          [
            'uuid' => '585b6cbc-0f17-4a37-a89f-92b0716087b7',
            'component_id' => 'sdc.canvas_test_sdc.props-slots',
            'component_version' => '0e79e884426a53ae',
            'parent_uuid' => 'ca6ed05b-2ffd-4462-9497-922e2c30d0f9',
            'slot' => 'the_body',
          ],
          [
            'uuid' => 'ca6ed05b-2ffd-4462-9497-922e2c30d0f9',
            'component_id' => 'sdc.canvas_test_sdc.props-slots',
            'component_version' => '0e79e884426a53ae',
            'parent_uuid' => '585b6cbc-0f17-4a37-a89f-92b0716087b7',
            'slot' => 'the_body',
          ],
        ],
        [
          'layout.0.parent_uuid' => 'Invalid component tree item with UUID <em class="placeholder">585b6cbc-0f17-4a37-a89f-92b0716087b7</em> is part of a cycle: it is an ancestor of its own parent <em class="placeholder">ca6ed05b-2ffd-4462-9497-922e2c30d0f9</em>.',
          'layout.1.parent_uuid' => 'Invalid component tree item with UUID <em class="placeholder">ca6ed05b-2ffd-4462-9497-922e2c30d0f9</em> is part of a cycle: it is an ancestor of its own parent <em class="placeholder">585b6cbc-0f17-4a37-a89f-92b0716087b7</em>.',
        ],
      ],
      'INVALID: parent cycle of length 3, with a descendant hanging off the cycle' => [
        [
          [
            'uuid' => 'b7fbf5ef-fee9-4b09-bd35-4ef1ba52b16d',
            'component_id' => 'sdc.canvas_test_sdc.props-slots',
            'component_version' => '0e79e884426a53ae',
            'parent_uuid' => 'e63661e8-a875-4c4c-a25b-3f37bf2926de',
            'slot' => 'the_body',
          ],
          [
            'uuid' => 'e63661e8-a875-4c4c-a25b-3f37bf2926de',
            'component_id' => 'sdc.canvas_test_sdc.props-slots',
            'component_version' => '0e79e884426a53ae',
            'parent_uuid' => '30a0356d-e35d-4b5c-a5eb-fcbc417f43ac',
            'slot' => 'the_footer',
          ],
          [
            'uuid' => '30a0356d-e35d-4b5c-a5eb-fcbc417f43ac',
            'component_id' => 'sdc.canvas_test_sdc.props-slots',
            'component_version' => '0e79e884426a53ae',
            'parent_uuid' => 'b7fbf5ef-fee9-4b09-bd35-4ef1ba52b16d',
            'slot' => 'the_colophon',
          ],
          // A descendant of a cycle member, but not part of the cycle itself:
          // must not be flagged — it becomes renderable once the cycle is
          // broken.
          [
            'uuid' => '80c9e02b-6cd6-4e2c-b28c-a5e33b0323ff',
            'component_id' => 'sdc.canvas_test_sdc.props-no-slots',
            'component_version' => 'd34b93534777207a',
            'parent_uuid' => 'b7fbf5ef-fee9-4b09-bd35-4ef1ba52b16d',
            'slot' => 'the_body',
          ],
        ],
        [
          'layout.0.parent_uuid' => 'Invalid component tree item with UUID <em class="placeholder">b7fbf5ef-fee9-4b09-bd35-4ef1ba52b16d</em> is part of a cycle: it is an ancestor of its own parent <em class="placeholder">e63661e8-a875-4c4c-a25b-3f37bf2926de</em>.',
          'layout.1.parent_uuid' => 'Invalid component tree item with UUID <em class="placeholder">e63661e8-a875-4c4c-a25b-3f37bf2926de</em> is part of a cycle: it is an ancestor of its own parent <em class="placeholder">30a0356d-e35d-4b5c-a5eb-fcbc417f43ac</em>.',
          'layout.2.parent_uuid' => 'Invalid component tree item with UUID <em class="placeholder">30a0356d-e35d-4b5c-a5eb-fcbc417f43ac</em> is part of a cycle: it is an ancestor of its own parent <em class="placeholder">b7fbf5ef-fee9-4b09-bd35-4ef1ba52b16d</em>.',
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
          'layout.0.uuid' => 'Invalid component tree item with UUID <em class="placeholder">8d2e68e5-fd4a-47dc-a641-06062723525d</em>. This UUID is used by <em class="placeholder">2</em> component instances in this component tree; each component instance must have a unique UUID.',
          'layout.1.uuid' => 'Invalid component tree item with UUID <em class="placeholder">8d2e68e5-fd4a-47dc-a641-06062723525d</em>. This UUID is used by <em class="placeholder">2</em> component instances in this component tree; each component instance must have a unique UUID.',
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
      'INVALID: reserved root UUID used as component instance UUID' => [
        [
          [
            'uuid' => ComponentTreeItemList::ROOT_UUID,
            'component_id' => 'sdc.canvas_test_sdc.props-no-slots',
            'component_version' => 'd34b93534777207a',
          ],
        ],
        [
          'layout.0.uuid' => 'Invalid component tree item with UUID <em class="placeholder">' . ComponentTreeItemList::ROOT_UUID . '</em>. This UUID is reserved to represent the root of the component tree, and must never be used by a component instance.',
        ],
      ],
      'INVALID: reserved root UUID used as component instance UUID (uppercase)' => [
        [
          [
            'uuid' => \strtoupper(ComponentTreeItemList::ROOT_UUID),
            'component_id' => 'sdc.canvas_test_sdc.props-no-slots',
            'component_version' => 'd34b93534777207a',
          ],
        ],
        [
          'layout.0.uuid' => 'Invalid component tree item with UUID <em class="placeholder">' . \strtoupper(ComponentTreeItemList::ROOT_UUID) . '</em>. This UUID is reserved to represent the root of the component tree, and must never be used by a component instance.',
        ],
      ],
      'INVALID: reserved root UUID used as parent_uuid' => [
        [
          [
            'uuid' => '7f4c4a09-3013-4b86-9d4f-27dbcd0078b4',
            'component_id' => 'sdc.canvas_test_sdc.props-slots',
            'component_version' => '0e79e884426a53ae',
          ],
          [
            'uuid' => '0f7dbcc5-0ea7-4b3d-96eb-b322b1f95522',
            'component_id' => 'sdc.canvas_test_sdc.props-no-slots',
            'component_version' => 'd34b93534777207a',
            'parent_uuid' => ComponentTreeItemList::ROOT_UUID,
            'slot' => 'the_body',
          ],
        ],
        [
          'layout.1.parent_uuid' => 'Invalid component tree item with UUID <em class="placeholder">0f7dbcc5-0ea7-4b3d-96eb-b322b1f95522</em> references the reserved root UUID as its parent. Component instances at the root of the tree must omit parent_uuid and slot.',
        ],
      ],
    ];
  }

}
