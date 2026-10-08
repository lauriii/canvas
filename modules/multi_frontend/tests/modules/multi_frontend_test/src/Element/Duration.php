<?php

declare(strict_types=1);

namespace Drupal\multi_frontend_test\Element;

use Drupal\Core\Render\Attribute\FormElement;
use Drupal\Core\Render\Element\FormElementBase;
use Drupal\multi_frontend\Form\JsonSchemaFormElementInterface;

/**
 * A contrib-style element the describer has never heard of.
 *
 * It exists to prove the inversion: nothing in FormDescriber knows this type,
 * and it is still published, because the element answers for itself.
 */
#[FormElement('multi_frontend_test_duration')]
final class Duration extends FormElementBase implements JsonSchemaFormElementInterface {

  /**
   * {@inheritdoc}
   */
  public function getInfo(): array {
    return [
      '#input' => TRUE,
      '#theme' => 'input__textfield',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public static function toJsonSchema(array $element): ?array {
    return [
      'type' => 'string',
      'pattern' => '^P(?!$)(\d+Y)?(\d+M)?(\d+D)?(T(?=\d)(\d+H)?(\d+M)?(\d+S)?)?$',
      'description' => 'An ISO 8601 duration.',
    ];
  }

}
