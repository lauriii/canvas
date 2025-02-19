<?php

declare(strict_types=1);

namespace Drupal\experience_builder\Plugin\Field\FieldTypeOverride;

use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\experience_builder\Plugin\DataTypeOverride\ComputedFileUrlOverride;
use Drupal\experience_builder\Plugin\DataTypeOverride\UriOverride;
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
    $properties['value']
      ->setClass(UriOverride::class)
      // The `file_uri` field type stores a URI that uses a stream wrapper URI.
      // Avoid making this constraint depend on the installed stream wrappers by
      // simply stating that the scheme of this URI is NOT a browser-accessible
      // scheme like `http`, `https`, nor a root-relative URL.
      ->addConstraint('Regex', ['pattern' => "/^(?!https?:\/\/)[\w\-]+:\/\//"]);
    $properties['url']
      ->setClass(ComputedFileUrlOverride::class)
      // The `url` property is computed using the `value` property, which is
      // required. Hence this value is guaranteed to exist.
      ->setRequired(TRUE)
      // The ComputedFileUrl data type generates a browser-accessible URL.
      ->addConstraint('Regex', ['pattern' => "/^(\/|https?:\/\/)?/"]);
    return $properties;
  }

}
