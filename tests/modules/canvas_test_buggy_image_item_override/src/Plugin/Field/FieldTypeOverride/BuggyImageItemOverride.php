<?php

declare(strict_types=1);

namespace Drupal\canvas_test_buggy_image_item_override\Plugin\Field\FieldTypeOverride;

use Drupal\canvas\Plugin\Field\FieldTypeOverride\ImageItemOverride;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Field\FieldDefinitionInterface;

/**
 * Reproduces the pre-fix ImageItemOverride::calculateDependencies() bug.
 *
 * The old code hardcoded a config dependency on
 * `image.style.canvas_parametrized_width` in every image field config. Because
 * that image style has an enforced dependency on Canvas, uninstalling Canvas
 * would cascade-delete those field configs.
 *
 * @see https://www.drupal.org/project/canvas/issues/3575579
 */
final class BuggyImageItemOverride extends ImageItemOverride {

  /**
   * {@inheritdoc}
   */
  public static function calculateDependencies(FieldDefinitionInterface $field_definition) {
    return NestedArray::mergeDeep(
      parent::calculateDependencies($field_definition),
      [
        'config' => [
          'image.style.canvas_parametrized_width',
        ],
      ],
    );
  }

}
