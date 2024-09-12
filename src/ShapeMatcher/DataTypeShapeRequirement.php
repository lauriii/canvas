<?php

declare(strict_types=1);

namespace Drupal\experience_builder\ShapeMatcher;

/**
 * Describes a single shape requirement for a Drupal data type.
 *
 * @see \Drupal\Core\TypedData\Attribute\DataType
 * @see \Drupal\experience_builder\JsonSchemaInterpreter\DataTypeShapeRequirements
 */
final class DataTypeShapeRequirement {

  /**
   * @param array<mixed> $constraintOptions
   */
  public function __construct(
    public readonly string $constraint,
    public readonly array $constraintOptions,
    // Restricting by interface makes sense in combination with \Drupal\Core\Validation\Plugin\Validation\Constraint\PrimitiveTypeConstraintValidator.
    public readonly ?string $interface = NULL,
  ) {
    if ($this->constraint === 'PrimitiveType' && $interface === NULL) {
      throw new \DomainException('The `PrimitiveType` constraint is meaningless without an interface restriction.');
    }
    if ($this->interface !== NULL && $this->constraint !== 'PrimitiveType') {
      throw new \DomainException('An interface restriction only makes sense when the `PrimitiveType` constraint is used.');
    }
  }

}
