<?php

declare(strict_types=1);

namespace Drupal\experience_builder\Plugin\Field\FieldTypeOverride;

use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\path\Plugin\Field\FieldType\PathItem;

/**
 * @todo Fix upstream.
 */
class PathItemOverride extends PathItem {

  /**
   * {@inheritdoc}
   */
  public static function propertyDefinitions(FieldStorageDefinitionInterface $field_definition) {
    $properties = parent::propertyDefinitions($field_definition);
    $properties['alias']->addConstraint('ValidPath');
    return $properties;
  }

}
