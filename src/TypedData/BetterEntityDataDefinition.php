<?php

declare(strict_types=1);

namespace Drupal\canvas\TypedData;

use Drupal\Core\Entity\TypedData\EntityDataDefinition;
use Drupal\Core\Entity\TypedData\EntityDataDefinitionInterface;
use Drupal\Core\TypedData\ComplexDataDefinitionBase;

/**
 * @todo Fix upstream in https://www.drupal.org/node/2169813. Even though EntityDataDefinitionInterface::setBundles() supports >1 bundle, core's concrete implementation supports only 1. Subclassing was impossible because ::create() imposes $bundles to be a string.
 */
class BetterEntityDataDefinition extends ComplexDataDefinitionBase implements EntityDataDefinitionInterface {

  /**
   * {@inheritdoc}
   */
  public static function create($entity_type_id = NULL, null|string|array $bundles = NULL) {
    // If the entity type is known, use the derived definition.
    if (isset($entity_type_id)) {
      $data_type = "entity:{$entity_type_id}";

      // If >=1 bundle was given, use the bundle-specific definition.
      if ($bundles) {
        $bundles = (array) $bundles;
        sort($bundles);
        $data_type .= ':' . implode('|', $bundles);
      }

      // It's possible that the given entity type ID or bundle wasn't discovered
      // by the TypedData plugin manager and/or weren't created by the
      // EntityDeriver. In that case, this is a new definition and we'll just
      // create the definition from defaults by using an empty array.
      $values = \Drupal::typedDataManager()->getDefinition($data_type, FALSE);
      $definition = new static(is_array($values) ? $values : []);

      // Set the EntityType constraint using the given entity type ID.
      $definition->setEntityTypeId($entity_type_id);

      // If available, set the Bundle constraint.
      if ($bundles) {
        $definition->setBundles($bundles);
      }

      return $definition;
    }

    return new static([]);
  }

  /**
   * {@inheritdoc}
   */
  public static function createFromDataType($data_type) {
    $parts = explode(':', $data_type);
    if ($parts[0] != 'entity') {
      throw new \InvalidArgumentException('Data type must be in the form of "entity:ENTITY_TYPE:BUNDLE."');
    }
    $bundles = isset($parts[2])
      ? explode('|', $parts[2])
      : NULL;
    return static::create($parts[1] ?? NULL, $bundles);
  }

  /**
   * {@inheritdoc}
   */
  public function getPropertyDefinitions() {
    if (!isset($this->propertyDefinitions)) {
      if ($entity_type_id = $this->getEntityTypeId()) {
        // Return an empty array for entities that are not content entities.
        $entity_type = \Drupal::entityTypeManager()->getDefinition($entity_type_id);
        $entity_type_class = $entity_type->getClass();
        if (!in_array('Drupal\Core\Entity\FieldableEntityInterface', class_implements($entity_type_class))) {
          $this->propertyDefinitions = [];
        }
        else {
          // @todo Add support for handling multiple bundles.
          // See https://www.drupal.org/node/2169813.
          $bundles = $this->getBundles();
          $specific_bundle = (is_array($bundles) && count($bundles) == 1) ? reset($bundles) : NULL;
          if ($specific_bundle === NULL && !$entity_type->hasKey('bundle')) {
            $specific_bundle = $entity_type_id;
          }
          if ($specific_bundle !== NULL) {
            $this->propertyDefinitions = \Drupal::service('entity_field.manager')->getFieldDefinitions($entity_type_id, $specific_bundle);
          }
          else {
            $this->propertyDefinitions = \Drupal::service('entity_field.manager')->getBaseFieldDefinitions($entity_type_id);
          }
        }
      }
      else {
        // No entity type given.
        $this->propertyDefinitions = [];
      }
    }
    return $this->propertyDefinitions;
  }

  /**
   * {@inheritdoc}
   */
  public function getDataType() {
    $type = 'entity';
    if ($entity_type = $this->getEntityTypeId()) {
      $type .= ':' . $entity_type;
      $bundles = $this->getBundles();
      if ($bundles === NULL) {
        return $type;
      }

      // Append a sole bundle only if we know it for sure and it is not the
      // default bundle.
      if (count($bundles) == 1) {
        $bundle = reset($bundles);
        if ($bundle != $entity_type) {
          $type .= ':' . $bundle;
        }
      }
      elseif (count($bundles) > 1) {
        $type .= ':' . implode('|', $bundles);
      }
    }
    return $type;
  }

  /**
   * {@inheritdoc}
   */
  public function getEntityTypeId() {
    return $this->definition['constraints']['EntityType'] ?? NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function setEntityTypeId($entity_type_id) {
    return $this->addConstraint('EntityType', $entity_type_id);
  }

  /**
   * {@inheritdoc}
   */
  public function getBundles() {
    $bundle = $this->definition['constraints']['Bundle'] ?? NULL;
    return is_string($bundle) ? [$bundle] : $bundle;
  }

  /**
   * {@inheritdoc}
   */
  public function setBundles(?array $bundles = NULL) {
    if (isset($bundles)) {
      $this->addConstraint('Bundle', $bundles);
      $this->propertyDefinitions = NULL;
    }
    else {
      // Remove the constraint.
      unset($this->definition['constraints']['Bundle']);
    }
    return $this;
  }

  public static function createFromBuggyInCoreEntityDataDefinition(EntityDataDefinition $def): static {
    $better = static::create($def->getEntityTypeId(), $def->getBundles());
    foreach ($def->getConstraints() as $constraint_name => $options) {
      if ($constraint_name === 'EntityType') {
        continue;
      }
      if ($constraint_name === 'Bundle') {
        continue;
      }
      $better->addConstraint($constraint_name, $options);
    }
    return $better;
  }

}
