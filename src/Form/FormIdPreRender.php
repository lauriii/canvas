<?php

declare(strict_types=1);

namespace Drupal\experience_builder\Form;

use Drupal\Core\Render\Element;
use Drupal\Core\Security\Attribute\TrustedCallback;

/**
 * Defines a pre-render method for adding form ID to elements.
 */
final class FormIdPreRender {

  /**
   * Pre-render callback to add form ID to each form element.
   *
   * @param array $element
   *   Array element.
   */
  #[TrustedCallback]
  public static function addFormId(array $element): array {
    $form_id = $element['#attributes']['data-form-id'];
    foreach (Element::children($element) as $child) {
      $element[$child]['#attributes']['data-form-id'] = $form_id;
      $element[$child] = self::addFormId($element[$child]);
    }
    return $element;
  }

}
