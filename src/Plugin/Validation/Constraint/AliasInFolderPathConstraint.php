<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * Requires the alias of a page to start with the URL path of its folder.
 *
 * A page in a folder that has no URL path, or in no folder, can have any
 * alias.
 *
 * @see docs/adr/0027-page-folders-can-set-the-url-path-of-their-pages.md
 */
#[Constraint(
  id: 'AliasInFolderPath',
  label: new TranslatableMarkup('Alias is in the URL path of the folder', [], ['context' => 'Validation']),
)]
final class AliasInFolderPathConstraint extends SymfonyConstraint {

  public string $message = 'The URL alias of a page in the %folder folder must start with %path.';

}
