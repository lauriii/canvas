<?php

declare(strict_types=1);

namespace Drupal\experience_builder\Entity;

use Drupal\Component\Utility\Random;
use Drupal\Core\Config\Entity\ConfigEntityBase;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\experience_builder\ClientSideRepresentation;
use Drupal\experience_builder\Controller\ApiConfigControllers;
use Drupal\experience_builder\Plugin\DataType\ComponentTreeStructure;
use Drupal\experience_builder\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\experience_builder\Plugin\Field\FieldType\ComponentTreeItemInstantiatorTrait;

/**
 * @ConfigEntityType(
 *    id = "pattern",
 *    label = @Translation("Pattern"),
 *    label_singular = @Translation("pattern"),
 *    label_plural = @Translation("patterns"),
 *    label_collection = @Translation("Patterns"),
 *    admin_permission = "access administration pages",
 *    entity_keys = {
 *      "id" = "id",
 *      "label" = "label",
 *    },
 *    config_export = {
 *      "id",
 *      "label",
 *      "component_tree",
 *    },
 *  )
 */
final class Pattern extends ConfigEntityBase implements XbHttpApiEligibleConfigEntityInterface {

  use ComponentTreeItemInstantiatorTrait;

  /**
   * Pattern entity ID.
   */
  protected string $id;

  /**
   * The human-readable label of the Experience Builder Pattern.
   */
  protected ?string $label;

  /**
   * Component tree.
   *
   * @var ?array{'props': array, 'tree': array}
   */
  protected ?array $component_tree;

  /**
   * @return \Drupal\experience_builder\Plugin\Field\FieldType\ComponentTreeItem
   *   One (dangling) component tree.
   */
  public function getComponentTree(): ComponentTreeItem {
    $component_tree_item = $this->createDanglingComponentTree();
    $component_tree_item->setValue($this->component_tree);
    return $component_tree_item;
  }

  /**
   * {@inheritdoc}
   */
  public function calculateDependencies() {
    parent::calculateDependencies();

    $component_tree = $this->getComponentTree();
    $tree = $component_tree->get('tree');
    assert($tree instanceof ComponentTreeStructure);
    $this->addDependencies($tree->getDependencies());

    // TRICKY: in theory, dependencies must also be calculated for the `props`
    // field prop. But, currently it can only contain StaticPropSources, and the
    // dependencies for those are tracked in the Component config entity.
    // @see \Drupal\experience_builder\Entity\Component::calculateDependencies()
    // @todo Revisit this when allowing more complex values in `props`, that are not dictated by/captured in the Component config entity.
    // @todo Revisit this in https://www.drupal.org/project/experience_builder/issues/3484666, where the above MIGHT change.

    return $this;
  }

  public static function preCreate(EntityStorageInterface $storage, array &$values) {
    $values['id'] = self::generateId($values['label']);
    parent::preCreate($storage, $values);
  }

  /**
   * Generates a valid ID from the given label.
   */
  private static function generateId(string $label): string {
    $id = mb_strtolower($label);

    $id = preg_replace('@[^a-z0-9_.]+@', '', $id);
    assert(is_string($id));
    // Furthermore remove any characters that are not alphanumerical from the
    // beginning and end of the transliterated string.
    $id = preg_replace('@^([^a-z0-9]+)|([^a-z0-9]+)$@', '', $id);
    assert(is_string($id));
    assert(is_string($id));
    if (strlen($id) > 23) {
      $id = substr($id, 0, 23);
    }
    assert(is_string($id));

    $query = \Drupal::entityTypeManager()->getStorage('pattern')->getQuery()->accessCheck(FALSE);
    $ids = $query->execute();
    $id_exists = in_array($id, $ids, TRUE);
    if ($id_exists) {
      $id = $id . '_' . (new Random())->machineName(8);
    }

    return $id;
  }

  /**
   * {@inheritdoc}
   *
   * This corresponds to `PatternPreview` in openapi.yml.
   *
   * @see docs/adr/0005-Keep-the-front1-end-simple.md
   */
  public function normalizeForClientSide(): ClientSideRepresentation {
    $item = $this->getComponentTree();
    assert($item instanceof ComponentTreeItem);
    return (new ClientSideRepresentation(
      values: [
        'layoutModel' => ApiConfigControllers::convertComponentTreeItemToLayoutModel($item),
        'name' => $this->label(),
        'id' => $this->id(),
      ],
      preview: $item->toRenderable(),
    ))->addCacheableDependency($this);
  }

}
