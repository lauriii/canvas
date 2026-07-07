<?php

declare(strict_types=1);

namespace Drupal\canvas\Utility;

use Drupal\canvas\Plugin\Validation\Constraint\UriSchemeConstraint;
use Drupal\Core\Cache\CacheableDependencyInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\Plugin\DataType\EntityReference;
use Drupal\Core\Field\FieldItemInterface;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\TypedDataManagerInterface;

/**
 * @internal
 */
final readonly class TypedDataHelper {

  /**
   * Whether a field/property definition explicitly opts in to being internal.
   *
   * `DataDefinitionInterface::isInternal()` cannot distinguish an explicit
   * `->setInternal(TRUE)` from the value it defaults to for computed
   * properties when left unset — both return `TRUE`. That makes it
   * impossible, via the interface alone, to respect an explicit internal
   * mark on a computed property (e.g. `DateTimeItemOverride` marking `date`
   * internal): `isInternal() && !isComputed()` is always `FALSE` for it.
   * Inspecting the raw definition array is the only way to tell them apart.
   *
   * @see \Drupal\Core\TypedData\DataDefinition::isInternal()
   */
  public static function isExplicitlyInternal(DataDefinitionInterface $definition): bool {
    return $definition instanceof DataDefinition && ($definition->toArray()['internal'] ?? NULL) === TRUE;
  }

  /**
   * Whether a field/property definition is genuinely internal.
   *
   * Stricter than `DataDefinitionInterface::isInternal()`, which returns TRUE
   * for *every* computed property (see `DataDefinition::isInternal()`: it
   * falls back to `isComputed()` when no `internal` flag is set). That
   * conflates a genuinely internal property with a merely computed one. This
   * method reports internal only when the mark is genuine — the definition is
   * internal AND either:
   * - it is not computed (so `isInternal()` cannot be the computed default), or
   * - it explicitly opts in via `->setInternal(TRUE)`, i.e.
   *   ::isExplicitlyInternal() returns TRUE.
   *
   * Two computed properties, both with `isInternal() === TRUE`, show why
   * ::isExplicitlyInternal() alone is not sufficient:
   * - `date` on a `datetime` field: DateTimeItemOverride marks it via
   *   `->setInternal(TRUE)`, so it is genuinely internal — both methods agree.
   * - `processed` on a `text` field: internal *only* because it is computed
   *   (nobody marked it), so it is not genuinely internal — both methods
   *   return FALSE.
   * The methods diverge on a *non-computed* internal definition that is not a
   * `DataDefinition` instance (e.g. a `FieldConfig`, a config entity that
   * implements the interface): ::isExplicitlyInternal() returns FALSE there
   * because of its `instanceof` guard, whereas this method still reports it
   * internal via `isInternal() && !isComputed()`.
   *
   * @see ::isExplicitlyInternal()
   * @see \Drupal\Core\TypedData\DataDefinition::isInternal()
   */
  public static function isEffectivelyInternal(DataDefinitionInterface $definition): bool {
    return $definition->isInternal() && (!$definition->isComputed() || self::isExplicitlyInternal($definition));
  }

  /**
   * Whether a `uri`-typed property is guaranteed to resolve to a browser URL.
   *
   * A UriSchemeConstraint restricted to a subset of http/https guarantees the
   * value is an absolute HTTP(S) URL — the only kind that is directly usable
   * in a browser (e.g. in an `<img src>` or `<a href>`). Properties lacking
   * this — e.g. the raw `uri` on a `link` field (which can be
   * `entity:node/1`), or the raw `value` on a `file_uri` field (a
   * stream-wrapper URI like `public://image.jpg`) — are not.
   *
   * @see \Drupal\canvas\Plugin\Validation\Constraint\UriSchemeConstraint
   */
  public static function isRestrictedToHttpSchemes(DataDefinitionInterface $property_definition): bool {
    $constraint = $property_definition->getConstraint(UriSchemeConstraint::PLUGIN_ID);
    $allowed_schemes = $constraint['allowedSchemes'] ?? [];
    return $allowed_schemes !== [] && \array_diff($allowed_schemes, ['http', 'https']) === [];
  }

  public static function conjureFieldItemObject(string $field_type): FieldItemInterface {
    $typed_data_manager = self::getTypedDataManger();
    $field_item_definition = $typed_data_manager->createDataDefinition("field_item:$field_type");
    $field_item = $typed_data_manager->createInstance("field_item:$field_type", [
      'name' => NULL,
      'parent' => NULL,
      'data_definition' => $field_item_definition,
    ]);
    \assert($field_item instanceof FieldItemInterface);
    return $field_item;
  }

  /**
   * Returns cacheability for a deleted referenced entity.
   *
   * When an entity reference field item's target has been deleted, the target
   * ID is still stored but the entity no longer loads. Any cached result that
   * depends on the (now-absent) entity must carry this cacheability so it is
   * invalidated if the entity is recreated at the same ID.
   *
   * TRICKY: imperfect; uses `entity_type_id:id` which is the default for most
   * entity types, but not all.
   *
   * @see \Drupal\Core\Entity\EntityBase::getCacheTagsToInvalidate()
   */
  public static function getDeletedReferencedEntityCacheability(EntityReference $reference): CacheableDependencyInterface {
    $target_id = $reference->getTargetIdentifier();
    $target_entity_type_id = $reference->getTargetDefinition()->getEntityTypeId();
    if ($target_id === NULL || $target_entity_type_id === NULL) {
      return new CacheableMetadata();
    }
    return (new CacheableMetadata())->addCacheTags([$target_entity_type_id . ':' . $target_id]);
  }

  private static function getTypedDataManger(): TypedDataManagerInterface {
    return \Drupal::typedDataManager();
  }

}
