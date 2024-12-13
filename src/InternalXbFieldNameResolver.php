<?php

declare(strict_types=1);

namespace Drupal\experience_builder;

use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\experience_builder\Plugin\Field\FieldType\ComponentTreeItem;

/**
 * Provides a method to get the XB field name from an entity.
 *
 * @internal
 */
class InternalXbFieldNameResolver {

  /**
   * Gets the XB field name from the entity.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The entity.
   *
   * @return string
   *   The XB field name, or throws an exception
   *   if not found or not supported entity type/bundle.
   *
   * @throws \LogicException
   */
  public static function getXbFieldName(FieldableEntityInterface $entity): string {
    // @todo Remove this restriction once other entity types and bundles are
    //   tested in https://drupal.org/i/3493675.
    if ($entity->getEntityTypeId() !== 'xb_page' && !($entity->getEntityTypeId() === 'node' && $entity->bundle() === 'article')) {
      throw new \LogicException('For now XB only works if the entity is an xb_page or an article node! Other entity types and bundles must be tested before they are supported, to help see https://drupal.org/i/3493675.');
    }
    $field_definitions = $entity->getFieldDefinitions();
    foreach ($field_definitions as $field_name => $field_definition) {
      if (is_a($field_definition->getItemDefinition()->getClass(), ComponentTreeItem::class, TRUE)) {
        return $field_name;
      }
    }
    throw new \LogicException("This entity does not have an XB field!");
  }

}
