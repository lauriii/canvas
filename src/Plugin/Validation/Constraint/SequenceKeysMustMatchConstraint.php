<?php

declare(strict_types = 1);

namespace Drupal\experience_builder\Plugin\Validation\Constraint;

use Symfony\Component\Validator\Constraint;

/**
 * Checks the validated sequence contains the same keys as another sequence.
 *
 * @Constraint(
 *   id = "SequenceKeysMustMatch",
 *   label = @Translation("Sequence keys must match.", context = "Validation"),
 *   type = {"sequence"},
 * )
 */
final class SequenceKeysMustMatchConstraint extends Constraint {

  /**
   * Optional prefix, to be specified when this contains a config entity ID.
   *
   * Every config entity type can have multiple instances, all with unique IDs
   * but the same config prefix. When config refers to a config entity,
   * typically only the ID is stored, not the prefix.
   *
   * @see \Drupal\Core\Config\Plugin\Validation\Constraint\ConfigExistsConstraint::$prefix
   */
  public string $configPrefix = '';

  /**
   * The config object containing a sequence whose keys must be matched.
   */
  public ?string $configName;

  /**
   * The property path within the loaded config entity pointing to a sequence.
   */
  public ?string $propertyPathToSequence;

  /**
   * {@inheritdoc}
   */
  public function getRequiredOptions(): array {
    return [
      'configName',
      'propertyPathToSequence',
    ];
  }

}
