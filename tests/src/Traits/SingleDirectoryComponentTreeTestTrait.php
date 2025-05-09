<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Traits;

use Drupal\experience_builder\Plugin\DataType\ComponentTreeStructure;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;

/**
 * Any test using these test cases must install the `xb_test_sdc` module.
 */
trait SingleDirectoryComponentTreeTestTrait {

  protected function createComponentTreeField(string $entity_type_id, string $bundle): void {
    $field_storage = FieldStorageConfig::create([
      'field_name' => 'field_component_tree',
      'entity_type' => $entity_type_id,
      'type' => 'component_tree',
    ]);
    $field_storage->save();

    FieldConfig::create([
      'field_storage' => $field_storage,
      'bundle' => $bundle,
    ])->save();
  }

  protected static function getValidTreeTestCases(): array {
    return [
      'valid values using static inputs' => [
        [
          'tree' => [
            ComponentTreeStructure::ROOT_UUID => [
              [
                'uuid' => 'dynamic-static-card2df',
                'component' => 'sdc.xb_test_sdc.props-slots',
              ],
            ],
          ],
          'inputs' => [
            'dynamic-static-card2df' => [
              'heading' => [
                'sourceType' => 'static:field_item:string',
                'value' => 'They say I am static, but I want to believe I can change!',
                'expression' => 'ℹ︎string␟value',
              ],
            ],
          ],
        ],
      ],
      'valid values for propless component' => [
        [
          'tree' => [
            "a548b48d-58a8-4077-aa04-da9405a6f418" => [
              [
                "uuid" => "propless-component-uuid",
                "component" => "sdc.experience_builder.druplicon",
              ],
            ],
          ],
          'inputs' => [],
        ],
      ],
      'valid value for optional explicit input using an URL prop shape, with default value' => [
        [
          'tree' => [
            ComponentTreeStructure::ROOT_UUID => [
              [
                'uuid' => 'optional-url-with-default-value',
                'component' => 'sdc.xb_test_sdc.image-optional-with-example-and-additional-prop',
              ],
            ],
          ],
          'inputs' => [
            'optional-url-with-default-value' => [
              'heading' => [
                'sourceType' => 'static:field_item:string',
                'value' => 'Gracie says hi!',
                'expression' => 'ℹ︎string␟value',
              ],
              'image' => [
                'sourceType' => 'default-relative-url',
                'value' => [
                  'src' => 'gracie.jpg',
                  'alt' => 'A good dog',
                  'width' => 601,
                  'height' => 402,
                ],
                'jsonSchema' => [
                  'type' => 'object',
                  'properties' => [
                    'src' => [
                      'type' => 'string',
                      'format' => 'uri-reference',
                      'pattern' => '^(/|https?://)?.*\.(png|gif|jpg|jpeg|webp)(\?.*)?(#.*)?$',
                    ],
                    'alt' => ['type' => 'string'],
                    'width' => ['type' => 'integer'],
                    'height' => ['type' => 'integer'],
                  ],
                  'required' => ['src'],
                ],
                'componentId' => 'sdc.xb_test_sdc.image-optional-with-example-and-additional-prop',
              ],
            ],
          ],
        ],
      ],
    ];
  }

  protected static function getInvalidTreeTestCases(): array {
    return [
      'invalid values using dynamic inputs' => [
        [
          'tree' => [
            ComponentTreeStructure::ROOT_UUID => [
              [
                'uuid' => 'dynamic-dynamic-card2df',
                'component' => 'sdc.xb_test_sdc.props-slots',
              ],
            ],
          ],
          'inputs' => [
            'dynamic-dynamic-card2df' => [
              'heading' => [
                'sourceType' => 'dynamic',
                'expression' => 'ℹ︎␜entity:node:article␝title␞␟value',
              ],
            ],
          ],
        ],
      ],
      'invalid tree structure, uuid at top of data structure is not in the tree, also has empty slots' => [
        [
          'tree' => [
            ComponentTreeStructure::ROOT_UUID => [
              [
                'uuid' => 'dynamic-static-card2df',
                'component' => 'sdc.xb_test_sdc.props-slots',
              ],
            ],
            'other-uuid' => [],
          ],
          'inputs' => [
            'dynamic-static-card2df' => [
              'heading' => [
                'sourceType' => 'static:field_item:string',
                'value' => 'Do not cause no static!',
                'expression' => 'ℹ︎string␟value',
              ],
            ],
          ],
        ],
      ],
      'missing components, using dynamic inputs' => [
        [
          'tree' => [
            ComponentTreeStructure::ROOT_UUID => [
              ['uuid' => 'dynamic-static-card2df', 'component' => 'sdc.sdc_test.missing'],
              ['uuid' => 'dynamic-static-card3', 'component' => 'sdc.sdc_test.missing-also'],
              ['uuid' => 'dynamic-static-card4', 'component' => 'sdc.xb_test_sdc.props-slots'],
            ],
          ],
          'inputs' => [
            'dynamic-static-card2df' => [
              'heading' => [
                'sourceType' => 'dynamic',
                'expression' => 'ℹ︎␜entity:node:article␝title␞␟value',
              ],
            ],
            'dynamic-static-card3' => [
              'heading' => [
                'sourceType' => 'dynamic',
                'expression' => 'ℹ︎␜entity:node:article␝title␞␟value',
              ],
            ],
            'dynamic-static-card4' => [
              'heading' => [
                'sourceType' => 'dynamic',
                'expression' => 'ℹ︎␜entity:node:article␝title␞␟value',
              ],
            ],
          ],
        ],
      ],
      'missing components, using only static inputs' => [
        [
          'tree' => [
            ComponentTreeStructure::ROOT_UUID => [
              ['uuid' => 'static-card2df', 'component' => 'sdc.sdc_test.missing'],
            ],
          ],
          'inputs' => [
            'static-card2df' => [
              'text' => [
                'sourceType' => 'static:field_item:link',
                'value' => [
                  'uri' => 'https://drupal.org',
                  'title' => NULL,
                  'options' => [],
                ],
                'expression' => 'ℹ︎link␟uri',
              ],
            ],
          ],
        ],
      ],
      'inputs invalid, using dynamic inputs' => [
        [
          'tree' => [
            ComponentTreeStructure::ROOT_UUID => [
              ['uuid' => 'dynamic-static-card2df', 'component' => 'sdc.xb_test_sdc.props-slots'],
              ['uuid' => 'dynamic-static-card3', 'component' => 'sdc.xb_test_sdc.props-slots'],
              ['uuid' => 'dynamic-static-card4', 'component' => 'sdc.xb_test_sdc.props-slots'],
            ],
          ],
          'inputs' => [
            'dynamic-static-card2df' => [
              'heading-2' => [
                'sourceType' => 'dynamic',
                'expression' => 'ℹ︎␜entity:node:article␝title␞␟value',
              ],
            ],
            'dynamic-static-card3' => [
              'heading-1' => [
                'sourceType' => 'dynamic',
                'expression' => 'ℹ︎␜entity:node:article␝title␞␟value',
              ],
            ],
            'dynamic-static-card4' => [
              'heading' => [
                'sourceType' => 'dynamic',
                'expression' => 'ℹ︎␜entity:node:article␝title␞␟value',
              ],
            ],
          ],
        ],
      ],
      'inputs invalid, using only static inputs' => [
        [
          'tree' => [
            ComponentTreeStructure::ROOT_UUID => [
              ['uuid' => 'static-card2df', 'component' => 'sdc.xb_test_sdc.props-no-slots'],
            ],
          ],
          'inputs' => [
            'static-card2df' => [
              'heading-x' => [
                'sourceType' => 'static:field_item:link',
                'value' => [
                  'uri' => 'https://drupal.org',
                  'title' => NULL,
                  'options' => [],
                ],
                'expression' => 'ℹ︎link␟uri',
              ],
            ],
          ],
        ],
      ],
      'missing inputs key' => [
        [
          'tree' => [
            ComponentTreeStructure::ROOT_UUID => [
              ['uuid' => 'dynamic-static-card2df', 'component' => 'sdc.xb_test_sdc.props-slots'],
              ['uuid' => 'dynamic-static-card3', 'component' => 'sdc.xb_test_sdc.props-slots'],
              ['uuid' => 'dynamic-static-card4', 'component' => 'sdc.xb_test_sdc.props-slots'],
            ],
          ],
        ],
      ],
      'missing tree key' => [
        [
          'inputs' => [
            'dynamic-static-card2df' => [
              'text' => [
                'sourceType' => 'static:field_item:string',
                'value' => 'Static like electricity? No like unchanging',
                'expression' => 'ℹ︎string␟value',
              ],
              'href' => [
                'sourceType' => 'static:field_item:link',
                'value' => [
                  'uri' => 'https://drupal.org',
                  'title' => NULL,
                  'options' => [],
                ],
                'expression' => 'ℹ︎link␟uri',
              ],
            ],
          ],
        ],
      ],
    ];
  }

}
