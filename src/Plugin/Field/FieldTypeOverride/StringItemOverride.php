<?php

declare(strict_types=1);

namespace Drupal\experience_builder\Plugin\Field\FieldTypeOverride;

use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\Field\Plugin\Field\FieldType\StringItem;
use Drupal\experience_builder\Plugin\Validation\Constraint\StringSemanticsConstraint;

/**
 * @todo Fix upstream.
 * @see \Drupal\experience_builder\Hook\ShapeMatchingHooks::entityBaseFieldInfoAlter()
 */
class StringItemOverride extends StringItem {

  /**
   * {@inheritdoc}
   */
  public static function propertyDefinitions(FieldStorageDefinitionInterface $field_definition) {
    $properties = parent::propertyDefinitions($field_definition);
    $properties['value']->addConstraint('StringSemantics', StringSemanticsConstraint::PROSE);
    return $properties;
  }

}
