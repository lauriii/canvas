<?php

declare(strict_types=1);

namespace Drupal\experience_builder\Storage;

use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\experience_builder\Entity\ComponentTreeEntityInterface;
use Drupal\experience_builder\Entity\Page;
use Drupal\experience_builder\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\experience_builder\Plugin\Field\FieldType\ComponentTreeItemList;

/**
 * Handles loading a component tree from entities.
 */
final class ComponentTreeLoader {

  public function __construct(
    private readonly EntityFieldManagerInterface $entityFieldManager,
  ) {}

  /**
   * Loads a component tree from an entity.
   *
   * @param \Drupal\experience_builder\Entity\ComponentTreeEntityInterface|\Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The entity that stores the component tree. If it does not specifically
   *   implement ComponentTreeEntityInterface, then it is expected to be a
   *   fieldable entity with at least one field that stores a component tree.
   *
   * @return \Drupal\experience_builder\Plugin\Field\FieldType\ComponentTreeItemList
   */
  public function load(ComponentTreeEntityInterface|FieldableEntityInterface $entity): ComponentTreeItemList {
    if ($entity instanceof ComponentTreeEntityInterface) {
      return $entity->getComponentTree();
    }
    $field_name = $this->getXbFieldName($entity);
    $item = $entity->get($field_name);
    assert($item instanceof ComponentTreeItemList);
    return $item;
  }

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
  public function getXbFieldName(FieldableEntityInterface $entity): string {
    // @todo Remove this restriction once other entity types and bundles are
    //   tested in https://drupal.org/i/3498525.
    if ($entity->getEntityTypeId() !== Page::ENTITY_TYPE_ID && !($entity->getEntityTypeId() === 'node' && $entity->bundle() === 'article')) {
      throw new \LogicException('For now XB only works if the entity is an xb_page or an article node! Other entity types and bundles must be tested before they are supported, to help see https://drupal.org/i/3493675.');
    }

    $map = $this->entityFieldManager->getFieldMapByFieldType(ComponentTreeItem::PLUGIN_ID);

    foreach ($map[$entity->getEntityTypeId()] ?? [] as $field_name => $info) {
      if (in_array($entity->bundle(), $info['bundles'], TRUE)) {
        return $field_name;
      }
    }
    throw new \LogicException("This entity does not have an XB field!");
  }

}
