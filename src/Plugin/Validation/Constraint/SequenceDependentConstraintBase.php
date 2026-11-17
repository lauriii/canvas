<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Validation\Constraint;

use Symfony\Component\Validator\Attribute\HasNamedArguments;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * @internal
 */
abstract class SequenceDependentConstraintBase extends SymfonyConstraint {

  #[HasNamedArguments]
  public function __construct(
    // The property path within the loaded config entity pointing to a
    // sequence.
    public string $propertyPathToSequence,
    // Every config entity type can have multiple instances, all with unique
    // IDs but the same config prefix. When config refers to a config entity,
    // typically only the ID is stored, not the prefix.
    // @see \Drupal\Core\Config\Plugin\Validation\Constraint\ConfigExistsConstraint::$prefix
    public string $configPrefix = '',
    // Optional config object containing a sequence whose keys must be
    // matched.
    public ?string $configName = NULL,
    ?array $groups = NULL,
    mixed $payload = NULL,
  ) {
    parent::__construct(groups: $groups, payload: $payload);
  }

}
