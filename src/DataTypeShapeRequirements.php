<?php

declare(strict_types=1);

namespace Drupal\experience_builder;

final class DataTypeShapeRequirements {
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
