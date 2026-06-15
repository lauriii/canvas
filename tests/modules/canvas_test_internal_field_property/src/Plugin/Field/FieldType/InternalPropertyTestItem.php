<?php

declare(strict_types=1);

namespace Drupal\canvas_test_internal_field_property\Plugin\Field\FieldType;

use Drupal\Core\Field\Attribute\FieldType;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\Field\Plugin\Field\FieldType\StringItem;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinition;

/**
 * A string field type with an extra internal (non-computed) `secret` property.
 *
 * No core field type marks a non-computed property internal, so this fixture
 * exercises EntityFieldExpressionMustNotTargetInternalProperty at the field
 * property level.
 */
#[FieldType(
  id: self::PLUGIN_ID,
  label: new TranslatableMarkup("Canvas test: internal property"),
  category: "test",
  no_ui: TRUE,
)]
final class InternalPropertyTestItem extends StringItem {

  public const PLUGIN_ID = 'canvas_test_internal_property';

  /**
   * {@inheritdoc}
   */
  public static function propertyDefinitions(FieldStorageDefinitionInterface $field_definition) {
    $properties = parent::propertyDefinitions($field_definition);
    $properties['secret'] = DataDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Secret'))
      ->setInternal(TRUE);
    return $properties;
  }

  /**
   * {@inheritdoc}
   */
  public static function schema(FieldStorageDefinitionInterface $field_definition) {
    $schema = parent::schema($field_definition);
    $schema['columns']['secret'] = [
      'type' => 'varchar',
      'length' => 255,
    ];
    return $schema;
  }

}
