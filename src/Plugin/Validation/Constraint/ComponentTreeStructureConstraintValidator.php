<?php

declare(strict_types=1);

namespace Drupal\experience_builder\Plugin\Validation\Constraint;

use Drupal\Core\Config\Entity\ConfigEntityStorageInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Validation\BasicRecursiveValidatorFactory;
use Drupal\experience_builder\ComponentSource\ComponentSourceWithSlotsInterface;
use Drupal\experience_builder\Entity\Component;
use Drupal\experience_builder\Plugin\DataType\ComponentTreeStructure;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Constraints\All;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Constraints\Collection;
use Symfony\Component\Validator\Constraints\Count;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Required;
use Symfony\Component\Validator\Constraints\Sequentially;
use Symfony\Component\Validator\Constraints\Type;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

final class ComponentTreeStructureConstraintValidator extends ConstraintValidator implements ContainerInjectionInterface {

  public function __construct(
    private readonly ConfigEntityStorageInterface $componentStorage,
    private readonly BasicRecursiveValidatorFactory $validatorFactory,
  ) {
    // @see \Drupal\experience_builder\Entity\Component
    assert($this->componentStorage->getEntityTypeId() === 'component');
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    $component_storage = $container->get(EntityTypeManagerInterface::class)->getStorage('component');
    assert($component_storage instanceof ConfigEntityStorageInterface);
    return new static(
      $component_storage,
      $container->get(BasicRecursiveValidatorFactory::class),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $value, Constraint $constraint): void {
    if (!is_string($value)) {
      throw new \UnexpectedValueException(sprintf('The value must be a string, found %s.', gettype($value)));
    }
    $tree = json_decode($value, TRUE);
    if ($tree === NULL) {
      throw new \UnexpectedValueException(sprintf('The value must be a valid JSON string, found %s.', $value));
    }
    $this->validateTree($tree);
    foreach (array_keys($tree) as $uuid) {
      assert(is_string($uuid));
      if ($uuid === ComponentTreeStructure::ROOT_UUID) {
        continue;
      }
      if (!self::isUuidInTree($tree, $uuid)) {
        $this->context->buildViolation("Dangling component subtree. This component subtree claims to be for a component instance with UUID %uuid, but no such component instance can be found.")
          ->setParameter('%uuid', $uuid)
          ->atPath("[$uuid]")
          ->addViolation();
      }
    }
  }

  private static function isUuidInTree(array $tree, string $uuid): bool {
    foreach ($tree as $top_level_uuid => $component_subtree) {
      if ($top_level_uuid === $uuid) {
        // Do not search for the UUID in its own component subtree.
        continue;
      }
      if ($top_level_uuid === ComponentTreeStructure::ROOT_UUID) {
        // The root subtree contains "uuid-component" tuples directly.
        if (in_array($uuid, array_column($component_subtree, 'uuid'), TRUE)) {
          return TRUE;
        }
      }
      else {
        // Non-root subtrees contain slots and "uuid,component" tuples in each slot.
        foreach ($component_subtree as $slots) {
          if (in_array($uuid, array_column($slots, 'uuid'), TRUE)) {
            return TRUE;
          }
        }
      }
    }
    return FALSE;
  }

  private function validateTree(array $tree): void {
    // TRICKY: The existing validator and execution context cannot be reused
    // because Drupal expects everything to be TypedData, whereas here it is a
    // plain array-based data structure.
    // @todo Re-assess this in https://www.drupal.org/project/experience_builder/issues/3462235: if that introduces TypedData objects, then this could be simplified.
    $non_typed_data_validator = $this->validatorFactory->createValidator();

    // Constraint to validate each component instance, which is represented in
    // the tree by a "uuid,component" tuple.
    $component_instance_constraint = new Sequentially(
      [
        new Collection([
          'uuid' => new Required([
            new Type('string'),
            // @todo Validate that the string is a valid UUID. *And* that it is unique in the tree.
            new NotBlank(),
          ]),
          'component' => new Required([
            new Type('string'),
            new NotBlank(),
          ]),
        ]),
        new Callback(
          callback: self::validateComponentInstance(...),
          payload: [
            'component_storage' => $this->componentStorage,
          ]
        ),
      ]
    );
    // Since the root UUID has a different expected structure than other UUIDs
    // at the top of the data structure we validate it first to avoid complicated
    // constraints.
    $root_constraints = new Collection(
      [
        ComponentTreeStructure::ROOT_UUID => new Required([
          new Type('array'),
          new All([$component_instance_constraint]),
        ],
        ),
      ],
      missingFieldsMessage: 'The root UUID is missing.'
    );
    $root_constraints->allowExtraFields = TRUE;
    $violations = $non_typed_data_validator->validate($tree, $root_constraints);

    // Finally, validate all other component subtrees.
    unset($tree[ComponentTreeStructure::ROOT_UUID]);
    $other_subtrees_constraints = new All([
      new Count(['min' => 1], minMessage: 'Empty component subtree. A component subtree must contain >=1 populated slot (with >=1 component instance). Empty component subtrees must be omitted.'),
      new All([
        new Sequentially([
          new Type('array'),
          new Count(['min' => 1], minMessage: 'Empty slot. Slots without component instances must be omitted.'),
          new All([$component_instance_constraint]),
        ]),
      ]),
    ]);
    $violations->addAll($non_typed_data_validator->validate($tree, $other_subtrees_constraints));

    foreach ($violations as $violation) {
      $this->context->buildViolation((string) $violation->getMessage())
        ->atPath($violation->getPropertyPath())
        ->addViolation();
    }
  }

  private static function validateComponentInstance(array $component_instance, ExecutionContextInterface $context, array $payload): void {
    /** @var \Drupal\Core\Config\Entity\ConfigEntityStorageInterface $component_storage */
    $component_storage = $payload['component_storage'];
    assert($component_storage->getEntityTypeId() === 'component');
    $tree = $context->getRoot();

    if (!isset($component_instance['component'])) {
      // The \Symfony\Component\Validator\Constraints\Collection constraint
      // will add the violations for the unset key.
      return;
    }
    $component_config_entity = $component_storage->load($component_instance['component']);
    if ($component_config_entity === NULL) {
      $context->addViolation('The component %component does not exist.', ['%component' => $component_instance['component']]);
      return;
    }
    if (!isset($component_instance['uuid'])) {
      // The \Symfony\Component\Validator\Constraints\Collection constraint
      // will add the violations for the unset key.
      return;
    }

    // Override property path, for more meaningful validation errors.
    $original_property_path = $context->getPropertyPath();
    $context->setNode(
      $context->getValue(),
      $context->getObject(),
      $context->getMetadata(),
      '',
    );

    $component_entity = Component::load($component_instance['component']);
    assert($component_entity instanceof Component);
    $component_source = $component_entity->getComponentSource();
    if ($component_source instanceof ComponentSourceWithSlotsInterface) {
      $slots = $component_source->getSlotDefinitions();
      if (empty($slots)) {
        if (isset($tree[$component_instance['uuid']])) {
          $context->buildViolation('Invalid component subtree. A component subtree must only exist for components with >=1 slot, but the component %component has no slots, yet a subtree exists for the instance with UUID %uuid.', [
            '%component' => $component_instance['component'],
            '%uuid' => $component_instance['component'],
          ])
            ->atPath('[' . $component_instance['uuid'] . ']')
            ->addViolation();
        }
      }
      elseif (isset($tree[$component_instance['uuid']])) {
        $tree_slot_info = $tree[$component_instance['uuid']];
        $actual_slot_names = array_keys($slots);
        $unknown_slot_names = array_diff(array_keys($tree_slot_info), $actual_slot_names);
        foreach ($unknown_slot_names as $unknown_slot_name) {
          $context->buildViolation('Invalid component subtree. This component subtree contains an invalid slot name for component %component: %invalid_slot_name. Valid slot names are: %valid_slot_names.', [
            '%component' => $component_instance['component'],
            '%invalid_slot_name' => $unknown_slot_name,
            '%valid_slot_names' => implode(', ', $actual_slot_names),
          ])
            ->atPath('[' . $component_instance['uuid'] . '][' . $unknown_slot_name . ']')
            ->addViolation();
        }
      }
    }

    // Restore property path.
    $context->setNode(
      $context->getValue(),
      $context->getObject(),
      $context->getMetadata(),
      $original_property_path,
    );
  }

}
