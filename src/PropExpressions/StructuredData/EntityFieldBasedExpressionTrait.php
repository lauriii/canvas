<?php

declare(strict_types=1);

namespace Drupal\canvas\PropExpressions\StructuredData;

/**
 * @see \Drupal\canvas\PropExpressions\StructuredData\EntityFieldBasedPropExpressionInterface
 * @internal
 */
trait EntityFieldBasedExpressionTrait {

  /**
   * @see \Drupal\canvas\PropExpressions\StructuredData\EntityFieldBasedPropExpressionInterface::hasSameStartingPointAs()
   */
  public function hasSameStartingPointAs(EntityFieldBasedPropExpressionInterface $other): bool {
    \assert($this instanceof EntityFieldBasedPropExpressionInterface);
    return $this->getHostEntityDataDefinition() == $other->getHostEntityDataDefinition()
      && $this->getFieldName() === $other->getFieldName()
      && $this->getDelta() === $other->getDelta();
  }

}
