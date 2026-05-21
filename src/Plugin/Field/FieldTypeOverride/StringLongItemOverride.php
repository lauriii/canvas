<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Field\FieldTypeOverride;

use Drupal\canvas\Plugin\Validation\Constraint\StringSemanticsConstraint;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\Field\Plugin\Field\FieldType\StringLongItem;

/**
 * @todo Fix upstream.
 */
class StringLongItemOverride extends StringLongItem {

  /**
   * {@inheritdoc}
   */
  public static function propertyDefinitions(FieldStorageDefinitionInterface $field_definition) {
    $properties = parent::propertyDefinitions($field_definition);
    $properties['value']->addConstraint('StringSemantics', [
      'semantic' => StringSemanticsConstraint::PROSE,
    ]);
    $properties['value']->addConstraint('Regex', [
      'pattern' => '/(.|\r?\n)*/',
    ]);
    return $properties;
  }

}
