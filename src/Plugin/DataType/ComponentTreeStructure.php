<?php

declare(strict_types=1);

namespace Drupal\experience_builder\Plugin\DataType;

use Drupal\Component\Assertion\Inspector;
use Drupal\Component\Serialization\Json;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\Attribute\DataType;
use Drupal\Core\TypedData\TypedData;

/**
 * @todo Implement ListInterface because it conceptually fits, but … what does it get us?
 */
#[DataType(
  id: "component_tree_structure",
  label: new TranslatableMarkup("Component tree structure"),
  description: new TranslatableMarkup("The structure of the component tree: without props values"),
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
    return $this->value;
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
    // @todo These are temporary checks until we have a constraint. Just to make
    //   sure tests fail if we don't have valid test values. Add actual
    //   constraint in https://drupal.org/i/3460856.
    if (!isset($this->tree[ComponentTreeStructure::ROOT_UUID]) || !array_is_list($this->tree[ComponentTreeStructure::ROOT_UUID])) {
      throw new \UnexpectedValueException('Temp exception replace with constraint. Root UUID is missing or incorrect:' . $value);
    }
    foreach (array_keys($this->tree) as $top_level_uuid) {
      assert(is_string($top_level_uuid));
      if ($top_level_uuid !== ComponentTreeStructure::ROOT_UUID && substr_count($value, $top_level_uuid) !== 2) {
        throw new \UnexpectedValueException("Temp exception replace with constraint. Top level UUID, $top_level_uuid does not appear in tree.");
      }
    }

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
          throw new \UnexpectedValueException(sprintf('Expected an array of items expect in %s, but got %s.', $section_name, gettype($items)));
        }
        // Efficiently extract UUID values from each inner array.
        $components = array_merge($components, $items);
      }
    }

    assert(Inspector::assertAllArrays($components));
    assert(Inspector::assertAll(fn (array $a) => array_keys($a) == ['uuid', 'component'], $components));

    // TRICKY: PHPStan gets confused by the array shape of $this->tree, and does
    // not understand the above assertions. Those assertions guarantee that the
    // documented return array shape is actually met.
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
