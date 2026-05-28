<?php

declare(strict_types=1);

namespace Drupal\canvas_test_block\Plugin\Block;

// cspell:ignore gitane

use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;

#[Block(
  id: self::PLUGIN_ID,
  admin_label: new TranslatableMarkup("Canvas Test Block for testing input translatability"),
)]
final class CanvasTestBlockInputTranslatability extends BlockBase {

  public const string PLUGIN_ID = 'canvas_test_block_input_translatability';

  public const DEFAULT_CONFIGURATION = [
    'top_level_translatable_regardless_of_type' => [
      'translations', 'are', 'hard',
      'especially', 'without', 'schema',
    ],
    'deeply_nested_translatable' => [
      [
        'foo' => 'Huh?',
        'bar' => 'Gitane',
      ],
    ],
  ];

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return self::DEFAULT_CONFIGURATION;
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    return [
      '#markup' => $this->t('First bar: @bar', ['@bar' => $this->configuration['deeply_nested_translatable'][0]['bar']]),
    ];
  }

}
