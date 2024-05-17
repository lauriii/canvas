<?php

declare(strict_types=1);

namespace Drupal\experience_builder;

/**
 * Describes a set of shape requirements for a Drupal data type.
 *
 * @see \Drupal\experience_builder\DataTypeShapeRequirement
 */
final class DataTypeShapeRequirements {

  /**
   * @param \Drupal\experience_builder\DataTypeShapeRequirement[] $requirements
   */
  public function __construct(
    public readonly array $requirements,
  ) {
    foreach ($this->requirements as $requirement) {
      if (!$requirement instanceof DataTypeShapeRequirement) {
        throw new \LogicException();
      }
    }
  }

}
