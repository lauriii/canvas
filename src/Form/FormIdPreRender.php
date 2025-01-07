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

  /**
   * Adds a data-ajax attribute to elements.
   *
   * @param array $element
   *   Element to add attribute to.
   * @param string $form_id
   *   Form ID.
   */
  public static function addAjaxAttribute(array &$element, string $form_id): void {
    $element['#attributes']['data-ajax'] = TRUE;
    $element['#attributes']['data-form-id'] = $form_id;
    foreach (Element::children($element) as $key) {
      self::addAjaxAttribute($element[$key], $form_id);
    }
  }

}
