<?php

declare(strict_types=1);

namespace Drupal\experience_builder\Plugin\Validation\Constraint;

use Drupal\Core\Config\Schema\SchemaCheckTrait;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Render\Component\Exception\ComponentNotFoundException;
use Drupal\Core\Render\Component\Exception\InvalidComponentException;
use Drupal\Core\Theme\Component\ComponentValidator;
use Drupal\Core\Theme\ComponentPluginManager;
use Drupal\Core\TypedData\TypedDataManagerInterface;
use Drupal\experience_builder\Plugin\DataType\ComponentTreeStructure;
use Drupal\experience_builder\Plugin\Field\FieldType\ComponentTreeItem;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

final class ValidComponentTreeConstraintValidator extends ConstraintValidator implements ContainerInjectionInterface {

  use SchemaCheckTrait;

  public function __construct(
    private readonly ComponentPluginManager $componentPluginManager,
    private readonly ComponentValidator $componentValidator,
    private readonly TypedDataManagerInterface $typedDataManager,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new static(
      $container->get(ComponentPluginManager::class),
      $container->get(ComponentValidator::class),
      $container->get(TypedDataManagerInterface::class),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $value, Constraint $constraint): void {
    if (!$value instanceof ComponentTreeItem && !is_array($value)) {
      throw new \UnexpectedValueException(sprintf('The value must be a ComponentTreeItem object or an array, found %s.', gettype($value)));
    }

    // Validate the raw structure:
    // - if this is a `experience_builder.component_tree`, that is the received value
    // - if this is a `field_item:component_tree`, that is the array
    //   representation of the field item object
    if (!$this->validateRawStructure(is_array($value) ? $value : $value->toArray())) {
      // ::validateRawStructure()'s validation errors should be fixed first.
      return;
    }

    // Validate in-depth. This is simpler if the ComponentTreeItem-provided
    // infrastructure is available, so conjure one from $value if not already.
    if (!$value instanceof ComponentTreeItem) {
      assert(array_key_exists('tree', $value));
      assert(array_key_exists('props', $value));
      $component_tree_type = 'config';
      $value = $this->conjureFieldItemObject($value);
    }
    else {
      $component_tree_type = 'content';
    }
    $tree = $value->get('tree');
    if (!$tree instanceof ComponentTreeStructure) {
      throw new \UnexpectedValueException(sprintf('The tree field must contain a ComponentTreeStructure object, found %s.', gettype($tree)));
    }

    // Validate that each prop source resolves into a value that is considered
    // valid by the destination SDC prop.
    foreach ($tree->getComponentInstanceUuids() as $component_instance_uuid) {
      $component_id = $tree->getComponentId($component_instance_uuid);
      try {
        $props_values = $value->resolveComponentProps($component_instance_uuid);
        $component = $this->componentPluginManager->find($component_id);
        $this->componentValidator->validateProps($props_values, $component);
      }
      catch (ComponentNotFoundException) {
        $this->context->addViolation('The component instance with UUID %uuid uses component %id but does not exist! Put a breakpoint here and figure out why.', ['%uuid' => $component_instance_uuid, '%id' => $component_id]);
      }
      catch (InvalidComponentException) {
        $this->context->addViolation('The component instance with UUID %uuid uses component %id and receives some invalid props! Put a breakpoint here and figure out why.', ['%uuid' => $component_instance_uuid, '%id' => $component_id]);
      }
      catch (\OutOfRangeException $e) {
        // DynamicPropSources cannot be validated in isolation, only in the
        // context of a host content entity.
        // @todo Create specific exceptions for this in
        //   https://drupal.org/i/3462160.
        if ($component_tree_type === 'config') {
          // Silence this exception until this config is used in a content
          // entity.
        }
        // Some component props may not be resolvable yet because required
        // fields do not yet have values specified.
        // @see https://www.drupal.org/project/drupal/issues/2820364
        // @see \Drupal\experience_builder\Plugin\Field\FieldType\ComponentTreeItem::postSave()
        elseif ($value->getEntity()->isNew()) {
          // Silence this exception until the required field is populated.
        }
        else {
          // The required field must be populated now (this branch can only be
          // hit when the entity already exists and hence all required fields
          // must have values already), so do not silence the exception.
          throw $e;
        }
      }
    }
  }

  /**
   * Validates that the two required key-value pairs are present.
   *
   * @param array{tree?: string, props?: string} $raw_component_tree_values
   *
   * @return bool
   *   TRUE when valid, FALSE when not. Indicates whether to validate further.
   */
  private function validateRawStructure(array $raw_component_tree_values): bool {
    $is_valid = TRUE;
    if (!array_key_exists('tree', $raw_component_tree_values)) {
      $this->context->addViolation('The array must contain a "tree" key.');
      $is_valid = FALSE;
    }
    if (!array_key_exists('props', $raw_component_tree_values)) {
      $this->context->addViolation('The array must contain a "props" key.');
      $is_valid = FALSE;
    }
    return $is_valid;
  }

  /**
   * @param array{tree: string, props: string} $value
   *
   * @return \Drupal\experience_builder\Plugin\Field\FieldType\ComponentTreeItem
   */
  private function conjureFieldItemObject(array $value): ComponentTreeItem {
    $field_item_definition = $this->typedDataManager->createDataDefinition('field_item:component_tree');
    $field_item = $this->typedDataManager->createInstance('field_item:component_tree', [
      'name' => NULL,
      'parent' => NULL,
      'data_definition' => $field_item_definition,
    ]);
    $field_item->setValue($value);
    assert($field_item instanceof ComponentTreeItem);
    return $field_item;
  }

}
