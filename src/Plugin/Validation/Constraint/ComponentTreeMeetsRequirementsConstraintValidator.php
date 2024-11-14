<?php

declare(strict_types=1);

namespace Drupal\experience_builder\Plugin\Validation\Constraint;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\TypedData\TypedDataManagerInterface;
use Drupal\experience_builder\Entity\Component;
use Drupal\experience_builder\Plugin\Field\FieldType\ComponentTreeItem;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

final class ComponentTreeMeetsRequirementsConstraintValidator extends ConstraintValidator implements ContainerInjectionInterface {

  use ConfigComponentTreeTrait;

  public function __construct(
    private readonly TypedDataManagerInterface $typedDataManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new static(
      $container->get(TypedDataManagerInterface::class),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $value, Constraint $constraint): void {
    assert($constraint instanceof ComponentTreeMeetsRequirementsConstraint);
    if ($value === NULL) {
      return;
    }
    if ($constraint->nested) {
      if (!is_array($value)) {
        throw new \UnexpectedValueException('The value must be an array of component trees.');
      }
      // Multiple config-defined component trees.
      $component_trees = array_map(
        // @phpstan-ignore-next-line
        fn(array $child_component_tree): ComponentTreeItem => $this->conjureFieldItemObject($child_component_tree),
        array_filter($value)
      );
    }
    else {
      // Regardless of how many component trees the requirements span, always
      // generate an array of ComponentTreeItem objects, to simplify validation.
      $component_trees = match (TRUE) {
        // A single content-defined component tree.
        $value instanceof ComponentTreeItem => [$value],
        // A single config-defined component tree.
        // @phpstan-ignore-next-line
        is_array($value) => [$this->conjureFieldItemObject($value)],
        default => throw new \UnexpectedValueException(sprintf('The value must be a ComponentTreeItem object, an array representing a single component tree, found %s.', gettype($value)))
      };
    }
    assert(is_array($component_trees));

    // Perform the necessary detections to check against what the constraint
    // options specify.
    $detected_component_ids = array_reduce(
      array_map(
        // @phpstan-ignore-next-line
        fn(ComponentTreeItem $component_tree): array => $component_tree->get('tree')->getComponentIdList(),
        $component_trees
      ),
      fn(array $unique_values, array $current_values): array => array_unique([...$unique_values, ...$current_values]),
      []
    );
    sort($detected_component_ids);
    $detected_component_classes = Component::getClasses($detected_component_ids);
    $detected_component_interfaces = [];
    foreach ($detected_component_classes as $fqcn) {
      // @phpstan-ignore arrayUnpacking.nonIterable
      $detected_component_interfaces = [...$detected_component_interfaces, ...class_implements($fqcn)];
    }
    $detected_component_interfaces = array_unique($detected_component_interfaces);
    sort($detected_component_interfaces);
    $detected_prop_source_prefixes = array_reduce(
      array_map(
        // @phpstan-ignore-next-line
        fn(ComponentTreeItem $component_tree): array => $component_tree->get('props')->getPropSourceTypePrefixList(),
        $component_trees
      ),
      fn(array $unique_values, array $current_values): array => array_unique([...$unique_values, ...$current_values]),
      []
    );
    sort($detected_prop_source_prefixes);

    foreach (['tree:component_ids', 'tree:component_interfaces', 'props'] as $aspect_to_check) {
      $actual_unique_values = match($aspect_to_check) {
        'props' => $detected_prop_source_prefixes,
        'tree:component_ids' => $detected_component_ids,
        'tree:component_interfaces' => $detected_component_interfaces,
      };
      foreach (['absence', 'presence'] as $nested_option) {
        $requirement_values = match($aspect_to_check) {
          'props' => $constraint->props[$nested_option],
          // Distinguish between the two kinds of restrictions supported by this
          // validation constraint: Component (config entity) IDs and Component
          // (plugin) interfaces.
          // The latter must start with the string `Drupal/` because all Drupal-
          // related interfaces must be somewhere under that namespace. All
          // other strings then must logically be Component (config entity) IDs.
          'tree:component_ids' => $constraint->tree[$nested_option] === NULL ? NULL : array_filter($constraint->tree[$nested_option], fn ($v) => !str_starts_with($v, 'Drupal\\')),
          'tree:component_interfaces' => $constraint->tree[$nested_option] === NULL ? NULL : array_filter($constraint->tree[$nested_option], fn ($v) => str_starts_with($v, 'Drupal\\')),
        };
        if ($requirement_values === NULL) {
          // No requirements for this.
          continue;
        }

        $intersection = array_intersect($actual_unique_values, $requirement_values);
        // When absence is required, the intersection must be empty.
        if ($nested_option === 'absence' && !empty($intersection)) {
          foreach ($intersection as $forbidden_value) {
            $this->context
              ->buildViolation(match($aspect_to_check) {
                'props' => $constraint->propSourceTypeAbsenceMessage,
                'tree:component_ids' => $constraint->componentAbsenceMessage,
                'tree:component_interfaces' => $constraint->componentInterfaceAbsenceMessage,
              })
              ->setParameter(
                match($aspect_to_check) {
                  'props' => '@prop_source_type_prefix',
                  'tree:component_ids' => '@component_id',
                  'tree:component_interfaces' => '@component_interface',
                },
                $forbidden_value
              )
              ->addViolation();
          }
        }
        // When presence is required, the intersection must equal the values
        // specified in the requirement.
        elseif ($nested_option === 'presence' && $intersection != $requirement_values) {
          $missing_values = array_diff($requirement_values, $intersection);
          foreach ($missing_values as $missing_value) {
            $this->context
              ->buildViolation(match($aspect_to_check) {
                'props' => $constraint->propSourceTypePresenceMessage,
                'tree:component_ids' => $constraint->componentPresenceMessage,
                'tree:component_interfaces' => $constraint->componentInterfacePresenceMessage,
              })
              ->setParameter(
                match($aspect_to_check) {
                  'props' => '@prop_source_type_prefix',
                  'tree:component_ids' => '@component_id',
                  'tree:component_interfaces' => '@component_interface',
                },
                $missing_value,
              )
              ->addViolation();
          }
        }
      }
    }
  }

}
