<?php

declare(strict_types=1);

namespace Drupal\canvas\PropExpressions\StructuredData;

use Drupal\canvas\TypedData\BetterEntityDataDefinition;

/**
 * @see \Drupal\canvas\PropExpressions\StructuredData\EntityFieldBasedPropExpressionInterface
 * @internal
 */
trait EntityFieldBasedExpressionTrait {

  /**
   * @see \Drupal\canvas\PropExpressions\StructuredData\EntityFieldBasedPropExpressionInterface::getStartingPointKey()
   */
  public function getStartingPointKey(): string {
    \assert($this instanceof EntityFieldBasedPropExpressionInterface);
    // Example: `entity:node:article|title|0` — first item of a node article's
    // title field.
    return \sprintf(
      '%s|%s|%s',
      $this->getHostEntityDataDefinition()->getDataType(),
      $this->getFieldName(),
      $this->getDelta() ?? '*',
    );
  }

  /**
   * @see \Drupal\canvas\PropExpressions\StructuredData\EntityFieldBasedPropExpressionInterface::getDeveloperFacingKey()
   */
  public function getDeveloperFacingKey(): string {
    \assert($this instanceof EntityFieldBasedPropExpressionInterface);
    $entity_type_and_bundle = $this->getHostEntityDataDefinition();
    \assert($entity_type_and_bundle instanceof BetterEntityDataDefinition);
    $field_names_to_entity_keys = \array_flip(
      $entity_type_and_bundle->getEntityType()->getKeys(),
    );
    $field_name = $this->getFieldName();
    $key = $field_names_to_entity_keys[$field_name] ?? $field_name;
    \assert(\preg_match('/^[a-z]+[a-z0-9_]*$/', $key) === 1);
    return $key;
  }

}
