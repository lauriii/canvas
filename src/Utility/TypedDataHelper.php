<?php

declare(strict_types=1);

namespace Drupal\canvas\Utility;

use Drupal\Core\Cache\CacheableDependencyInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\Plugin\DataType\EntityReference;
use Drupal\Core\Field\FieldItemInterface;
use Drupal\Core\TypedData\TypedDataManagerInterface;

/**
 * @internal
 */
final readonly class TypedDataHelper {

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
