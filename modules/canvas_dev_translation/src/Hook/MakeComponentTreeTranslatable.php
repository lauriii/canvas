<?php

declare(strict_types=1);

namespace Drupal\canvas_dev_translation\Hook;

use Drupal\Core\Hook\Attribute\Hook;

/**
 * Makes component_tree translatable when translation feature is enabled.
 *
 * This class uses hook_config_schema_info_alter() instead of defining
 * translatable: true directly in canvas.schema.yml. This approach prevents
 * side effects when the canvas_dev_translation feature flag module is disabled.
 *
 * When this module is disabled, the schema alterations are not applied,
 * ensuring component_tree remains non-translatable without requiring cache
 * clears or leaving orphaned configuration.
 *
 * @see https://www.drupal.org/project/canvas/issues/3571232
 *
 * @todo Need to move these changes directly
 *       into canvas.schema.yml as permanent schema definitions
 *       when canvas_dev_translation removed.
 */
readonly final class MakeComponentTreeTranslatable {

  /**
   * Implements hook_config_schema_info_alter().
   *
   * Marks the component_tree property as translatable for Canvas configuration
   * entities. This allows translation of component trees when translation
   * modules (config_translation, content_translation) are enabled alongside
   * this feature flag module.
   *
   * @param array $definitions
   *   The configuration schema definitions.
   */
  #[Hook('config_schema_info_alter')]
  public function configSchemaInfoAlter(array &$definitions): void {
    // Mark component_tree as translatable for canvas.page_region.*.
    if (isset($definitions['canvas.page_region.*']['mapping']['component_tree'])) {
      $definitions['canvas.page_region.*']['mapping']['component_tree']['translatable'] = TRUE;
    }

    // Mark component_tree as translatable for canvas.content_template.*.*.*.
    if (isset($definitions['canvas.content_template.*.*.*']['mapping']['component_tree'])) {
      $definitions['canvas.content_template.*.*.*']['mapping']['component_tree']['translatable'] = TRUE;
    }
  }

}
