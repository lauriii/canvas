<?php

declare(strict_types=1);

namespace Drupal\canvas\PropExpressions\StructuredData;

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

}
