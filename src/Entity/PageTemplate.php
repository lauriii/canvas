<?php

declare(strict_types=1);

namespace Drupal\experience_builder\Entity;

use Drupal\Core\Config\Entity\ConfigEntityBase;
use Drupal\experience_builder\Plugin\DataType\ComponentTreeStructure;
use Drupal\experience_builder\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\experience_builder\Plugin\Field\FieldType\ComponentTreeItemInstantiatorTrait;

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
final class PageTemplate extends ConfigEntityBase implements XbHttpApiEligibleConfigEntityInterface {

  use ComponentTreeItemInstantiatorTrait;

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

    // Instantiate a single (dangling) XB component tree field item object to
    // subsequently clone and assign a different value for each region that has
    // a component tree defined.
    $field_item = $this->createDanglingComponentTree();
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
      assert($component_tree instanceof ComponentTreeItem);
      $tree = $component_tree->get('tree');
      assert($tree instanceof ComponentTreeStructure);
      $this->addDependencies($tree->getDependencies());

      // TRICKY: in theory, dependencies must also be calculated for the `props`
      // field prop. But, currently it can only contain StaticPropSources, and
      // the dependencies for those are tracked in the Component config entity.
      // @see \Drupal\experience_builder\Entity\Component::calculateDependencies()
      // @todo Revisit this when allowing more complex values in `props`, that are not dictated by/captured in the Component config entity.
      // @todo Revisit this in https://www.drupal.org/project/experience_builder/issues/3484666, where the above MIGHT change.
    }

    return $this;
  }

}
