<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * Requires a `path` field item's alias to be storable as a valid path alias.
 *
 * Core validates path aliases only in its path widget, so aliases set through
 * other means (such as Canvas' HTTP API) are saved unvalidated: duplicates and
 * aliases without a leading slash are stored as-is. Neither does the widget
 * reject an alias consisting of only slashes, which is stored as a path alias
 * with a NULL alias. This constraint validates the path alias that saving the
 * field would create or update instead, normalized as it would be stored.
 *
 * @see \Drupal\path\Plugin\Field\FieldWidget\PathWidget::validateFormElement()
 * @see \Drupal\path\Plugin\Field\FieldType\PathItem::postSave()
 *
 * @todo Remove when https://www.drupal.org/i/3638830 lands.
 */
#[Constraint(
  id: 'StorablePathAlias',
  label: new TranslatableMarkup('Storable path alias', [], ['context' => 'Validation']),
)]
final class StorablePathAliasConstraint extends SymfonyConstraint {

  public string $slashOnlyMessage = 'The alias %alias cannot consist only of slashes.';

}
