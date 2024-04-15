<?php

declare(strict_types=1);

namespace Drupal\experience_builder\Plugin\Field\FieldType;

use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\experience_builder\Plugin\Validation\Constraint\StringSemanticsConstraint;
use Drupal\file\Plugin\Field\FieldType\FileItem;

/**
 * @todo Fix upstream.
 */
class FileItemOverride extends FileItem {

  /**
   * {@inheritdoc}
   */
  public static function propertyDefinitions(FieldStorageDefinitionInterface $field_definition) {
    $properties = parent::propertyDefinitions($field_definition);
    $properties['description']->addConstraint('StringSemantics', StringSemanticsConstraint::PROSE);
    return $properties;
  }

}
