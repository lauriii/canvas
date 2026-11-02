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
 * Stores an object with scalar properties, defined by a JSON schema.
 *
 * Each JSON schema property becomes a field property, so a component prop of
 * `type: object` (or `type: array` of those) maps 1:1 onto field items: the
 * stored value is exactly the value the component receives.
 *
 * Only used for static prop sources: it is never used for entity fields.
 *
 * @see \Drupal\canvas\JsonSchemaInterpreter\JsonSchemaType::computeStorablePropShape()
 * @see \Drupal\canvas\Plugin\Field\FieldWidget\CanvasObjectWidget
 * @see docs/shape-matching.md#3.2.4
 *
 * @internal
 */
#[FieldType(
  id: self::PLUGIN_ID,
  label: new TranslatableMarkup('Object'),
  description: new TranslatableMarkup('An object with scalar properties, for component props.'),
  default_widget: self::PLUGIN_ID,
  no_ui: TRUE,
)]
final class CanvasObjectItem extends FieldItemBase {

  public const string PLUGIN_ID = 'canvas_object';

  /**
   * {@inheritdoc}
   */
  public static function defaultStorageSettings(): array {
    return [
      // The JSON schema of each object property, keyed by property name.
      'properties' => [],
      // The names of the required object properties.
      'required' => [],
    ] + parent::defaultStorageSettings();
  }

  /**
   * {@inheritdoc}
   */
  public static function propertyDefinitions(FieldStorageDefinitionInterface $field_definition): array {
    $definitions = [];
    $required = $field_definition->getSetting('required') ?? [];
    foreach ($field_definition->getSetting('properties') ?? [] as $name => $schema) {
      $definitions[$name] = DataDefinition::create(match ($schema['type']) {
        'number' => 'float',
        default => $schema['type'],
      })
        ->setLabel($name)
        ->setRequired(\in_array($name, $required, TRUE));
    }
    return $definitions;
  }

  /**
   * {@inheritdoc}
   */
  public static function mainPropertyName(): ?string {
    // The properties depend on the storage settings.
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public static function schema(FieldStorageDefinitionInterface $field_definition): array {
    $columns = [];
    foreach ($field_definition->getSetting('properties') ?? [] as $name => $schema) {
      $columns[$name] = match ($schema['type']) {
        'string' => ['type' => 'text', 'size' => 'big'],
        'integer' => ['type' => 'int', 'size' => 'big'],
        'number' => ['type' => 'float', 'size' => 'big'],
        'boolean' => ['type' => 'int', 'size' => 'tiny'],
        default => throw new \LogicException(\sprintf('Unsupported property type for "%s".', $name)),
      };
    }
    return ['columns' => $columns];
  }

  /**
   * {@inheritdoc}
   */
  public function isEmpty(): bool {
    // An item without any value other than '' or FALSE is empty: that is what
    // an unfilled set of form elements submits.
    foreach ($this->getValue() as $value) {
      if (!\in_array($value, [NULL, '', FALSE], TRUE)) {
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public static function generateSampleValue(FieldDefinitionInterface $field_definition): array {
    $value = [];
    foreach ($field_definition->getSetting('properties') ?? [] as $name => $schema) {
      $value[$name] = match (TRUE) {
        \array_key_exists('enum', $schema) => $schema['enum'][0],
        $schema['type'] === 'string' => match ($schema['format'] ?? NULL) {
          'email', 'idn-email' => 'person@example.com',
          'date' => '2025-01-01',
          NULL => 'Lorem ipsum',
          default => 'https://example.com',
        },
        $schema['type'] === 'boolean' => TRUE,
        default => $schema['minimum'] ?? 1,
      };
    }
    return $value;
  }

}
