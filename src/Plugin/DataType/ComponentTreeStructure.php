<?php

declare(strict_types=1);

namespace Drupal\experience_builder\Plugin\DataType;

use Drupal\Component\Serialization\Json;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\Attribute\DataType;
use Drupal\Core\TypedData\TypedData;

/**
 * The component tree structure's data structure is optimized for efficiency.
 *
 * - The component tree is represented as an array of component subtrees.
 * - Each component subtree is keyed by its parent component instance's UUID.
 * - There is one special case: the root, which has a reserved UUID.
 * - Each component subtree contains only its children, not grandchildren — its
 *   depth is hence always 1.
 * - Each component subtree contains a list of populated slot names, with an
 *   ordered list of component "uuid,component" tuples in each populated slot.
 *   The sole exception is the root, which contains has no slot names: it is
 *   essentially a slot.
 * - Hence each component subtree contains only its children, not grandchildren;
 *   its depth is hence always 1.
 *
 * This avoids the need for deep tree traversal: the depth of the data structure
 * when represented as PHP arrays is at most 4 levels:
 * - the top level lists the root UUID plus all component instances that contain
 *   subtrees
 * - the root component subtree contains "uuid,component" tuples, bringing it to
 *   3 levels deep: level 2 contains the tuples, level 3 is each tuple
 *   represented as an array
 * - the other component subtrees contain populated slot names, followed by the
 *   aforementioned tuples, bringing it to 4 levels deep: level 2 contains the
 *   populated slot names, level 3 contains the tuples in each populated slot,
 *   and level 4 is each tuple represented as an array
 *
 * The costly consequence is that the complete component tree is not readily
 * available: it requires some assembly. However, since this requires rendering
 * anyway, this cost is negligible.
 *
 * @see \Drupal\experience_builder\Plugin\DataType\ComponentTreeHydrated
 *
 * The benefits:
 * - finding a component instance by UUID or by component does not require tree
 *   traversal; it can happen more efficiently
 * - less recursion throughout the codebase — this tree is the heart of
 *   Experience Builder, and how it works affects the entire codebase
 * - … for example in the validation logic
 * - updating/migrating existing component instances is hence simpler
 * - bugs in update/migration paths cannot easily corrupt the entire tree
 *
 * @see \Drupal\experience_builder\Plugin\Validation\Constraint\ComponentTreeStructureConstraintValidator
 * @see \Drupal\Tests\experience_builder\Kernel\DataType\ComponentTreeStructureTest
 *
 * @todo Implement ListInterface because it conceptually fits, but … what does it get us?
 */
#[DataType(
  id: "component_tree_structure",
  label: new TranslatableMarkup("Component tree structure"),
  description: new TranslatableMarkup("The structure of the component tree: without props values"),
  constraints: [
    "ComponentTreeStructure" => [],
  ]
)]
class ComponentTreeStructure extends TypedData {

  const ROOT_UUID = 'a548b48d-58a8-4077-aa04-da9405a6f418';

  /**
   * The data value.
   *
   * @var string
   */
  protected string $value;

  /**
   * The parsed data value.
   *
   * @todo The value 'component' key stored is a machine name of Component plugin though XB only allows users to select Component config entities.
   *    Because all config entities have a corresponding Component plugin, and it is not possible to have 2 config entities that relate to the same plugin, this works.
   *    It is a bit confusing but probably not worth fixing as this will all change in https://drupal.org/i/3454519.
   *
   * @var array<string,array<int, array{'uuid': string, 'component': string}>|array<string, array<int, array{'uuid': string, 'component': string}>>
   * >
   */
  protected array $tree = [];

  /**
   * {@inheritdoc}
   */
  public function getValue() {
    // @todo Uncomment next line and delete last line after https://www.drupal.org/project/drupal/issues/2232427
    // return $this->tree;
    // @see \Drupal\experience_builder\Plugin\Validation\Constraint\ValidComponentTreeConstraintValidator
    return $this->value ?? NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function applyDefaultValue($notify = TRUE) {
    // Default to a JSON object with only the root key present.
    $this->setValue('{"' . ComponentTreeStructure::ROOT_UUID . '": []}', $notify);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function setValue($value, $notify = TRUE): void {
    // @todo Delete next line; update this code to ONLY do the JSON-to-PHP-object parsing after https://www.drupal.org/project/drupal/issues/2232427 lands — that will allow specifying the "json" serialization strategy rather than only PHP's serialize().
    $this->value = $value;
    $this->tree = Json::decode($value);
    // Notify the parent of any changes.
    if ($notify && isset($this->parent)) {
      $this->parent->onChange($this->name);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function __toString(): string {
    return Json::encode($this->tree);
  }

  /**
   * @return string[]
   *   Component instance UUIDs.
   */
  public function getComponentInstanceUuids(): array {
    return array_column($this->getComponents(), 'uuid');
  }

  /**
   * @return array<array{uuid: string, component: string}>
   */
  private function getComponents(): array {
    $components = [];

    // For the remainder of the structure, assume two levels as per the requirement.
    foreach ($this->tree as $uuid => $sub_tree_value) {
      if ($uuid === self::ROOT_UUID) {
        $components = array_merge($components, $sub_tree_value);
        continue;
      }

      foreach ($sub_tree_value as $section_name => $items) {
        if (!is_array($items)) {
          // @see \Drupal\experience_builder\Plugin\Validation\Constraint\ComponentTreeStructureConstraintValidator
          throw new \UnexpectedValueException(sprintf('Expected an array of items expect in %s, but got %s.', $section_name, gettype($items)));
        }
        // Efficiently extract UUID values from each inner array.
        $components = array_merge($components, $items);
      }
    }

    // @see \Drupal\experience_builder\Plugin\Validation\Constraint\ValidComponentTreeConstraintValidator
    // @phpstan-ignore-next-line
    return $components;
  }

  /**
   * @param string $component_instance_uuid
   *   The UUID of a placed component instance.
   *
   * @return string
   */
  public function getComponentId(string $component_instance_uuid): string {
    if (!in_array($component_instance_uuid, $this->getComponentInstanceUuids(), TRUE)) {
      throw new \OutOfRangeException(sprintf('No component stored for %s. Caused by either incorrect logic or `props` being out of sync with `tree`.', $component_instance_uuid));
    }
    $components = $this->getComponents();

    $index = array_search($component_instance_uuid, array_column($components, 'uuid'));

    return $components[$index]['component'];
  }

  /**
   * @return array<string>
   */
  public function getComponentIdList(): array {
    return array_values(array_unique(array_column($this->getComponents(), 'component')));
  }

}
