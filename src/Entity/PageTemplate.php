<?php

declare(strict_types=1);

namespace Drupal\experience_builder\Entity;

use Drupal\Core\Config\Entity\ConfigEntityBase;
use Drupal\Core\TypedData\TypedDataTrait;
use Drupal\experience_builder\Plugin\DataType\ComponentTreeStructure;
use Drupal\experience_builder\Plugin\Field\FieldType\ComponentTreeItem;

/**
 * @ConfigEntityType(
 *    id = "page_template",
 *    label = @Translation("Page template"),
 *    label_singular = @Translation("page template"),
 *    label_plural = @Translation("page templates"),
 *    label_collection = @Translation("Page templates"),
 *    admin_permission = "access administration pages",
 *    entity_keys = {
 *      "id" = "theme",
 *    },
 *    config_export = {
 *      "theme",
 *      "component_trees",
 *    },
 *    lookup_keys = {
 *      "theme",
 *    }
 *  )
 */
final class PageTemplate extends ConfigEntityBase {

  use TypedDataTrait;

  /**
   * The theme that this defines the XB Page Template for.
   *
   * @var string
   */
  protected string $theme;

  /**
   * Component trees for each region.
   *
   * Keys are region names, values are either:
   * - if empty: `NULL`
   * - otherwise: a `type: experience_builder.component_tree`, which consists of
   *   a `tree` + `props` key-value pair.
   */
  protected ?array $component_trees;

  /**
   * {@inheritdoc}
   */
  public function id() {
    return $this->theme;
  }

  /**
   * @return \Generator<string, ComponentTreeItem>
   *   One (dangling) component tree per (populated) region.
   */
  public function getComponentTrees(): \Generator {
    assert(is_array($this->component_trees));

    // Instantiates a single (dangling) XB component tree field item object to
    // subsequently clone and assign a different value for each region that has
    // a component tree defined.
    $typed_data_manager = $this->getTypedDataManager();
    $field_item_definition = $typed_data_manager->createDataDefinition('field_item:component_tree');
    $field_item = $typed_data_manager->createInstance('field_item:component_tree', [
      'name' => NULL,
      'parent' => NULL,
      'data_definition' => $field_item_definition,
    ]);
    assert($field_item instanceof ComponentTreeItem);

    foreach ($this->component_trees as $region_name => $component_tree) {
      if ($component_tree === NULL) {
        continue;
      }
      $xb_component_tree = clone $field_item;
      $xb_component_tree->setValue($component_tree);
      yield $region_name => $xb_component_tree;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function calculateDependencies() {
    parent::calculateDependencies();
    $this->addDependency('theme', $this->theme);

    foreach ($this->getComponentTrees() as $component_tree) {
      // Use the XB field type infrastructure to determine the list of Component
      // config entities in use.
      assert($component_tree instanceof ComponentTreeItem);
      $tree = $component_tree->get('tree');
      assert($tree instanceof ComponentTreeStructure);
      $component_ids = $tree->getComponentIdList();

      // All unique components used in the tree are dependencies.
      foreach ($component_ids as $component_id) {
        // @see \Drupal\Core\Config\Entity\ConfigEntityTypeInterface::getConfigPrefix()
        $this->addDependency('config', 'experience_builder.component.' . $component_id);
      }
    }

    return $this;
  }

}
