<?php

declare(strict_types=1);

namespace Drupal\canvas_test_buggy_image_item_override\Hook;

use Drupal\canvas_test_buggy_image_item_override\Plugin\Field\FieldTypeOverride\BuggyImageItemOverride;
use Drupal\Core\Hook\Attribute\Hook;

/**
 * Overrides the image field type class with the buggy version.
 */
final class FieldInfoAlterHook {

  #[Hook('field_info_alter')]
  public function fieldInfoAlter(array &$info): void {
    if (\array_key_exists('image', $info)) {
      $info['image']['class'] = BuggyImageItemOverride::class;
    }
  }

}
