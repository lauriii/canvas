<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * Requires the site default page variant to stay enabled.
 *
 * Disabled variants keep rendering where they are already selected, but
 * cannot be selected anew; a disabled site default would make every new page
 * fall back to a variant nobody can pick.
 *
 * @see \Drupal\canvas\Entity\PageVariant::DEFAULT_SETTING
 * @see \Drupal\canvas\EntityHandlers\PageVariantAccessControlHandler
 */
#[Constraint(
  id: 'SiteDefaultPageVariantEnabled',
  label: new TranslatableMarkup('Site default page variant is enabled', [], ['context' => 'Validation']),
)]
final class SiteDefaultPageVariantEnabledConstraint extends SymfonyConstraint {

  public string $message = 'The site default page variant cannot be disabled. Set another variant as the default first.';

}
