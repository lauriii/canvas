<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Validation\Constraint;

use Drupal\canvas\Entity\ContentTemplate;
use Drupal\canvas\Exception\SubtreeInjectionException;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItemList;
use Drupal\Core\Entity\FieldableEntityInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Validates that an entity's exposed slot content can merge into its template.
 *
 * Merges in the same order as rendering, so a collision with an earlier slot
 * is reported on the later slot's field.
 *
 * @see \Drupal\canvas\Plugin\Validation\Constraint\ExposedSlotContentIsMergeableConstraint
 */
final class ExposedSlotContentIsMergeableConstraintValidator extends ConstraintValidator {

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $value, Constraint $constraint): void {
    \assert($constraint instanceof ExposedSlotContentIsMergeableConstraint);
    if (!$value instanceof FieldableEntityInterface) {
      return;
    }
    // Only the full view mode can expose slots.
    // @see \Drupal\canvas\Plugin\Validation\Constraint\ValidExposedSlotConstraint::$viewMode
    $template = ContentTemplate::loadForEntity($value, 'full');
    if ($template === NULL || $template->getExposedSlots() === []) {
      return;
    }

    $merged_tree = $template->getComponentTree($value);
    foreach ($template->getExposedSlots() as $field_name => $definition) {
      if (!$value->hasField($field_name)) {
        continue;
      }
      $slot_field = $value->get($field_name);
      \assert($slot_field instanceof ComponentTreeItemList);
      try {
        $merged_tree->injectSlotContent($definition['component_uuid'], $definition['slot_name'], $slot_field);
      }
      catch (SubtreeInjectionException) {
        $this->context->buildViolation($constraint->message)
          ->setParameter('%field', $field_name)
          ->atPath($field_name)
          ->addViolation();
      }
    }
  }

}
