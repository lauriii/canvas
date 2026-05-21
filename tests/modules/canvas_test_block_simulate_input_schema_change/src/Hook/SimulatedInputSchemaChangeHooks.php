<?php

declare(strict_types=1);

namespace Drupal\canvas_test_block_simulate_input_schema_change\Hook;

use Drupal\canvas_test_block_simulate_input_schema_change\Plugin\Block\SimulatedInputSchemaChangeBlock;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\State\StateInterface;

readonly class SimulatedInputSchemaChangeHooks {

  public function __construct(
    private StateInterface $state,
  ) {}

  #[Hook('block_alter')]
  public function blockAlter(array &$definitions): void {
    // Simulate an explicit input schema change unless explicitly disabled by the flag.
    $allow_to_run_hook = $this->state->get('canvas_test_block.allow_hook_block_alter', TRUE);
    if ($allow_to_run_hook && isset($definitions['canvas_test_block_input_schema_change_poc'])) {
      $definitions['canvas_test_block_input_schema_change_poc']['class'] = SimulatedInputSchemaChangeBlock::class;
    }
  }

  #[Hook('config_schema_info_alter')]
  public static function configSchemaInfoAlter(array &$definitions): void {
    if (isset($definitions['block.settings.canvas_test_block_input_schema_change_poc'])) {
      $definitions['block.settings.canvas_test_block_input_schema_change_poc']['mapping'] = [
        // ⚠️ Simulate the first explicit input having its type change (upon
        // updating the module providing this block plugin) from `string` to
        // `integer`, causing all existing instances to have invalid settings.
        'foo' => [
          'type' => 'integer',
          'label' => 'Foo',
          'constraints' => [
            'Choice' => [
              1,
              2,
            ],
          ],
        ],
        // ⚠️ Simulate a second explicit input having appeared (upon updating
        // the module providing this block plugin).
        'change' => [
          'type' => 'string',
          'label' => 'Change',
          'constraints' => [
            'Choice' => [
              'is scary',
              'is necessary',
            ],
          ],
        ],
      ];
    }
  }

}
