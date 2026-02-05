<?php

declare(strict_types=1);

namespace Drupal\canvas\PropShape;

use Drupal\canvas\JsonSchemaInterpreter\JsonSchemaType;
use Drupal\canvas\PropExpressions\StructuredData\FieldTypeBasedPropExpressionInterface;
use Drupal\canvas\PropSource\StaticPropSource;

/**
 * A storable prop shape: a prop shape with corresponding field type + widget.
 *
 * @see \Drupal\canvas\JsonSchemaInterpreter\JsonSchemaType::computeStorablePropShape()
 * @internal
 */
final class StorablePropShape {

  /**
   * The default cardinality for StorablePropShapes and StaticPropSources.
   *
   * Inspired by core's defaults.
   *
   * @see \Drupal\Core\Field\BaseFieldDefinition::getCardinality()
   */
  public const DEFAULT_CARDINALITY = 1;

  /**
   * The default cardinality for StorablePropShapes and StaticPropSources.
   *
   * Inspired by core's defaults.
   *
   * @see \Drupal\Core\Field\FieldItemBase::defaultStorageSettings())
   */
  public const DEFAULT_STORAGE_SETTINGS = [];

  /**
   * The default cardinality for StorablePropShapes and StaticPropSources.
   *
   * Inspired by core's defaults.
   *
   * @see \Drupal\Core\Field\FieldItemBase::defaultFieldSettings())
   */
  public const DEFAULT_INSTANCE_SETTINGS = [];

  /**
   * @param \Drupal\Core\Field\FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED|int<1, max>|null $cardinality
   */
  public function __construct(
    public readonly PropShape $shape,
    // The corresponding UX for the prop shape:
    // - field type to use + which field properties to extract from an instance
    // of the field type
    public readonly FieldTypeBasedPropExpressionInterface $fieldTypeProp,
    // - which widget to use to populate an instance of the field type
    public readonly string $fieldWidget,
    // - (optionally) which cardinality to use in case of a list (`type: array`)
    // @see \Drupal\Core\Field\FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED
    public readonly ?int $cardinality = NULL,
    // - (optionally) which storage settings to specify for an instance of this
    //   field type — crucial for e.g. the `enum` use case
    public readonly ?array $fieldStorageSettings = NULL,
    // - (optionally) which storage settings to specify for an instance of this
    //   field type — necessary for the `entity_reference` field type
    public readonly ?array $fieldInstanceSettings = NULL,
  ) {
    if ($this->shape->resolvedSchema['type'] === JsonSchemaType::Array->value) {
      match ($this->cardinality) {
        NULL => throw new \LogicException('Array prop shapes MUST have a cardinality.'),
        0 => throw new \OutOfRangeException('Nonsensical cardinality of zero for an array prop shape.'),
        1 => throw new \OutOfRangeException('Nonsensical cardinality of one for an array prop shape.'),
        default => NULL,
      };
    }
    elseif ($this->cardinality !== NULL) {
      // Non-array prop shapes can only have one cardinality: 1. While
      // meaningless, it is also harmless.
      // @see https://en.wikipedia.org/wiki/Robustness_principle
      \assert($this->cardinality === 1);
    }
    // In theory, this could be validated: $this->fieldTypeProp->getFieldType()
    // is a field type plugin ID, which determines which field widgets
    // (`$this->fieldWidget`) would be acceptable, and what
    // `$this->fieldStorageSettings`, if any, would be acceptable.
    // In practice, we leave this to the Component config entity, because that
    // is where these values of the StorablePropShape object are persisted.
    // @see \Drupal\canvas\Entity\Component
    // @see `type: canvas.component.*`.
  }

  public function toStaticPropSource(): StaticPropSource {
    return StaticPropSource::generate($this->fieldTypeProp, $this->cardinality, $this->fieldStorageSettings, $this->fieldInstanceSettings);
  }

  /**
   * Checks (field) data compatibility with a target ("new") StorablePropShape.
   *
   * @param \Drupal\canvas\PropShape\StorablePropShape $target
   *   The target StorablePropShape to compare against: the "new" storable
   *   shape.
   *
   * @return bool
   *   TRUE if data compatible (i.e. allows a superset), FALSE otherwise.
   */
  public function fieldDataFitsIn(StorablePropShape $target): bool {
    // A field type mismatch means incompatible data.
    if ($this->fieldTypeProp->getFieldType() !== $target->fieldTypeProp->getFieldType()) {
      return FALSE;
    }

    // Cardinality, field storage settings and field instance settings all are
    // optional. If not specified, they default to the respective defaults.
    if (($this->cardinality ?? self::DEFAULT_CARDINALITY) !== ($target->cardinality ?? self::DEFAULT_CARDINALITY)) {
      return FALSE;
    }
    if (($this->fieldStorageSettings ?? self::DEFAULT_STORAGE_SETTINGS) !== ($target->fieldStorageSettings ?? self::DEFAULT_STORAGE_SETTINGS)) {
      return FALSE;
    }
    if (($this->fieldInstanceSettings ?? self::DEFAULT_INSTANCE_SETTINGS) !== ($target->fieldInstanceSettings ?? self::DEFAULT_INSTANCE_SETTINGS)) {
      // Entity reference fields are special: their instance settings
      // contain the allowed target bundles, which can be a superset.
      // @todo Consider allowing contributed modules to provide similar logic for other field types in https://www.drupal.org/project/canvas/issues/3571366.
      if ($this->fieldTypeProp->getFieldType() === 'entity_reference') {
        \assert($target->fieldTypeProp->getFieldType() === 'entity_reference');
        // @see \Drupal\Core\Field\Plugin\Field\FieldType\EntityReferenceItem::getReferenceableBundles()
        $this_bundles = array_values($this->fieldInstanceSettings['handler_settings']['target_bundles'] ?? []);
        $target_bundles = array_values($target->fieldInstanceSettings['handler_settings']['target_bundles'] ?? []);
        if (empty(array_diff($this_bundles, $target_bundles))) {
          // The target is a superset: field data in $this would fit in $target!
          return TRUE;
        }
      }
      return FALSE;
    }

    return TRUE;
  }

}
