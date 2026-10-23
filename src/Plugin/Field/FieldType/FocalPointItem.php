<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Field\FieldType;

use Drupal\Core\Field\Attribute\FieldType;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemBase;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinition;

/**
 * Defines the 'canvas_focal_point' field type.
 *
 * Stores a point on an image as percentages from its top left corner. Used to
 * store component props with the `focal-point` shape.
 *
 * @see schema.json
 * @see \Drupal\canvas\JsonSchemaInterpreter\JsonSchemaObjectRef::FocalPoint
 * @see \Drupal\canvas\Plugin\Field\FieldWidget\CanvasFocalPointWidget
 *
 * @property float|null $x
 * @property float|null $y
 */
#[FieldType(
  id: 'canvas_focal_point',
  label: new TranslatableMarkup('Focal point'),
  description: new TranslatableMarkup('A point on an image, as percentages from its top left corner.'),
  default_widget: 'canvas_focal_point',
  no_ui: TRUE,
)]
final class FocalPointItem extends FieldItemBase {

  /**
   * {@inheritdoc}
   */
  public static function propertyDefinitions(FieldStorageDefinitionInterface $field_definition): array {
    $properties['x'] = DataDefinition::create('float')
      ->setLabel(new TranslatableMarkup('Horizontal position'))
      ->setRequired(TRUE)
      ->addConstraint('Range', ['min' => 0, 'max' => 100]);
    $properties['y'] = DataDefinition::create('float')
      ->setLabel(new TranslatableMarkup('Vertical position'))
      ->setRequired(TRUE)
      ->addConstraint('Range', ['min' => 0, 'max' => 100]);
    return $properties;
  }

  /**
   * {@inheritdoc}
   */
  public static function mainPropertyName(): ?string {
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public static function schema(FieldStorageDefinitionInterface $field_definition): array {
    return [
      'columns' => [
        'x' => ['type' => 'float'],
        'y' => ['type' => 'float'],
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function isEmpty(): bool {
    // 0 is a valid coordinate (the left or top edge), so test for absence.
    return !\is_numeric($this->x) || !\is_numeric($this->y);
  }

  /**
   * {@inheritdoc}
   */
  public static function generateSampleValue(FieldDefinitionInterface $field_definition): array {
    return ['x' => 50.0, 'y' => 50.0];
  }

}
