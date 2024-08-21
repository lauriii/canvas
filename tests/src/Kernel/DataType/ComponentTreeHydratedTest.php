<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Kernel\DataType;

use Drupal\Core\Render\RendererInterface;
use Drupal\Core\TypedData\TypedDataManagerInterface;
use Drupal\experience_builder\Plugin\DataType\ComponentTreeHydrated;
use Drupal\experience_builder\Plugin\DataType\ComponentTreeStructure;
use Drupal\experience_builder\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\experience_builder\Traits\ConstraintViolationsTestTrait;

/**
 * @coversDefaultClass \Drupal\experience_builder\Plugin\DataType\ComponentTreeHydrated
 * @group experience_builder
 */
class ComponentTreeHydratedTest extends KernelTestBase {

  use ConstraintViolationsTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'experience_builder',
    'xb_test_sdc',
  ];

  /**
   * @dataProvider provider
   */
  public function test(array $tree, array $props, array $expected_value, array $expected_renderable, string $expected_html): void {
    $typed_data_manager = $this->container->get(TypedDataManagerInterface::class);
    $field_item_definition = $typed_data_manager->createDataDefinition('field_item:component_tree');
    $component_tree_field_item = $typed_data_manager->createInstance('field_item:component_tree', [
      'name' => NULL,
      'parent' => NULL,
      'data_definition' => $field_item_definition,
    ]);
    assert($component_tree_field_item instanceof ComponentTreeItem);
    $component_tree_field_item->setValue([
      'tree' => json_encode($tree, JSON_UNESCAPED_UNICODE | JSON_FORCE_OBJECT),
      'props' => json_encode($props, JSON_UNESCAPED_UNICODE | JSON_FORCE_OBJECT),
    ]);

    // Every test case must be valid.
    $violations = $component_tree_field_item->validate();
    $this->assertSame([], self::violationsToArray($violations));

    // Assert that the corresponding hydrated component tree is valid, in both
    // representations:
    // 1. raw (`::getValue()`)
    // 2. Drupal renderable (`::toRenderable()`)
    // 3. the resulting HTML markup.assert($node->field_xb_test[0] instanceof ComponentTreeItem);
    $hydrated = $component_tree_field_item->get('hydrated');
    assert($hydrated instanceof ComponentTreeHydrated);
    $hydrated_value = $hydrated->getValue();
    $json = $hydrated_value->getContent();
    $this->assertIsString($json);
    $this->assertSame($expected_value, json_decode($json, TRUE));
    $renderable = $hydrated->toRenderable();
    $this->assertSame($expected_renderable, $renderable);
    $this->assertSame($expected_html, (string) $this->container->get(RendererInterface::class)->renderInIsolation($renderable));
  }

  public static function provider(): \Generator {
    $generate_static_prop_source = function (string $label): array {
      return [
        'sourceType' => 'static:field_item:string',
        'value' => "Hello, $label!",
        'expression' => 'ℹ︎string␟value',
      ];
    };

    yield 'empty component tree' => [
      'tree' => [
        ComponentTreeStructure::ROOT_UUID => [],
      ],
      'props' => [],
      'expected_value' => [
        ComponentTreeStructure::ROOT_UUID => [],
      ],
      'expected_renderable' => [],
      'expected_html' => '',
    ];

    yield 'simplest component tree without nesting' => [
      'tree' => [
        ComponentTreeStructure::ROOT_UUID => [
          ['uuid' => 'uuid-in-root', 'component' => 'xb_test_sdc:props-no-slots'],
          ['uuid' => 'uuid-in-root-another', 'component' => 'xb_test_sdc:props-no-slots'],
        ],
      ],
      'props' => [
        'uuid-in-root' => [
          'heading' => $generate_static_prop_source('world'),
        ],
        'uuid-in-root-another' => [
          'heading' => $generate_static_prop_source('another world'),
        ],
      ],
      'expected_value' => [
        ComponentTreeStructure::ROOT_UUID => [
          'uuid-in-root' => [
            'component' => 'xb_test_sdc:props-no-slots',
            'props' => ['heading' => 'Hello, world!'],
          ],
          'uuid-in-root-another' => [
            'component' => 'xb_test_sdc:props-no-slots',
            'props' => ['heading' => 'Hello, another world!'],
          ],
        ],
      ],
      'expected_renderable' => [
        ComponentTreeStructure::ROOT_UUID => [
          'uuid-in-root' => [
            '#type' => 'component',
            '#component' => 'xb_test_sdc:props-no-slots',
            '#props' => ['heading' => 'Hello, world!'],
          ],
          'uuid-in-root-another' => [
            '#type' => 'component',
            '#component' => 'xb_test_sdc:props-no-slots',
            '#props' => ['heading' => 'Hello, another world!'],
          ],
        ],
      ],
      'expected_html' => <<<HTML
<div  data-component-id="xb_test_sdc:props-no-slots" style="font-family: Helvetica, Arial, sans-serif; width: 100%; height: 100vh; background-color: #f5f5f5; display: flex; justify-content: center; align-items: center; flex-direction: column; text-align: center; padding: 20px; box-sizing: border-box;">
  <h1 style="font-size: 3em; margin: 0.5em 0; color: #333;">Hello, world!</h1>
</div>
<div  data-component-id="xb_test_sdc:props-no-slots" style="font-family: Helvetica, Arial, sans-serif; width: 100%; height: 100vh; background-color: #f5f5f5; display: flex; justify-content: center; align-items: center; flex-direction: column; text-align: center; padding: 20px; box-sizing: border-box;">
  <h1 style="font-size: 3em; margin: 0.5em 0; color: #333;">Hello, another world!</h1>
</div>

HTML,
    ];

    yield 'simplest component tree with nesting' => [
      'tree' => [
        ComponentTreeStructure::ROOT_UUID => [
          ['uuid' => 'uuid-in-root', 'component' => 'xb_test_sdc:props-slots'],
        ],
        'uuid-in-root' => [
          'the_body' => [
            ['uuid' => 'uuid-in-slot', 'component' => 'xb_test_sdc:props-no-slots'],
          ],
        ],
      ],
      'props' => [
        'uuid-in-root' => [
          'heading' => $generate_static_prop_source('world'),
        ],
        'uuid-in-slot' => [
          'heading' => $generate_static_prop_source('from a slot'),
        ],
      ],
      'expected_value' => [
        ComponentTreeStructure::ROOT_UUID => [
          'uuid-in-root' => [
            'component' => 'xb_test_sdc:props-slots',
            'props' => ['heading' => 'Hello, world!'],
            'slots' => [
              'the_body' => [
                'uuid-in-slot' => [
                  'component' => 'xb_test_sdc:props-no-slots',
                  'props' => ['heading' => 'Hello, from a slot!'],
                ],
              ],
            ],
          ],
        ],
      ],
      'expected_renderable' => [
        ComponentTreeStructure::ROOT_UUID => [
          'uuid-in-root' => [
            '#type' => 'component',
            '#component' => 'xb_test_sdc:props-slots',
            '#props' => ['heading' => 'Hello, world!'],
            '#slots' => [
              'the_body' => [
                'uuid-in-slot' => [
                  '#type' => 'component',
                  '#component' => 'xb_test_sdc:props-no-slots',
                  '#props' => ['heading' => 'Hello, from a slot!'],
                ],
              ],
            ],
          ],
        ],
      ],
      'expected_html' => <<<HTML
<div  data-component-id="xb_test_sdc:props-slots" style="font-family: Helvetica, Arial, sans-serif; width: 100%; height: 100vh; background-color: #f5f5f5; display: flex; justify-content: center; align-items: center; flex-direction: column; text-align: center; padding: 20px; box-sizing: border-box;">
  <h1 style="font-size: 3em; margin: 0.5em 0; color: #333;">Hello, world!</h1>
  <div class="component--props-slots--body">
        <div  data-component-id="xb_test_sdc:props-no-slots" style="font-family: Helvetica, Arial, sans-serif; width: 100%; height: 100vh; background-color: #f5f5f5; display: flex; justify-content: center; align-items: center; flex-direction: column; text-align: center; padding: 20px; box-sizing: border-box;">
  <h1 style="font-size: 3em; margin: 0.5em 0; color: #333;">Hello, from a slot!</h1>
</div>

    </div>
</div>

HTML,
    ];

    yield 'component tree with complex nesting' => [
      'tree' => [
        // Note how these are NOT sequentially ordered.
        'uuid-in-root' => [
          'the_body' => [
            ['uuid' => 'uuid-level-1', 'component' => 'xb_test_sdc:props-slots'],
          ],
        ],
        'uuid-level-2' => [
          'the_body' => [
            ['uuid' => 'uuid-level-3', 'component' => 'xb_test_sdc:props-no-slots'],
            ['uuid' => 'uuid-last-in-tree', 'component' => 'xb_test_sdc:props-no-slots'],
          ],
        ],
        'uuid-level-1' => [
          'the_body' => [
            ['uuid' => 'uuid-level-2', 'component' => 'xb_test_sdc:props-slots'],
          ],
        ],
        ComponentTreeStructure::ROOT_UUID => [
          ['uuid' => 'uuid-in-root', 'component' => 'xb_test_sdc:props-slots'],
        ],
      ],
      'props' => [
        // Note how these are NOT sequentially ordered, but in a different way.
        'uuid-in-root' => [
          'heading' => $generate_static_prop_source('world'),
        ],
        'uuid-level-3' => ['heading' => $generate_static_prop_source('from slot level 3')],
        'uuid-level-1' => ['heading' => $generate_static_prop_source('from slot level 1')],
        'uuid-last-in-tree' => ['heading' => $generate_static_prop_source('from slot <LAST ONE>')],
        'uuid-level-2' => ['heading' => $generate_static_prop_source('from slot level 2')],
      ],
      'expected_value' => [
        // Note how these are sequentially ordered.
        ComponentTreeStructure::ROOT_UUID => [
          'uuid-in-root' => [
            'component' => 'xb_test_sdc:props-slots',
            'props' => ['heading' => 'Hello, world!'],
            'slots' => [
              'the_body' => [
                'uuid-level-1' => [
                  'component' => 'xb_test_sdc:props-slots',
                  'props' => ['heading' => 'Hello, from slot level 1!'],
                  'slots' => [
                    'the_body' => [
                      'uuid-level-2' => [
                        'component' => 'xb_test_sdc:props-slots',
                        'props' => ['heading' => 'Hello, from slot level 2!'],
                        'slots' => [
                          'the_body' => [
                            'uuid-level-3' => [
                              'component' => 'xb_test_sdc:props-no-slots',
                              'props' => ['heading' => 'Hello, from slot level 3!'],
                            ],
                            'uuid-last-in-tree' => [
                              'component' => 'xb_test_sdc:props-no-slots',
                              'props' => ['heading' => 'Hello, from slot <LAST ONE>!'],
                            ],
                          ],
                        ],
                      ],
                    ],
                  ],
                ],
              ],
            ],
          ],
        ],
      ],
      'expected_renderable' => [
        // Note how these are sequentially ordered.
        ComponentTreeStructure::ROOT_UUID => [
          'uuid-in-root' => [
            '#type' => 'component',
            '#component' => 'xb_test_sdc:props-slots',
            '#props' => ['heading' => 'Hello, world!'],
            '#slots' => [
              'the_body' => [
                'uuid-level-1' => [
                  '#type' => 'component',
                  '#component' => 'xb_test_sdc:props-slots',
                  '#props' => ['heading' => 'Hello, from slot level 1!'],
                  '#slots' => [
                    'the_body' => [
                      'uuid-level-2' => [
                        '#type' => 'component',
                        '#component' => 'xb_test_sdc:props-slots',
                        '#props' => ['heading' => 'Hello, from slot level 2!'],
                        '#slots' => [
                          'the_body' => [
                            'uuid-level-3' => [
                              '#type' => 'component',
                              '#component' => 'xb_test_sdc:props-no-slots',
                              '#props' => ['heading' => 'Hello, from slot level 3!'],
                            ],
                            'uuid-last-in-tree' => [
                              '#type' => 'component',
                              '#component' => 'xb_test_sdc:props-no-slots',
                              '#props' => ['heading' => 'Hello, from slot <LAST ONE>!'],
                            ],
                          ],
                        ],
                      ],
                    ],
                  ],
                ],
              ],
            ],
          ],
        ],
      ],
      'expected_html' => <<<HTML
<div  data-component-id="xb_test_sdc:props-slots" style="font-family: Helvetica, Arial, sans-serif; width: 100%; height: 100vh; background-color: #f5f5f5; display: flex; justify-content: center; align-items: center; flex-direction: column; text-align: center; padding: 20px; box-sizing: border-box;">
  <h1 style="font-size: 3em; margin: 0.5em 0; color: #333;">Hello, world!</h1>
  <div class="component--props-slots--body">
        <div  data-component-id="xb_test_sdc:props-slots" style="font-family: Helvetica, Arial, sans-serif; width: 100%; height: 100vh; background-color: #f5f5f5; display: flex; justify-content: center; align-items: center; flex-direction: column; text-align: center; padding: 20px; box-sizing: border-box;">
  <h1 style="font-size: 3em; margin: 0.5em 0; color: #333;">Hello, from slot level 1!</h1>
  <div class="component--props-slots--body">
        <div  data-component-id="xb_test_sdc:props-slots" style="font-family: Helvetica, Arial, sans-serif; width: 100%; height: 100vh; background-color: #f5f5f5; display: flex; justify-content: center; align-items: center; flex-direction: column; text-align: center; padding: 20px; box-sizing: border-box;">
  <h1 style="font-size: 3em; margin: 0.5em 0; color: #333;">Hello, from slot level 2!</h1>
  <div class="component--props-slots--body">
        <div  data-component-id="xb_test_sdc:props-no-slots" style="font-family: Helvetica, Arial, sans-serif; width: 100%; height: 100vh; background-color: #f5f5f5; display: flex; justify-content: center; align-items: center; flex-direction: column; text-align: center; padding: 20px; box-sizing: border-box;">
  <h1 style="font-size: 3em; margin: 0.5em 0; color: #333;">Hello, from slot level 3!</h1>
</div>
<div  data-component-id="xb_test_sdc:props-no-slots" style="font-family: Helvetica, Arial, sans-serif; width: 100%; height: 100vh; background-color: #f5f5f5; display: flex; justify-content: center; align-items: center; flex-direction: column; text-align: center; padding: 20px; box-sizing: border-box;">
  <h1 style="font-size: 3em; margin: 0.5em 0; color: #333;">Hello, from slot &lt;LAST ONE&gt;!</h1>
</div>

    </div>
</div>

    </div>
</div>

    </div>
</div>

HTML,
    ];
  }

}
