<?php

declare(strict_types=1);

namespace Drupal\experience_builder;

final class DataTypeShapeRequirements {
  public function __construct(
    public readonly string $constraint,
    public readonly array $constraintOptions,
    // Restricting by interface makes sense in combination with \Drupal\Core\Validation\Plugin\Validation\Constraint\PrimitiveTypeConstraintValidator.
    public readonly ?string $interface = NULL
  ) {
    if ($this->constraint === 'PrimitiveType' && $interface === NULL) {
      throw new \DomainException('The `PrimitiveType` constraint is meaningless without an interface restriction.');
    }
    if ($this->interface !== NULL && $this->constraint !== 'PrimitiveType') {
      throw new \DomainException('An interface restriction only makes sense when the `PrimitiveType` constraint is used.');
    }
  }
}
