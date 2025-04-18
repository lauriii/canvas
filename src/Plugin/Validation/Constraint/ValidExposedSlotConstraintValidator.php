<?php

declare(strict_types=1);

namespace Drupal\experience_builder\Plugin\Validation\Constraint;

use Drupal\experience_builder\ComponentSource\ComponentSourceWithSlotsInterface;
use Drupal\experience_builder\Entity\ContentTemplate;
use Drupal\experience_builder\Plugin\DataType\ComponentTreeStructure;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

/**
 * Validates the `ValidExposedSlot` constraint.
 */
final class ValidExposedSlotConstraintValidator extends ConstraintValidator {

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $value, Constraint $constraint): void {
    assert($constraint instanceof ValidExposedSlotConstraint);

    assert(is_array($value), new UnexpectedTypeException($value, 'array'));

    // The root UUID (i.e., the entire component tree) cannot be exposed.
    if ($value['component_uuid'] === ComponentTreeStructure::ROOT_UUID) {
      $this->context->addViolation($constraint->rootExposedMessage);
      return;
    }

    $root = $this->context->getRoot()->getEntity();
    assert($root instanceof ContentTemplate);
    /** @var \Drupal\experience_builder\Plugin\DataType\ComponentTreeStructure $tree */
    $tree = $root->getComponentTree()->get('tree');

    $slot_exists = FALSE;
    try {
      $source = $tree->getComponentSource($value['component_uuid']);
      if ($source instanceof ComponentSourceWithSlotsInterface) {
        $slot_exists = array_key_exists($value['slot_name'], $source->getSlotDefinitions());
      }
    }
    catch (\OutOfRangeException) {
      // The component that contains the exposed slot isn't in the tree at all,
      // so there's nothing else for us to do.
      $this->context->addViolation($constraint->unknownComponentMessage, [
        '%id' => $value['component_uuid'],
      ]);
      return;
    }

    // The component has to actually define the slot being exposed.
    if ($slot_exists === FALSE) {
      $this->context->addViolation($constraint->undefinedSlotMessage, [
        '%id' => $value['component_uuid'],
        '%slot' => $value['slot_name'],
      ]);
    }

    // The exposed slot has to be empty.
    foreach ($tree->getSlotChildrenDepthFirst() as $parent_uuid => $child) {
      if ($parent_uuid === $value['component_uuid']) {
        $this->context->addViolation($constraint->slotNotEmptyMessage, [
          '%slot' => $child['slot'],
        ]);
        break;
      }
    }

    if ($root->getMode() !== $constraint->viewMode) {
      $this->context->addViolation($constraint->viewModeMismatchMessage, [
        '%mode' => $constraint->viewMode,
      ]);
    }
  }

}
