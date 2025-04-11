<?php

declare(strict_types=1);

namespace Drupal\xb_test_block\Plugin\Block;

use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;

#[Block(
  id: "xb_test_block_input_none",
  admin_label: new TranslatableMarkup("Test block with no settings."),
)]
final class XbTestBlockInputNone extends BlockBase {

  /**
   * {@inheritdoc}
   */
  public function build() {
    return [
      '#markup' => '<div>Hello, XB!</div>',
    ];
  }

}
