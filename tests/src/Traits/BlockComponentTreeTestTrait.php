<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Traits;

use Drupal\experience_builder\Plugin\Field\FieldType\ComponentTreeItemList;

/**
 * Any test using these test cases must install the `xb_test_block` module.
 */
trait BlockComponentTreeTestTrait {

  public static function getValidTreeTestCases(): array {
    return [
      'block input none' => [
        [
          [
            'uuid' => 'block-input-none',
            'component_id' => 'block.xb_test_block_input_none',
            'inputs' => [
              'label' => 'Test block with no settings.',
              'label_display' => '',
            ],
          ],
        ],
      ],

      'block input validatable' => [
        [
          [
            'uuid' => 'block-input-validatable',
            'component_id' => 'block.xb_test_block_input_validatable',
            'inputs' => [
              'label' => 'Test Block for testing.',
              'label_display' => '',
              'name' => 'Component',
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
            ComponentTreeItemList::ROOT_UUID => [
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
      'invalid UUID, missing component_id key' => [
        [
          ['uuid' => 'other-uuid'],
        ],
      ],
      'missing components, using dynamic inputs' => [
        [
          'tree' => [
            ComponentTreeItemList::ROOT_UUID => [
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
            ComponentTreeItemList::ROOT_UUID => [
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
            ComponentTreeItemList::ROOT_UUID => [
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
            ComponentTreeItemList::ROOT_UUID => [
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
            ComponentTreeItemList::ROOT_UUID => [
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
