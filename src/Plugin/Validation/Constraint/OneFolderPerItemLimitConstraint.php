<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\Validator\Attribute\HasNamedArguments;
use Symfony\Component\Validator\Constraint;

/**
 * Checks that the Folder Item isn't assigned to a different Folder already.
 *
 * @internal
 */
#[\Drupal\Core\Validation\Attribute\Constraint(
  id: 'OneFolderPerItemLimitConstraint',
  label: new TranslatableMarkup('Item can only be assigned to one Folder.', [], ['context' => 'Validation']),
  type: ['string']
)]
final class OneFolderPerItemLimitConstraint extends Constraint {

  #[HasNamedArguments]
  public function __construct(
    public string $id,
    public string $configEntityTypeId,
    // The violation message when the limit is violated.
    public string $limitViolated = 'Folder item %item_id is already assigned to folder %folder_name',
    ?array $groups = NULL,
    mixed $payload = NULL,
  ) {
    parent::__construct(groups: $groups, payload: $payload);
  }

}
