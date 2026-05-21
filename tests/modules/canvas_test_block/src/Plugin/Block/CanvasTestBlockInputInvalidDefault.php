<?php

declare(strict_types=1);

namespace Drupal\canvas_test_block\Plugin\Block;

use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\StringTranslation\TranslatableMarkup;

#[Block(
  id: "canvas_test_block_input_invalid_default",
  admin_label: new TranslatableMarkup("Test Block with invalid default configuration"),
)]
final class CanvasTestBlockInputInvalidDefault extends CanvasTestBlockInputValidatableCrash {

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return [
      'name' => 'Canvas',
      // TRICKY: this is an intentional violation of this block plugin's
      // setting's config schema.
      'crash' => 'sure',
    ];
  }

}
