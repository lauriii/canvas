<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Traits;

/**
 * Any test using these test cases must install the `canvas_test_block` module.
 */
trait BlockComponentTreeTestTrait {

  public static function getValidTreeTestCases(): array {
    return [
      'block input none' => [
        [
          [
            // A random UUID.
            'uuid' => 'ca45b820-2f17-43c5-99a1-a8536dc9a96c',
            'component_id' => 'block.canvas_test_block_input_none',
            'inputs' => [
              'label' => 'Test block with no settings.',
              'label_display' => '0',
            ],
          ],
        ],
      ],

      'block input validatable' => [
        [
          [
            // A random UUID.
            'uuid' => 'd93d0da5-2e91-44b6-b1d3-7043eabdef6f',
            'component_id' => 'block.canvas_test_block_input_validatable',
            'inputs' => [
              'label' => 'Test Block for testing.',
              'label_display' => '0',
              'name' => 'Component',
            ],
          ],
        ],
      ],
    ];
  }

}
