<?php

namespace Drupal\experience_builder;

use Drupal\Core\Render\Element;

class RenderArrayXB {

  /**
   * Adds a TRUE #is_xb setting to all elements within a given $form.
   *
   * The #is_xb setting is then used by experience_builder_theme_suggestions_alter
   * to identify elements that should have suggestions for templates that will be
   * processed by the semi-coupled theme engine instead of Twig.
   *
   * @param array $render_array
   *   The render array to be marked #is_xb.
   */
  public static function markXB(array &$render_array): void {
    foreach (Element::children($render_array) as $child) {
      if (is_array($render_array[$child])) {
        $render_array[$child]['#is_xb'] = TRUE;
        self::markXB($render_array[$child]);
      }
    }
  }

  /**
   * Recursively checks a render array for elements with #is_xb.
   *
   * When #is_xb => TRUE is found, this will set all child elements #is_xb
   * also set to TRUE.
   *
   * @param array $render_array
   *   The render array to be processed.
   */
  public static function findAndMarkXB(array &$render_array): void {
    foreach (Element::children($render_array) as $child) {
      if (!empty($render_array[$child]['#is_xb'])) {
        self::markXB($render_array[$child]);
      }
      elseif (is_array($render_array[$child])) {
        self::findAndMarkXB($render_array[$child]);
      }
    }
  }

}
