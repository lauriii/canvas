<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Kernel\Plugin\Field\FieldType;

use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\experience_builder\Plugin\DataType\ComponentTreeStructure;
use Drupal\experience_builder\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\Tests\experience_builder\Traits\ComponentTreeTestTrait;
use Drupal\Tests\experience_builder\Traits\ConstraintViolationsTestTrait;
use Drupal\Tests\experience_builder\Traits\ContribStrictConfigSchemaTestTrait;
use Drupal\Tests\experience_builder\Traits\GenerateComponentConfigTrait;
use Drupal\Tests\experience_builder\Traits\TestDataUtilitiesTrait;

/**
 * @coversDefaultClass \Drupal\experience_builder\Plugin\Field\FieldType\ComponentTreeItem
 * @group experience_builder
 */
class ComponentTreeItemTest extends KernelTestBase {

  use ComponentTreeTestTrait;
  use ConstraintViolationsTestTrait;
  use ContribStrictConfigSchemaTestTrait;
  use GenerateComponentConfigTrait;
  use TestDataUtilitiesTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'experience_builder',
    'sdc',
    'sdc_test',
    'xb_test_sdc',
    // Modules providing field types + widgets for the component props defaults.
    'file',
    'image',
    'options',
    'link',
    'system',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->generateComponentConfig();
  }

  public function testCalculateDependencies(): void {
    $this->assertSame([], ComponentTreeItem::calculateDependencies(BaseFieldDefinition::create('component_tree')));
    $this->assertSame(
      [
        'config' => [
          'experience_builder.component.sdc.experience_builder.image',
          'experience_builder.component.sdc.sdc_test.my-cta',
        ],
      ],
      ComponentTreeItem::calculateDependencies(BaseFieldDefinition::create('component_tree')->setDefaultValue(
        [
          'tree' => self::encodeXBData([
            ComponentTreeStructure::ROOT_UUID => [
              ['uuid' => 'dynamic-image-udf7d', 'component' => 'sdc.experience_builder.image'],
              ['uuid' => 'static-static-card1ab', 'component' => 'sdc.sdc_test.my-cta'],
              ['uuid' => 'dynamic-static-card2df', 'component' => 'sdc.sdc_test.my-cta'],
              ['uuid' => 'dynamic-dynamic-card3rr', 'component' => 'sdc.sdc_test.my-cta'],
              ['uuid' => 'dynamic-image-static-imageStyle-something7d', 'component' => 'sdc.experience_builder.image'],
            ],
          ]),
          'props' => json_encode([
            'dynamic-static-card2df' => [
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
            'static-static-card1ab' => [
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
            'dynamic-dynamic-card3rr' => [
              'text' => [
                'sourceType' => 'dynamic',
                'expression' => 'ℹ︎␜entity:node:article␝title␞␟value',
              ],
              'href' => [
                'sourceType' => 'dynamic',
                'expression' => 'ℹ︎␜entity:node:article␝field_hero␞␟entity␜␜entity:file␝uri␞␟value',
              ],
            ],
            'dynamic-image-udf7d' => [
              'image' => [
                'sourceType' => 'dynamic',
                'expression' => 'ℹ︎␜entity:node:article␝field_hero␞␟{src↝entity␜␜entity:file␝uri␞␟value,alt↠alt,width↠width,height↠height}',
              ],
            ],
            'dynamic-image-static-imageStyle-something7d' => [
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
          ]),
        ]
      ))
    );
  }

  public static function providerInvalidField(): array {
    $root_uuid = ComponentTreeStructure::ROOT_UUID;
    $test_cases = static::getValidTreeTestCases();
    array_walk($test_cases, fn(array &$test_case) => $test_case[] = []);
    $test_cases = array_merge($test_cases, static::getInvalidTreeTestCases());
    $test_cases['invalid tree: the main content block is only allowed in PageTemplate'][] = [
      'field_xb_test.0' => "The 'Drupal\Core\Block\MainContentBlockPluginInterface' component interface must be absent.",
    ];
    $test_cases['invalid values using dynamic props'][] = [
      'field_xb_test.0' => "The 'dynamic' prop source type must be absent.",
    ];
    $test_cases['invalid tree structure, uuid at top of data structure is not in the tree, also has empty slots'][] = [
      'field_xb_test.0.tree[other-uuid]' => [
        'Empty component subtree. A component subtree must contain >=1 populated slot (with >=1 component instance). Empty component subtrees must be omitted.',
        'Dangling component subtree. This component subtree claims to be for a component instance with UUID <em class="placeholder">other-uuid</em>, but no such component instance can be found.',
      ],
    ];
    $test_cases['missing components, using dynamic props'][] = [
      'field_xb_test.0' => "The 'dynamic' prop source type must be absent.",
      "field_xb_test.0.tree[$root_uuid][0]" => 'The component <em class="placeholder">sdc.sdc_test.missing</em> does not exist.',
      "field_xb_test.0.tree[$root_uuid][1]" => 'The component <em class="placeholder">sdc.sdc_test.missing-also</em> does not exist.',
    ];
    $test_cases['missing components, using only static props'][] = [
      "field_xb_test.0.tree[$root_uuid][0]" => 'The component <em class="placeholder">sdc.sdc_test.missing</em> does not exist.',
    ];
    $test_cases['props invalid, using dynamic props'][] = [
      'field_xb_test.0.props.dynamic-static-card2df.heading' => 'The property heading is required',
      'field_xb_test.0.props.dynamic-static-card3.heading' => 'The property heading is required',
      'field_xb_test.0' => "The 'dynamic' prop source type must be absent.",
    ];
    $test_cases['props invalid, using only static props'][] = [
      'field_xb_test.0.props.static-card2df.heading' => 'The property heading is required',
    ];
    $test_cases['missing props key'][] = [
      'field_xb_test.0' => 'The array must contain a "props" key.',
    ];
    $test_cases['missing tree key'][] = [
      'field_xb_test.0' => 'The array must contain a "tree" key.',
    ];
    return $test_cases;
  }

  /**
   * @covers \Drupal\experience_builder\Plugin\Field\FieldType\ComponentTreeItem::resolveComponentProps
   * @dataProvider providerComponentResolving
   */
  public function testComponentResolving(array $component_item_value, array $expected_props_for_uuids): void {
    $this->container->get('module_installer')->install(['xb_test_config_node_article']);
    $node = Node::create([
      'title' => 'Test node',
      'type' => 'article',
      'field_xb_test' => $component_item_value,
    ]);
    $xb_field_item = $node->field_xb_test[0];
    $this->assertInstanceOf(ComponentTreeItem::class, $xb_field_item);
    $actual_props = array_combine(
      array_keys($expected_props_for_uuids),
      array_map(fn (string $uuid) => $xb_field_item->resolveComponentProps($uuid), array_keys($expected_props_for_uuids))
    );
    $this->assertSame($expected_props_for_uuids, $actual_props);
  }

  public static function providerComponentResolving(): array {
    $test_cases = static::getValidTreeTestCases();
    $invalid_test_cases = static::getInvalidTreeTestCases();
    // Only 1 invalid case will allow to call
    // \Drupal\experience_builder\Plugin\Field\FieldType\ComponentTreeItem::resolveComponentProps()
    // without an exception.
    $test_cases['invalid tree structure, uuid at top of data structure is not in the tree, also has empty slots'] = $invalid_test_cases['invalid tree structure, uuid at top of data structure is not in the tree, also has empty slots'];
    $test_cases['invalid tree structure, uuid at top of data structure is not in the tree, also has empty slots'][] = [];
    $test_cases['valid values using static props'][] = [
      'dynamic-static-card2df' => [
        'heading' => 'They say I am static, but I want to believe I can change!',
      ],
    ];
    $test_cases['valid values for propless component'][] = [
      'propless-component-uuid' => [],
    ];
    return $test_cases;
  }

  /**
   * @coversClass \Drupal\experience_builder\Plugin\Validation\Constraint\ValidComponentTreeConstraintValidator
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
      'field_xb_test' => [$field_values],
    ]);
    $violations = $node->validate();
    $this->assertSame($expected_violations, self::violationsToArray($violations));
  }

}
