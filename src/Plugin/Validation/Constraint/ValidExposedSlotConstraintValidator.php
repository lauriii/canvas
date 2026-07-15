<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Validation\Constraint;

use Drupal\canvas\ComponentSource\ComponentSourceWithSlotsInterface;
use Drupal\canvas\Entity\ContentTemplate;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItemList;
use Drupal\Core\Config\ConfigManagerInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\Plugin\DataType\EntityAdapter;
use Drupal\field\Entity\FieldConfig;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

/**
 * Validates the `ValidExposedSlot` constraint.
 */
final class ValidExposedSlotConstraintValidator extends ConstraintValidator implements ContainerInjectionInterface {

  public function __construct(
    private readonly ConfigManagerInterface $configManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get(ConfigManagerInterface::class),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $value, Constraint $constraint): void {
    \assert($constraint instanceof ValidExposedSlotConstraint);

    \assert(\is_array($value), new UnexpectedTypeException($value, 'array'));
    $root = $this->context->getRoot();
    if ($root instanceof EntityAdapter) {
      $template = $root->getEntity();
    }
    else {
      $template = $this->configManager->loadConfigEntityByName($root->getName());
    }
    \assert($template instanceof ContentTemplate);

    $component_tree_item_list = $template->getComponentTree();
    $item = $component_tree_item_list->getComponentTreeItemByUuid($value['component_uuid']);
    if ($item === NULL) {
      // The component that contains the exposed slot isn't in the tree at all,
      // so there's nothing else for us to do.
      $this->context->addViolation($constraint->unknownComponentMessage, [
        '%id' => $value['component_uuid'],
      ]);
      return;
    }
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

    // The exposed slot has to be empty only when the consumer requires it (for
    // example page variants); content templates allow template content in an
    // exposed slot to become that slot's per-entity-overridable default.
    if ($constraint->requireEmpty) {
      $items_in_exposed_slot = $component_tree_item_list->componentTreeItemsIterator(
        ComponentTreeItemList::isChildOfComponentTreeItemSlot($value['component_uuid'], $value['slot_name']),
      );
      if (\iterator_count($items_in_exposed_slot) > 0) {
        $this->context->addViolation($constraint->slotNotEmptyMessage, [
          '%slot' => $value['slot_name'],
        ]);
        return;
      }
    }

    if ($template->getMode() !== $constraint->viewMode) {
      $this->context->addViolation($constraint->viewModeMismatchMessage, [
        '%mode' => $constraint->viewMode,
      ]);
    }

    // The exposed slot's key must name a `component_tree` field on the bundle:
    // that field is the slot's per-entity storage and stable identity. The key
    // is the final segment of this item's property path (e.g. the `test_slot`
    // in `exposed_slots.test_slot`). Derive it from there rather than matching
    // $value against the base template's exposed slots: a translation override
    // changes the translatable `label`, so the merged value validated here no
    // longer equals any base value and a value-based lookup would fail.
    if ($constraint->requireFieldBacked) {
      $segments = \preg_split('/[.\[\]]+/', $this->context->getPropertyPath(), -1, \PREG_SPLIT_NO_EMPTY);
      $field_name = $segments === FALSE || $segments === [] ? '' : (string) \end($segments);
      $field_config = $field_name !== ''
        ? FieldConfig::loadByName($template->getTargetEntityTypeId(), $template->getTargetBundle(), $field_name)
        : NULL;
      if ($field_config === NULL || $field_config->getType() !== ComponentTreeItem::PLUGIN_ID) {
        $this->context->addViolation($constraint->missingFieldMessage, [
          '%field' => $field_name,
        ]);
      }
    }
  }

}
