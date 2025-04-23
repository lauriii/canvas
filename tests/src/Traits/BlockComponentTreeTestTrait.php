<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Traits;

use Drupal\experience_builder\Plugin\DataType\ComponentTreeStructure;

/**
 * Any test using these test cases must install the `xb_test_block` module.
 */
trait BlockComponentTreeTestTrait {

  use TestDataUtilitiesTrait;

  public static function getValidTreeTestCases(): array {
    return [
      'block input none' => [
        [
          'uuid' => 'block-input-none',
          'tree' => self::encodeXBData([
            ComponentTreeStructure::ROOT_UUID => [
              [
                'uuid' => 'block-input-none',
                'component' => 'block.xb_test_block_input_none',
              ],
            ],
          ]),
          'inputs' => self::encodeXBData([
            'block-input-none' => [
              'label' => 'Test block with no settings.',
              'label_display' => '',
            ],
          ]),
        ],
      ],

      'block input validatable' => [
        [
          'uuid' => 'block-input-validatable',
          'tree' => self::encodeXBData([
            ComponentTreeStructure::ROOT_UUID => [
              [
                'uuid' => 'block-input-validatable',
                'component' => 'block.xb_test_block_input_validatable',
              ],
            ],
          ]),
          'inputs' => self::encodeXBData([
            'block-input-validatable' => [
              'label' => 'Test Block for testing.',
              'label_display' => '',
              'name' => 'Component',
            ],
          ]),
        ],
      ],
    ];
  }

  protected static function getInvalidTreeTestCases(): array {
    return [
      'invalid values using dynamic inputs' => [
        [
          'tree' => self::encodeXBData([
            ComponentTreeStructure::ROOT_UUID => [
              [
                'uuid' => 'dynamic-dynamic-card2df',
                'component' => 'sdc.xb_test_sdc.props-slots',
              ],
            ],
          ]),
          'inputs' => self::encodeXBData([
            'dynamic-dynamic-card2df' => [
              'heading' => [
                'sourceType' => 'dynamic',
                'expression' => 'ℹ︎␜entity:node:article␝title␞␟value',
              ],
            ],
          ]),
        ],
      ],
      'invalid tree structure, uuid at top of data structure is not in the tree, also has empty slots' => [
        [
          'tree' => self::encodeXBData([
            ComponentTreeStructure::ROOT_UUID => [
              [
                'uuid' => 'dynamic-static-card2df',
                'component' => 'sdc.xb_test_sdc.props-slots',
              ],
            ],
            'other-uuid' => [],
          ]),
          'inputs' => self::encodeXBData([
            'dynamic-static-card2df' => [
              'heading' => [
                'sourceType' => 'static:field_item:string',
                'value' => 'Do not cause no static!',
                'expression' => 'ℹ︎string␟value',
              ],
            ],
          ]),
        ],
      ],
      'missing components, using dynamic inputs' => [
        [
          'tree' => self::encodeXBData([
            ComponentTreeStructure::ROOT_UUID => [
              ['uuid' => 'dynamic-static-card2df', 'component' => 'sdc.sdc_test.missing'],
              ['uuid' => 'dynamic-static-card3', 'component' => 'sdc.sdc_test.missing-also'],
              ['uuid' => 'dynamic-static-card4', 'component' => 'sdc.xb_test_sdc.props-slots'],
            ],
          ]),
          'inputs' => self::encodeXBData([
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
          ]),
        ],
      ],
      'missing components, using only static inputs' => [
        [
          'tree' => self::encodeXBData([
            ComponentTreeStructure::ROOT_UUID => [
              ['uuid' => 'static-card2df', 'component' => 'sdc.sdc_test.missing'],
            ],
          ]),
          'inputs' => self::encodeXBData([
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
          ]),
        ],
      ],
      'inputs invalid, using dynamic inputs' => [
        [
          'tree' => self::encodeXBData([
            ComponentTreeStructure::ROOT_UUID => [
              ['uuid' => 'dynamic-static-card2df', 'component' => 'sdc.xb_test_sdc.props-slots'],
              ['uuid' => 'dynamic-static-card3', 'component' => 'sdc.xb_test_sdc.props-slots'],
              ['uuid' => 'dynamic-static-card4', 'component' => 'sdc.xb_test_sdc.props-slots'],
            ],
          ]),
          'inputs' => self::encodeXBData([
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
          ]),
        ],
      ],
      'inputs invalid, using only static inputs' => [
        [
          'tree' => self::encodeXBData([
            ComponentTreeStructure::ROOT_UUID => [
              ['uuid' => 'static-card2df', 'component' => 'sdc.xb_test_sdc.props-no-slots'],
            ],
          ]),
          'inputs' => self::encodeXBData([
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
          ]),
        ],
      ],
      'missing inputs key' => [
        [
          'tree' => self::encodeXBData([
            ComponentTreeStructure::ROOT_UUID => [
              ['uuid' => 'dynamic-static-card2df', 'component' => 'sdc.xb_test_sdc.props-slots'],
              ['uuid' => 'dynamic-static-card3', 'component' => 'sdc.xb_test_sdc.props-slots'],
              ['uuid' => 'dynamic-static-card4', 'component' => 'sdc.xb_test_sdc.props-slots'],
            ],
          ]),
        ],
      ],
      'missing tree key' => [
        [
          'inputs' => self::encodeXBData([
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
          ]),
        ],
      ],
    ];
  }

}
