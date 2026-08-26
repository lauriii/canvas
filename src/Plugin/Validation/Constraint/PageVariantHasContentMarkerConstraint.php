<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * Requires exactly one "Page content" marker in a page variant's tree.
 *
 * The existing ComponentTreeMeetRequirements constraint cannot express this:
 * its `presence` check is set-membership, not a count.
 *
 * @see \Drupal\canvas\Plugin\Canvas\ComponentSource\Marker::PAGE_CONTENT_COMPONENT_ID
 */
#[Constraint(
  id: 'PageVariantHasContentMarker',
  label: new TranslatableMarkup('Page variant has exactly one content placement', [], ['context' => 'Validation']),
)]
final class PageVariantHasContentMarkerConstraint extends SymfonyConstraint {

  public string $missingMessage = 'A page variant must contain a "Page content" placement.';

  public string $multipleMessage = 'A page variant must contain only one "Page content" placement, but found @count.';

}
