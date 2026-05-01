<?php

declare(strict_types=1);

namespace Drupal\canvas\Controller;

use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Placeholder controller for config entities without traditional edit forms.
 *
 * @todo Remove this once linking to the Canvas Content Template editor UI is possible in https://www.drupal.org/i/3551708
 * @todo Remove this once linking to the Canvas Page Region "focus" editor UI is possible in https://www.drupal.org/i/3502765
 *
 * @internal
 */
final class NoUiConfigController {

  use StringTranslationTrait;

  /**
   * Returns a placeholder page for config entities managed via Canvas.
   *
   * @return array
   *   A render array.
   */
  public function placeholder(): array {
    return [
      '#markup' => $this->t('This configuration is managed through the Canvas UI.'),
    ];
  }

}
