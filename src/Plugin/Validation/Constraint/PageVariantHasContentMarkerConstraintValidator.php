<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Validation\Constraint;

use Drupal\canvas\Plugin\Canvas\ComponentSource\Marker;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItemList;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Validates that a page variant tree has exactly one "Page content" marker.
 */
final class PageVariantHasContentMarkerConstraintValidator extends ConstraintValidator {

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $value, Constraint $constraint): void {
    \assert($constraint instanceof PageVariantHasContentMarkerConstraint);

    // Accept a ComponentTreeItemList (content context), a config sequence, or
    // null (an empty tree, which has zero markers).
    $items = match (TRUE) {
      $value instanceof ComponentTreeItemList => $value->getValue(),
      \is_array($value) => \array_values($value),
      default => [],
    };

    $marker_count = \count(\array_filter(
      \array_column($items, 'component_id'),
      static fn (string $component_id): bool => $component_id === Marker::PAGE_CONTENT_COMPONENT_ID,
    ));

    if ($marker_count === 0) {
      $this->context->buildViolation($constraint->missingMessage)->addViolation();
    }
    elseif ($marker_count > 1) {
      $this->context->buildViolation($constraint->multipleMessage)
        ->setParameter('@count', (string) $marker_count)
        ->addViolation();
    }
  }

}
