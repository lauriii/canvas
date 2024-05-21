<?php

declare(strict_types=1);

namespace Drupal\experience_builder\Plugin\Field\FieldTypeOverride;

use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\experience_builder\Plugin\DataTypeOverride\TextProcessedOverride;
use Drupal\experience_builder\Plugin\Validation\Constraint\StringSemanticsConstraint;
use Drupal\text\Plugin\Field\FieldType\TextItem;

/**
 * @todo Fix upstream.
 */
class TextItemOverride extends TextItem {

  /**
   * {@inheritdoc}
   */
  public static function propertyDefinitions(FieldStorageDefinitionInterface $field_definition) {
    $properties = parent::propertyDefinitions($field_definition);
    $properties['processed']
      ->setClass(TextProcessedOverride::class)
      ->addConstraint('StringSemantics', StringSemanticsConstraint::MARKUP);
    return $properties;
  }

}
