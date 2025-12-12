<?php

declare(strict_types=1);

namespace Drupal\canvas_test_storable_prop_shape_alter\Plugin\Field\FieldType;

use Drupal\Core\Field\Attribute\FieldType;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\Field\Plugin\Field\FieldType\NumericItemBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinition;

#[FieldType(
  id: self::PLUGIN_ID,
  label: new TranslatableMarkup("Multiple (integer)"),
  category: "number",
  weight: -50,
  default_widget: "number",
  default_formatter: "number_integer"
)]
final class MultipleOfItem extends NumericItemBase {

  public const PLUGIN_ID = 'multiple_of';

  /**
   * {@inheritdoc}
   */
  public static function defaultStorageSettings() {
    return ['must_be_divisible_by' => 2];
  }

  /**
   * {@inheritdoc}
   */
  public static function propertyDefinitions(FieldStorageDefinitionInterface $field_definition) {
    $properties['value'] = DataDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('Multiple'))
      ->setRequired(TRUE);

    return $properties;
  }

  /**
   * {@inheritdoc}
   */
  public static function schema(FieldStorageDefinitionInterface $field_definition) {
    return [
      'columns' => [
        'value' => [
          'type' => 'int',
        ],
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public static function generateSampleValue(FieldDefinitionInterface $field_definition) {
    $values['value'] = mt_rand(1, 10) * $field_definition->getSetting('must_be_divisible_by');
    return $values;
  }

}
