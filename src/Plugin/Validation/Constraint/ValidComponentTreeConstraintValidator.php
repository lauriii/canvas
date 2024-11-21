<?php

declare(strict_types=1);

namespace Drupal\experience_builder\Plugin\Validation\Constraint;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Render\Component\Exception\ComponentNotFoundException;
use Drupal\Core\Render\Component\Exception\InvalidComponentException;
use Drupal\Core\Theme\Component\ComponentValidator;
use Drupal\Core\TypedData\TypedDataManagerInterface;
use Drupal\experience_builder\MissingComponentPropsException;
use Drupal\experience_builder\MissingHostEntityException;
use Drupal\experience_builder\Entity\Component;
use Drupal\experience_builder\Plugin\DataType\ComponentTreeStructure;
use Drupal\experience_builder\Plugin\ExperienceBuilder\ComponentSource\SingleDirectoryComponent;
use Drupal\experience_builder\Plugin\Field\FieldType\ComponentTreeItem;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

final class ValidComponentTreeConstraintValidator extends ConstraintValidator implements ContainerInjectionInterface {

  use ConfigComponentTreeTrait;

  public function __construct(
    private readonly ComponentValidator $componentValidator,
    private readonly TypedDataManagerInterface $typedDataManager,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new static(
      $container->get(ComponentValidator::class),
      $container->get(TypedDataManagerInterface::class),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $value, Constraint $constraint): void {
    if ($value === NULL) {
      return;
    }

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
    // @todo This will need to evolve when supporting non-SDC component types in https://www.drupal.org/project/experience_builder/issues/3454519
    foreach ($tree->getComponentInstanceUuids() as $component_instance_uuid) {
      $component_id = $tree->getComponentId($component_instance_uuid);
      $component_entity = Component::load($component_id);
      if ($component_entity instanceof Component && $component_entity->getComponentSource() instanceof SingleDirectoryComponent) {
        try {
          $component = $component_entity->getComponentSource()->getComponentPlugin();
          $props_values = $value->resolveComponentProps($component_instance_uuid);
          $this->componentValidator->validateProps($props_values, $component);
        }
        catch (ComponentNotFoundException) {
          // The violation for a missing component will be added in the validation
          // of the tree structure.
          // @see \Drupal\experience_builder\Plugin\Validation\Constraint\ComponentTreeStructureConstraintValidator
        }
        catch (MissingComponentPropsException $e) {
          $this->context->buildViolation('The required properties are missing.')
            ->atPath(sprintf('props.%s', $e->componentInstanceUuid))
            ->addViolation();
        }
        catch (InvalidComponentException $e) {
          // Deconstruct the multi-part exception message constructed by SDC.
          // @see \Drupal\Core\Theme\Component\ComponentValidator::validateProps()
          $errors = explode("\n", $e->getMessage());
          foreach ($errors as $error) {
            // An example error:
            // @code
            // [style] Does not have a value in the enumeration ["primary","secondary"]
            // @endcode
            // In that string, `[style]` is the bracket-enclosed SDC prop name
            // for which an error occurred. This string must be parsed.
            $sdc_prop_name_closing_bracket_pos = strpos($error, ']', 1);
            assert($sdc_prop_name_closing_bracket_pos !== FALSE);
            // This extracts `style` and the subsequent error message from the
            // example string above.
            $prop_name = substr($error, 1, $sdc_prop_name_closing_bracket_pos - 1);
            $prop_error_message = substr($error, $sdc_prop_name_closing_bracket_pos + 2);
            $this->context->buildViolation($prop_error_message)
              ->atPath("props.$component_instance_uuid.$prop_name")
              ->addViolation();
          }
        }
        catch (MissingHostEntityException $e) {
          // DynamicPropSources cannot be validated in isolation, only in the
          // context of a host content entity.
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

}
