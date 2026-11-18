<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Validation\Constraint;

use Drupal\canvas\ComponentSource\ComponentSourceWithSlotsInterface;
use Drupal\canvas\Entity\ContentTemplate;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\Core\Config\ConfigManagerInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Plugin\DataType\EntityAdapter;
use Drupal\Core\Field\FieldConfigInterface;
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
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get(ConfigManagerInterface::class),
      $container->get(EntityTypeManagerInterface::class),
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

    // The validated entry's key, which is its backing field's name: the
    // property path's final segment. Not looked up by value: two entries with
    // identical values are duplicates, and a translation's label override
    // changes the value.
    $segments = \preg_split('/[.\[\]]+/', $this->context->getPropertyPath(), -1, \PREG_SPLIT_NO_EMPTY);
    $field_name = ($segments === FALSE || $segments === []) ? '' : (string) \end($segments);

    // An exposed slot's host component must not sit inside another exposed
    // slot's subtree: that subtree is a replaceable default, so a per-entity
    // override of the outer slot (which replaces the default with fresh
    // content) would orphan the inner slot's target and leave its per-entity
    // content unrenderable. Collect every (parent, slot) pair on the host's
    // ancestry, then reject if any other exposed slot targets one of them.
    $ancestor_slots = [];
    for ($ancestor = $item; $ancestor !== NULL && $ancestor->getParentUuid() !== NULL; $ancestor = $component_tree_item_list->getComponentTreeItemByUuid($ancestor->getParentUuid())) {
      $ancestor_slots[$ancestor->getParentUuid() . ':' . $ancestor->getSlot()] = TRUE;
    }
    foreach ($template->getExposedSlots() as $alias => $definition) {
      if ($alias === $field_name) {
        continue;
      }
      // One physical slot cannot be exposed twice: the Layout API would offer
      // two editable regions for one target, and rendering would merge one
      // backing field over the other.
      if ($definition['component_uuid'] === $value['component_uuid'] && $definition['slot_name'] === $value['slot_name']) {
        $this->context->addViolation($constraint->duplicateTargetMessage, [
          '%id' => $value['component_uuid'],
          '%slot' => $value['slot_name'],
          '%alias' => $alias,
        ]);
        return;
      }
      if (isset($ancestor_slots[$definition['component_uuid'] . ':' . $definition['slot_name']])) {
        $this->context->addViolation($constraint->nestedSlotMessage, [
          '%id' => $value['component_uuid'],
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

    // The key must name a `component_tree` field on the bundle: the slot's
    // per-entity storage.
    $field_config = $field_name !== ''
      ? $this->entityTypeManager->getStorage('field_config')->load(\sprintf('%s.%s.%s', $template->getTargetEntityTypeId(), $template->getTargetBundle(), $field_name))
      : NULL;
    if (!$field_config instanceof FieldConfigInterface || $field_config->getType() !== ComponentTreeItem::PLUGIN_ID) {
      $this->context->addViolation($constraint->missingFieldMessage, [
        '%field' => $field_name,
      ]);
    }
  }

}
