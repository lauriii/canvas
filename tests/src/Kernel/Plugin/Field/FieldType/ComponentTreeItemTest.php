<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Kernel\Plugin\Field\FieldType;

use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\experience_builder\Entity\Component;
use Drupal\experience_builder\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\experience_builder\Plugin\Field\FieldType\ComponentTreeItemListInstantiatorTrait;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\experience_builder\Kernel\Traits\CiModulePathTrait;
use Drupal\Tests\experience_builder\Traits\SingleDirectoryComponentTreeTestTrait;
use Drupal\Tests\experience_builder\Traits\ConstraintViolationsTestTrait;
use Drupal\Tests\experience_builder\Traits\ContribStrictConfigSchemaTestTrait;
use Drupal\Tests\experience_builder\Traits\GenerateComponentConfigTrait;
use Drupal\Tests\image\Kernel\ImageFieldCreationTrait;

/**
 * @coversDefaultClass \Drupal\experience_builder\Plugin\Field\FieldType\ComponentTreeItem
 * @group experience_builder
 * @group #slow
 */
class ComponentTreeItemTest extends KernelTestBase {

  use SingleDirectoryComponentTreeTestTrait;
  use ComponentTreeItemListInstantiatorTrait;
  use ConstraintViolationsTestTrait;
  use ContribStrictConfigSchemaTestTrait;
  use GenerateComponentConfigTrait;
  use CiModulePathTrait;
  use ImageFieldCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'experience_builder',
    'sdc',
    'sdc_test',
    'xb_test_sdc',
    // Dependencies must actually exist.
    'field',
    'user',
    'node',
    // Modules providing field types + widgets for the SDC Components'
    // `prop_field_definitions`.
    'file',
    'image',
    'options',
    'link',
    'system',
    'media',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->generateComponentConfig();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node_type');
    $this->installEntitySchema('node');
  }

  /**
   * @covers ::getParentUuid()
   * @covers ::getParentComponentTreeItem()
   * @covers ::getSlot()
   * @covers ::getComponentId()
   * @covers ::getComponent()
   * @covers ::getUuid()
   */
  public function testConvenienceMethods(): void {
    $root_uuid = '947c196f-f108-43fd-a446-03a08100d579';
    $child_uuid = '8b6b47ec-1167-433b-975d-e2d97739f5a6';

    $this->generateComponentConfig();
    $item_list = $this->createDanglingComponentTreeItemList();
    $item_list->setValue([
      [
        'uuid' => $root_uuid,
        'component_id' => 'sdc.xb_test_sdc.props-slots',
        'inputs' => [
          'heading' => [
            'sourceType' => 'static:field_item:string',
            'value' => 'This is really tricky for a first-timer …',
            'expression' => 'ℹ︎string␟value',
          ],
        ],
      ],
      [
        'parent_uuid' => $root_uuid,
        'slot' => 'the_body',
        'uuid' => $child_uuid,
        'component_id' => 'sdc.xb_test_sdc.props-no-slots',
        'inputs' => [
          'heading' => [
            'sourceType' => 'static:field_item:string',
            'value' => '… but eventually it all makes sense. Wished I RTFMd.',
            'expression' => 'ℹ︎string␟value',
          ],
        ],
      ],
    ]);
    $this->assertCount(0, $item_list->validate());
    $this->assertCount(2, $item_list);

    // Call all convenience methods on the root component instance.
    $root = $item_list->get(0);
    assert($root instanceof ComponentTreeItem);
    $this->assertNull($root->getParentUuid());
    $this->assertNull($root->getParentComponentTreeItem());
    $this->assertNull($root->getSlot());
    $this->assertSame('sdc.xb_test_sdc.props-slots', $root->getComponentId());
    $this->assertInstanceOf(Component::class, $root->getComponent());
    $this->assertSame(Component::load('sdc.xb_test_sdc.props-slots')?->toArray(), $root->getComponent()->toArray());
    $this->assertSame($root_uuid, $root->getUuid());

    // Call all convenience methods on the child component instance.
    $child = $item_list->get(1);
    assert($child instanceof ComponentTreeItem);
    $this->assertSame($root_uuid, $child->getParentUuid());
    $this->assertSame($root, $child->getParentComponentTreeItem());
    $this->assertSame('the_body', $child->getSlot());
    $this->assertSame('sdc.xb_test_sdc.props-no-slots', $child->getComponentId());
    $this->assertInstanceOf(Component::class, $child->getComponent());
    $this->assertSame(Component::load('sdc.xb_test_sdc.props-no-slots')?->toArray(), $child->getComponent()->toArray());
    $this->assertSame($child_uuid, $child->getUuid());
  }

  public function testCalculateDependencies(): void {
    $uuid = $this->container->get('uuid');
    $type = NodeType::create([
      'type' => 'article',
      'name' => 'Article',
    ]);
    $type->save();
    $this->createImageField('field_hero', 'node', 'article', storage_settings: [
      // @todo Remove once https://drupal.org/i/3513317 is fixed.
      // We cannot rely on the override because experience_builder module is not
      // yet installed so need to manually specify it here for testing sake.
      // @see \Drupal\experience_builder\Plugin\Field\FieldTypeOverride\ImageItemOverride::defaultStorageSettings
      'display_default' => TRUE,
    ]);

    $this->assertSame([], ComponentTreeItem::calculateDependencies(BaseFieldDefinition::create('component_tree')));
    $this->assertSame(
      [
        'config' => [
          'experience_builder.component.sdc.experience_builder.image',
          'experience_builder.component.sdc.sdc_test.my-cta',
          'field.field.node.article.field_hero',
          'node.type.article',
        ],
        'content' => [],
        'module' => [
          'node',
        ],
        'theme' => [],
      ],
      ComponentTreeItem::calculateDependencies(BaseFieldDefinition::create('component_tree')
        ->setDefaultValue(
          [
            [
              'uuid' => $uuid->generate(),
              'component_id' => 'sdc.experience_builder.image',
              'inputs' => [
                'image' => [
                  'sourceType' => 'dynamic',
                  'expression' => 'ℹ︎␜entity:node:article␝field_hero␞␟{src↝entity␜␜entity:file␝uri␞␟value,alt↠alt,width↠width,height↠height}',
                ],
              ],
            ],
            [
              'uuid' => $uuid->generate(),
              'component_id' => 'sdc.sdc_test.my-cta',
              'inputs' => [
                'text' => [
                  'sourceType' => 'static:field_item:string',
                  'value' => 'hello, world!',
                  'expression' => 'ℹ︎string␟value',
                ],
                'href' => [
                  'sourceType' => 'static:field_item:uri',
                  'value' => 'https://drupal.org',
                  'expression' => 'ℹ︎uri␟value',
                ],
              ],
            ],
            [
              'uuid' => $uuid->generate(),
              'component_id' => 'sdc.sdc_test.my-cta',
              'inputs' => [
                'text' => [
                  'sourceType' => 'dynamic',
                  'expression' => 'ℹ︎␜entity:node:article␝title␞␟value',
                ],
                'href' => [
                  'sourceType' => 'static:field_item:uri',
                  'value' => 'https://drupal.org',
                  'expression' => 'ℹ︎uri␟value',
                ],
              ],
            ],
            [
              'uuid' => $uuid->generate(),
              'component_id' => 'sdc.sdc_test.my-cta',
              'inputs' => [
                'text' => [
                  'sourceType' => 'dynamic',
                  'expression' => 'ℹ︎␜entity:node:article␝title␞␟value',
                ],
                'href' => [
                  'sourceType' => 'dynamic',
                  'expression' => 'ℹ︎␜entity:node:article␝field_hero␞␟entity␜␜entity:file␝uri␞␟value',
                ],
              ],
            ],
            [
              'uuid' => $uuid->generate(),
              'component_id' => 'sdc.experience_builder.image',
              'inputs' => [
                'image' => [
                  'sourceType' => 'adapter:image_apply_style',
                  'adapterInputs' => [
                    'image' => [
                      'sourceType' => 'dynamic',
                      'expression' => 'ℹ︎␜entity:node:article␝field_hero␞␟{src↝entity␜␜entity:file␝uri␞0␟value,alt↠alt,width↠width,height↠height}',
                    ],
                    'imageStyle' => [
                      'sourceType' => 'static:field_item:string',
                      'value' => 'thumbnail',
                      'expression' => 'ℹ︎string␟value',
                    ],
                  ],
                ],
              ],
            ],
          ],
        ))
    );
  }

  public static function providerInvalidField(): array {
    $test_cases = static::getValidTreeTestCases();
    array_walk($test_cases, fn(array &$test_case) => $test_case[] = []);
    $test_cases = array_merge($test_cases, static::getInvalidTreeTestCases());
    $test_cases['invalid values using dynamic inputs'][] = [
      'field_xb_test.0' => "The 'dynamic' prop source type must be absent.",
    ];
    $test_cases['invalid UUID, missing component_id key'][] = [
      'field_xb_test.0.uuid' => 'This is not a valid UUID.',
      'field_xb_test.0.component_id' => 'This value should not be blank.',
    ];
    $test_cases['missing components, using dynamic inputs'][] = [
      'field_xb_test.0.component_id' => "The 'experience_builder.component.sdc.sdc_test.missing' config does not exist.",
      'field_xb_test.1.component_id' => "The 'experience_builder.component.sdc.sdc_test.missing-also' config does not exist.",
      'field_xb_test.0' => "The 'dynamic' prop source type must be absent.",
      'field_xb_test.1' => "The 'dynamic' prop source type must be absent.",
      'field_xb_test.2' => "The 'dynamic' prop source type must be absent.",
    ];
    $test_cases['missing components, using only static inputs'][] = [
      'field_xb_test.0.component_id' => "The 'experience_builder.component.sdc.sdc_test.missing' config does not exist.",
    ];
    $test_cases['inputs invalid, using dynamic inputs'][] = [
      \sprintf('field_xb_test.0.inputs.%s.heading', self::UUID_DYNAMIC_STATIC_CARD_2) => 'The property heading is required.',
      'field_xb_test.0' => "The 'dynamic' prop source type must be absent.",
      \sprintf('field_xb_test.1.inputs.%s.heading', self::UUID_DYNAMIC_STATIC_CARD_3) => 'The property heading is required.',
      'field_xb_test.1' => "The 'dynamic' prop source type must be absent.",
      'field_xb_test.2' => "The 'dynamic' prop source type must be absent.",
    ];
    $test_cases['inputs invalid, using only static inputs'][] = [
      \sprintf('field_xb_test.0.inputs.%s.heading', self::UUID_DYNAMIC_STATIC_CARD_2) => 'The property heading is required.',
    ];
    $test_cases['missing inputs key'][] = [
      \sprintf('field_xb_test.0.inputs.%s', self::UUID_DYNAMIC_STATIC_CARD_2) => 'The required properties are missing.',
      \sprintf('field_xb_test.1.inputs.%s', self::UUID_DYNAMIC_STATIC_CARD_3) => 'The required properties are missing.',
      \sprintf('field_xb_test.2.inputs.%s', self::UUID_DYNAMIC_STATIC_CARD_4) => 'The required properties are missing.',
    ];
    $test_cases['non unique uuids'][] = [
      'field_xb_test' => 'The UUID should be unique.',
    ];
    $test_cases['invalid parent'][] = [
      'field_xb_test.1.parent_uuid' => 'Invalid component tree item with UUID <em class="placeholder">e303dd88-9409-4dc7-8a8b-a31602884a94</em> references an invalid parent <em class="placeholder">6381352f-5b0a-4ca1-960d-a5505b37b27c</em>.',
    ];
    $test_cases['invalid slot'][] = [
      'field_xb_test.1.slot' => 'Invalid component subtree. This component subtree contains an invalid slot name for component <em class="placeholder">sdc.xb_test_sdc.props-slots</em>: <em class="placeholder">banana</em>. Valid slot names are: <em class="placeholder">the_body, the_footer, the_colophon</em>.',
    ];
    return $test_cases;
  }

  /**
   * @coversClass \Drupal\experience_builder\Plugin\Validation\Constraint\ValidComponentTreeItemConstraintValidator
   * @param array $field_values
   * @param array $expected_violations
   *
   * @dataProvider providerInvalidField
   */
  public function testInvalidField(array $field_values, array $expected_violations): void {
    $this->container->get('module_installer')->install(['xb_test_config_node_article']);
    $node = Node::create([
      'title' => 'Test node',
      'type' => 'article',
      'field_xb_test' => $field_values,
    ]);
    $violations = $node->validate();
    $this->assertSame($expected_violations, self::violationsToArray($violations));
  }

}
