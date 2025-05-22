<?php

declare(strict_types=1);

namespace Drupal\experience_builder\Plugin\Validation\Constraint;

use Drupal\experience_builder\ComponentSource\ComponentSourceWithSlotsInterface;
use Drupal\experience_builder\Entity\ContentTemplate;
use Drupal\experience_builder\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\experience_builder\Plugin\Field\FieldType\ComponentTreeItemList;
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

    $root = $this->context->getRoot()->getEntity();
    assert($root instanceof ContentTemplate);

    $component_tree_item_list = $root->getComponentTree();
    $item = $component_tree_item_list->getComponentTreeItemByUuid($value['component_uuid']);
    if ($item === NULL) {
      // The component that contains the exposed slot isn't in the tree at all,
      // so there's nothing else for us to do.
      $this->context->addViolation($constraint->unknownComponentMessage, [
        '%id' => $value['component_uuid'],
      ]);
      return;
    }
    \assert($item instanceof ComponentTreeItem);
    $slot_exists = FALSE;
    $source = $item->getComponent()?->getComponentSource();
    if ($source instanceof ComponentSourceWithSlotsInterface) {
      $slot_exists = \array_key_exists($value['slot_name'], $source->getSlotDefinitions());
    }

    // The component has to actually define the slot being exposed.
    if ($slot_exists === FALSE) {
      $this->context->addViolation($constraint->undefinedSlotMessage, [
        '%id' => $value['component_uuid'],
        '%slot' => $value['slot_name'],
      ]);
      return;
    }

    // The exposed slot has to be empty.
    if (\count(\iterator_to_array($component_tree_item_list->componentTreeItemsIterator(ComponentTreeItemList::isChildOfComponentTreeItemSlot($value['component_uuid'], $value['slot_name'])))) !== 0) {
      $this->context->addViolation($constraint->slotNotEmptyMessage, [
        '%slot' => $value['slot_name'],
      ]);
      return;
    }

    if ($root->getMode() !== $constraint->viewMode) {
      $this->context->addViolation($constraint->viewModeMismatchMessage, [
        '%mode' => $constraint->viewMode,
      ]);
    }
  }

}
