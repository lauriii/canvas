<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Kernel\Plugin\Field\FieldType;

use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\experience_builder\Entity\Component;
use Drupal\experience_builder\Plugin\DataType\ComponentTreeStructure;
use Drupal\experience_builder\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\Tests\experience_builder\Traits\ComponentTreeTestTrait;
use Drupal\Tests\experience_builder\Traits\ConstraintViolationsTestTrait;
use Drupal\Tests\experience_builder\Traits\ContribStrictConfigSchemaTestTrait;
use Drupal\Tests\experience_builder\Traits\TestDataUtilitiesTrait;

/**
 * @coversDefaultClass \Drupal\experience_builder\Plugin\Field\FieldType\ComponentTreeItem
 * @group experience_builder
 */
class ComponentTreeItemTest extends KernelTestBase {

  use ComponentTreeTestTrait;
  use ConstraintViolationsTestTrait;
  use ContribStrictConfigSchemaTestTrait;
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
    'image',
    'options',
  ];

  public function testCalculateDependencies(): void {
    $this->container->get('theme_installer')->install(['sdc_theme_test']);
    Component::create([
      'label' => $this->randomString(),
      'component' => Component::convertMachineNameToId('experience_builder:image'),
      'defaults' => [
        'props' => [
          'image' => [
            // @see \Drupal\image\Plugin\Field\FieldType\ImageItem
            'field_type' => 'image',
            // @see \Drupal\image\Plugin\Field\FieldWidget\ImageWidget
            'field_widget' => 'image_image',
            'default_value' => NULL,
            'expression' => 'ℹ︎image␟image',
          ],
        ],
      ],
    ])->save();
    Component::create([
      'label' => $this->randomString(),
      'component' => Component::convertMachineNameToId('sdc_test:my-cta'),
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
    ])->save();
    $this->assertSame([], ComponentTreeItem::calculateDependencies(BaseFieldDefinition::create('component_tree')));
    $this->assertSame(
      [
        'config' => ['experience_builder.component.experience_builder+image', 'experience_builder.component.sdc_test+my-cta'],
      ],
      ComponentTreeItem::calculateDependencies(BaseFieldDefinition::create('component_tree')->setDefaultValue(
        [
          'tree' => self::encodeXBData([
            ComponentTreeStructure::ROOT_UUID => [
              ['uuid' => 'dynamic-image-udf7d', 'component' => 'experience_builder:image'],
              ['uuid' => 'static-static-card1ab', 'component' => 'sdc_test:my-cta'],
              ['uuid' => 'dynamic-static-card2df', 'component' => 'sdc_test:my-cta'],
              ['uuid' => 'dynamic-dynamic-card3rr', 'component' => 'sdc_test:my-cta'],
              ['uuid' => 'dynamic-image-static-imageStyle-something7d', 'component' => 'experience_builder:image'],
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

  public function providerInvalidField(): array {
    $root_uuid = ComponentTreeStructure::ROOT_UUID;
    $test_cases = $this->getComponentTreeTestCases();
    $test_cases['invalid tree structure, uuid at top of data structure is not in the tree, also has empty slots'][] = [
      'field_xb_test.0.tree[other-uuid]' => [
        'Empty component subtree. A component subtree must contain >=1 populated slot (with >=1 component instance). Empty component subtrees must be omitted.',
        'Dangling component subtree. This component subtree claims to be for a component instance with UUID <em class="placeholder">other-uuid</em>, but no such component instance can be found.',
      ],
    ];
    $test_cases['valid values using dynamic props'][] = [];
    $test_cases['missing components, using dynamic props'][] = [
      "field_xb_test.0.tree[$root_uuid][0]" => 'The component <em class="placeholder">sdc_test:missing</em> does not exist.',
      "field_xb_test.0.tree[$root_uuid][1]" => 'The component <em class="placeholder">sdc_test:missing-also</em> does not exist.',
    ];
    $test_cases['missing components, using only static props'][] = [
      "field_xb_test.0.tree[$root_uuid][0]" => 'The component <em class="placeholder">sdc_test:missing</em> does not exist.',
    ];
    $test_cases['props invalid, using dynamic props'][] = [
      'field_xb_test.0' => [
        'The component instance with UUID <em class="placeholder">dynamic-static-card2df</em> uses component <em class="placeholder">xb_test_sdc:props-slots</em> and receives some invalid props! Put a breakpoint here and figure out why.',
        'The component instance with UUID <em class="placeholder">dynamic-static-card3</em> uses component <em class="placeholder">xb_test_sdc:props-slots</em> and receives some invalid props! Put a breakpoint here and figure out why.',
      ],
    ];
    $test_cases['props invalid, using only static props'][] = [
      'field_xb_test.0' => 'The component instance with UUID <em class="placeholder">static-card2df</em> uses component <em class="placeholder">xb_test_sdc:props-no-slots</em> and receives some invalid props! Put a breakpoint here and figure out why.',
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
