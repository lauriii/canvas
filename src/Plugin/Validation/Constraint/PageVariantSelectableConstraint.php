<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * Requires a page variant selection to reference an enabled variant.
 *
 * Disabled variants keep rendering where they are already selected, but
 * cannot be selected anew. Pages enforce this through the options list on
 * their `page_variant` base field; this constraint is the equivalent for
 * config entities selecting a variant (currently content templates), whose
 * schema otherwise only checks the machine name format and existence.
 *
 * @see \Drupal\canvas\Entity\PageVariant::allowedValues()
 * @see \Drupal\canvas\Plugin\Validation\Constraint\SiteDefaultPageVariantEnabledConstraint
 */
#[Constraint(
  id: 'PageVariantSelectable',
  label: new TranslatableMarkup('Page variant is selectable', [], ['context' => 'Validation']),
)]
final class PageVariantSelectableConstraint extends SymfonyConstraint {

  public string $message = 'The page variant %variant is disabled and cannot be selected.';

}
