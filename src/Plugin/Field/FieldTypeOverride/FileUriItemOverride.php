<?php

declare(strict_types=1);

namespace Drupal\experience_builder\Plugin\Field\FieldTypeOverride;

use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\experience_builder\Plugin\DataTypeOverride\ComputedFileUrlOverride;
use Drupal\file\Plugin\Field\FieldType\FileUriItem;

/**
 * @todo Fix upstream.
 */
class FileUriItemOverride extends FileUriItem {

  /**
   * {@inheritdoc}
   */
  public static function propertyDefinitions(FieldStorageDefinitionInterface $field_definition) {
    $properties = parent::propertyDefinitions($field_definition);
    $properties['url']
      ->setClass(ComputedFileUrlOverride::class)
      // The `url` property is computed using the `value` property, which is
      // required. Hence this value is guaranteed to exist.
      ->setRequired(TRUE);
    return $properties;
  }

}
