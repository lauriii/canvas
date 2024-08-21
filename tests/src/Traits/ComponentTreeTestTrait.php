<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Traits;

use Drupal\experience_builder\Plugin\DataType\ComponentTreeStructure;

/**
 * Any test using these test cases must install the `xb_test_sdc` module.
 */
trait ComponentTreeTestTrait {

  use TestDataUtilitiesTrait;

  protected function getComponentTreeTestCases(): array {
    return [
      'invalid tree structure, uuid at top of data structure is not in the tree, also has empty slots' => [
        [
          'tree' => self::encodeXBData([
            ComponentTreeStructure::ROOT_UUID => [
              [
                'uuid' => 'dynamic-static-card2df',
                'component' => 'xb_test_sdc:props-slots',
              ],
            ],
            'other-uuid' => [],
          ]),
          'props' => self::encodeXBData([
            'dynamic-static-card2df' => [
              'heading' => [
                'sourceType' => 'dynamic',
                'expression' => 'ℹ︎␜entity:node:article␝title␞␟value',
              ],
            ],
          ]),
        ],
      ],
      'valid values using dynamic props' => [
        [
          'tree' => self::encodeXBData([
            ComponentTreeStructure::ROOT_UUID => [
              [
                'uuid' => 'dynamic-static-card2df',
                'component' => 'xb_test_sdc:props-slots',
              ],
            ],
          ]),
          'props' => self::encodeXBData([
            'dynamic-static-card2df' => [
              'heading' => [
                'sourceType' => 'dynamic',
                'expression' => 'ℹ︎␜entity:node:article␝title␞␟value',
              ],
            ],
          ]),
        ],
      ],
      'missing components, using dynamic props' => [
        [
          'tree' => self::encodeXBData([
            ComponentTreeStructure::ROOT_UUID => [
              ['uuid' => 'dynamic-static-card2df', 'component' => 'sdc_test:missing'],
              ['uuid' => 'dynamic-static-card3', 'component' => 'sdc_test:missing-also'],
              ['uuid' => 'dynamic-static-card4', 'component' => 'xb_test_sdc:props-slots'],
            ],
          ]),
          'props' => self::encodeXBData([
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
          ]),
        ],
      ],
      'missing components, using only static props' => [
        [
          'tree' => self::encodeXBData([
            ComponentTreeStructure::ROOT_UUID => [
              ['uuid' => 'static-card2df', 'component' => 'sdc_test:missing'],
            ],
          ]),
          'props' => self::encodeXBData([
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
      'props invalid, using dynamic props' => [
        [
          'tree' => self::encodeXBData([
            ComponentTreeStructure::ROOT_UUID => [
              ['uuid' => 'dynamic-static-card2df', 'component' => 'xb_test_sdc:props-slots'],
              ['uuid' => 'dynamic-static-card3', 'component' => 'xb_test_sdc:props-slots'],
              ['uuid' => 'dynamic-static-card4', 'component' => 'xb_test_sdc:props-slots'],
            ],
          ]),
          'props' => self::encodeXBData([
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
      'props invalid, using only static props' => [
        [
          'tree' => self::encodeXBData([
            ComponentTreeStructure::ROOT_UUID => [
              ['uuid' => 'static-card2df', 'component' => 'xb_test_sdc:props-no-slots'],
            ],
          ]),
          'props' => self::encodeXBData([
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
      'missing props key' => [
        [
          'tree' => self::encodeXBData([
            ComponentTreeStructure::ROOT_UUID => [
              ['uuid' => 'dynamic-static-card2df', 'component' => 'xb_test_sdc:props-slots'],
              ['uuid' => 'dynamic-static-card3', 'component' => 'xb_test_sdc:props-slots'],
              ['uuid' => 'dynamic-static-card4', 'component' => 'xb_test_sdc:props-slots'],
            ],
          ]),
        ],
      ],
      'missing tree key' => [
        [
          'props' => self::encodeXBData([
            'dynamic-static-card2df' => [
              'text' => [
                'sourceType' => 'dynamic',
                'expression' => 'ℹ︎␜entity:node:article␝title␞␟value',
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
