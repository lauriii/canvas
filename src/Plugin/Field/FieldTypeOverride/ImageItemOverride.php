<?php

declare(strict_types=1);

namespace Drupal\experience_builder\Plugin\Field\FieldTypeOverride;

use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\experience_builder\Plugin\Validation\Constraint\StringSemanticsConstraint;
use Drupal\image\Plugin\Field\FieldType\ImageItem;

/**
 * @todo Fix upstream.
 */
class ImageItemOverride extends ImageItem {

  /**
   * {@inheritdoc}
   */
  public static function defaultStorageSettings(): array {
    // @todo Remove once https://drupal.org/i/3513317 is fixed.
    return ['display_default' => TRUE] + parent::defaultStorageSettings();
  }

  /**
   * {@inheritdoc}
   */
  public static function propertyDefinitions(FieldStorageDefinitionInterface $field_definition) {
    $properties = parent::propertyDefinitions($field_definition);
    $properties['alt']->addConstraint('StringSemantics', StringSemanticsConstraint::PROSE);
    $properties['title']->addConstraint('StringSemantics', StringSemanticsConstraint::PROSE);
    return $properties;
  }

}
