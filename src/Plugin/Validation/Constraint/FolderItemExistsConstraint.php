<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\Validator\Attribute\HasNamedArguments;
use Symfony\Component\Validator\Constraint;

/**
 * Checks that a Folder item can be an item of the Folder's type.
 *
 * An item of a config entity type must exist. An item of a content entity type
 * must be a UUID, and does not have to exist: config is deployed between
 * environments that do not hold the same content.
 *
 * @internal
 */
#[\Drupal\Core\Validation\Attribute\Constraint(
  id: 'FolderItemExists',
  label: new TranslatableMarkup('Folder item exists.', [], ['context' => 'Validation']),
  type: ['string']
)]
final class FolderItemExistsConstraint extends Constraint {

  #[HasNamedArguments]
  public function __construct(
    public string $configEntityTypeId,
    // The same message as core's ConfigExists constraint, which this replaces.
    public string $configMissing = "The '@name' config does not exist.",
    public string $notUuid = "The '@item' folder item must be a UUID.",
    ?array $groups = NULL,
    mixed $payload = NULL,
  ) {
    parent::__construct(groups: $groups, payload: $payload);
  }

}
