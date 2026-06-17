<?php

declare(strict_types=1);

namespace Drupal\canvas\Hook;

use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\canvas\Tmgmt\ComponentInputsConfigProcessor;
use Drupal\canvas\Tmgmt\ComponentTreeFieldProcessor;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Hook\Attribute\Hook;

/**
 * Hook implementations for TMGMT integration.
 */
final readonly class TmgmtHooks {

  public function __construct(
    private ModuleHandlerInterface $moduleHandler,
  ) {}

  /**
   * Implements hook_config_schema_info_alter().
   */
  #[Hook('config_schema_info_alter')]
  public static function configSchemaInfoAlter(array &$definitions): void {
    // 'canvas.pattern.*' is intentionally left out of this list as patterns are
    // not translatable.
    $types_with_component_trees = [
      'canvas.content_template.*.*.*',
      'canvas.page_region.*',
    ];
    foreach ($types_with_component_trees as $types_with_component_tree) {
      if (isset($definitions[$types_with_component_tree])) {
        $definitions[$types_with_component_tree]['tmgmt_config_processor'] = ComponentInputsConfigProcessor::class;
      }
    }
  }

  /**
   * Implements hook_field_info_alter().
   *
   * Registers ComponentTreeFieldProcessor for component_tree fields when
   * tmgmt_content is enabled, so each translatable prop in `inputs` appears as
   * a separate translatable string in the TMGMT review UI.
   *
   * @todo Refactor to use `HookDependsOnModule` once Canvas depends on Drupal 11.5
   * @see https://www.drupal.org/node/3548805
   */
  #[Hook('field_info_alter')]
  public function fieldInfoAlter(array &$info): void {
    if (isset($info[ComponentTreeItem::PLUGIN_ID]) && $this->moduleHandler->moduleExists('tmgmt_content')) {
      $info[ComponentTreeItem::PLUGIN_ID]['tmgmt_field_processor'] = ComponentTreeFieldProcessor::class;
    }
  }

}
