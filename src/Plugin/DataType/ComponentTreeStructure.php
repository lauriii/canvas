<?php

declare(strict_types=1);

namespace Drupal\experience_builder\Plugin\DataType;

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

  /**
   * The data value.
   *
   * @var string
   */
  protected string $value;

  /**
   * The parsed data value.
   *
   * @var array<int, array{'uuid': string, 'type': string}>
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
    // Default to the empty JSON array.
    $this->setValue('[]', $notify);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function setValue($value, $notify = TRUE) {
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
    return array_column($this->tree, 'uuid');
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

    $index = array_search($component_instance_uuid, array_column($this->tree, 'uuid'));

    return $this->tree[$index]['type'];
  }

}
