<?php

declare(strict_types=1);

namespace Drupal\experience_builder\Plugin\Field\FieldTypeOverride;

use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\Field\Plugin\Field\FieldType\StringLongItem;
use Drupal\experience_builder\Plugin\Validation\Constraint\StringSemanticsConstraint;

/**
 * @todo Fix upstream.
 */
class StringLongItemOverride extends StringLongItem {

  /**
   * {@inheritdoc}
   */
  public static function propertyDefinitions(FieldStorageDefinitionInterface $field_definition) {
    $properties = parent::propertyDefinitions($field_definition);
    $properties['value']->addConstraint('StringSemantics', StringSemanticsConstraint::PROSE);
    $properties['value']->addConstraint('Regex', [
      'pattern' => '/(.|\r?\n)*/',
    ]);
    return $properties;
  }

}
