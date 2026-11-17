<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\Validator\Attribute\HasNamedArguments;
use Symfony\Component\Validator\Constraint;

/**
 * Checks that the name is a unique string per Folder type.
 *
 * @internal
 */
#[\Drupal\Core\Validation\Attribute\Constraint(
  id: 'UniqueNamePerFolderTypeConstraint',
  label: new TranslatableMarkup('Unique name per Folder config entity type', [], ['context' => 'Validation']),
  type: ['string']
)]
final class UniqueNamePerFolderTypeConstraint extends Constraint {

  #[HasNamedArguments]
  public function __construct(
    public string $id,
    public string $configEntityTypeId,
    // The violation message when the name is already in use.
    public string $notUnique = 'Name %value is not unique in Folder type "%configEntityTypeId"',
    ?array $groups = NULL,
    mixed $payload = NULL,
  ) {
    parent::__construct(groups: $groups, payload: $payload);
  }

}
