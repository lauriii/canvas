<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Field\FieldTypeOverride;

use Drupal\canvas\Plugin\Validation\Constraint\UriConstraint;
use Drupal\canvas\Plugin\Validation\Constraint\UriSchemeConstraint;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\canvas\Plugin\DataTypeOverride\ComputedFileUrlOverride;
use Drupal\canvas\Plugin\DataTypeOverride\UriOverride;
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
      // @todo should respect the `uri_scheme` field storage setting of \Drupal\file\Plugin\Field\FieldType\FileItem
      // @see \Drupal\file\Plugin\Field\FieldType\FileItem::defaultStorageSettings()
      ->addConstraint(UriConstraint::PLUGIN_ID, ['allowReferences' => FALSE])
      ->addConstraint(UriSchemeConstraint::PLUGIN_ID, ['public']);
    $properties['url']
      ->setClass(ComputedFileUrlOverride::class)
      // The `url` property is computed using the `value` property, which is
      // required. Hence this value is guaranteed to exist.
      ->setRequired(TRUE)
      // The ComputedFileUrl data type generates a browser-accessible URL (root-
      // relative, absolute using HTTP, absolute using HTTPs or relative).
      ->addConstraint(UriConstraint::PLUGIN_ID, ['allowReferences' => TRUE])
      ->addConstraint(UriSchemeConstraint::PLUGIN_ID, ['http', 'https']);
    return $properties;
  }

}
