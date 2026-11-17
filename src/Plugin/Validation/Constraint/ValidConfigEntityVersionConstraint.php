<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Attribute\HasNamedArguments;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * Defines a constraint to validate a configuration entity has a given version.
 *
 * @see \Drupal\canvas\Entity\VersionedConfigEntityInterface::getVersions()
 */
#[Constraint(
  id: self::PLUGIN_ID,
  label: new TranslatableMarkup('Valid config entity version', [], ['context' => 'Validation']),
)]
final class ValidConfigEntityVersionConstraint extends SymfonyConstraint {

  public const string PLUGIN_ID = 'ValidConfigEntityVersion';

  #[HasNamedArguments]
  public function __construct(
    public string $configName,
    // Every config entity type can have multiple instances, all with unique
    // IDs but the same config prefix. When config refers to a config entity,
    // typically only the ID is stored, not the prefix.
    // @see \Drupal\Core\Config\Plugin\Validation\Constraint\ConfigExistsConstraint::$prefix
    public string $configPrefix = '',
    public string $message = "'@version' is not a version that exists on @entity_type config entity '@entity_id'. Available versions: '@available_versions'.",
    ?array $groups = NULL,
    mixed $payload = NULL,
  ) {
    parent::__construct(groups: $groups, payload: $payload);
  }

}
