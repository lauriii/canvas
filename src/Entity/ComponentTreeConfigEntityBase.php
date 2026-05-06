<?php

declare(strict_types=1);

namespace Drupal\canvas\Entity;

use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItemListInstantiatorTrait;
use Drupal\Component\Serialization\Json;
use Drupal\Core\Config\Entity\ConfigEntityBase;
use Drupal\Core\Entity\EntityStorageInterface;

/**
 * @internal
 *
 * @see \Drupal\canvas\EventSubscriber\ComponentTreeConfigEntityTransformer
 *
 * @phpstan-import-type ComponentTreeItemArray from \Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItemList
 * @phpstan-import-type ComponentTreeItemListArray from \Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItemList
 * @phpstan-type ComponentTreeItemKeyedSequence array<string, ComponentTreeItemArray>
 */
abstract class ComponentTreeConfigEntityBase extends ConfigEntityBase implements ComponentTreeEntityInterface {

  use ComponentTreeItemListInstantiatorTrait;
  use ConfigUpdaterAwareEntityTrait;

  /**
   * The component tree.
   *
   * @var ?array<string, ComponentTreeItemArray>
   */
  protected ?array $component_tree;

  /**
   * Transforms a component tree sequence to have no JSON strings as inputs.
   *
   * Canvas reuses ComponentTreeItem(List) for config-defined component trees at
   * run time: for rendering, validating, et cetera.
   *
   * This means that ComponentTreeItem's `inputs` field property may be used in
   * some ::set() or ::setComponentTree() calls to this class, which represents
   * the explicit inputs as a JSON string. Config-defined component
   * Config-defined component trees must be translatable though, and only a
   * subset of each component instance's explicit inputs may be translatable.
   *
   * @see \Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItem::propertyDefinitions()
   * @see \Drupal\canvas\Plugin\DataType\ComponentInputs::$value
   * @see \Drupal\canvas\Plugin\DataType\ComponentInputs::setValue()
   *
   * Hence config schema is generated on-the-fly for each config-defined
   * component instance: based on the referenced Component config entity (and
   * its version). This enables Drupal's config_translation module to
   * automatically handle translated explicit inputs (think: block labels, and
   * string/HTML SDC props).
   *
   * @see \Drupal\canvas\Config\Schema\ComponentInputsMapping
   *
   * This in turn means that when config entities with component trees are:
   * - validated, `inputs` will be validated by by Drupal's ValidKeysConstraint
   * - saved, `inputs` will be validated by Drupal's ConfigSchemaChecker
   * In both cases, `inputs` being a JSON string would trigger an error.
   *
   * Note that the inverse direction (converting `inputs`' key-value pairs to
   * blobs) is not necessary because `ComponentInputs::setValue()` transparently
   * handles that already for DX reasons.
   *
   * @internal
   */
  public static function componentTreeInstancesInputsMustBeArrays(array $component_tree_sequence): array {
    return \array_map(
      function (array $component_instance): array {
        \assert(\array_key_exists('inputs', $component_instance));
        if (\is_string($component_instance['inputs'])) {
          $component_instance['inputs'] = Json::decode($component_instance['inputs']);
        }
        return $component_instance;
      },
      $component_tree_sequence,
    );
  }

  /**
   * {@inheritdoc}
   */
  public static function preCreate(EntityStorageInterface $storage, array &$values) {
    if (\array_key_exists('component_tree', $values)) {
      $values['component_tree'] = self::componentTreeInstancesInputsMustBeArrays($values['component_tree']);
    }
    parent::preCreate($storage, $values);
  }

  /**
   * {@inheritdoc}
   */
  public function set($property_name, $value) {
    if ($property_name === 'component_tree') {
      $value = self::componentTreeInstancesInputsMustBeArrays($value);
    }
    return parent::set($property_name, $value);
  }

  /**
   * Transforms a component tree sequence to have translation-targetable keys.
   *
   * @param ComponentTreeItemKeyedSequence|ComponentTreeItemListArray $component_tree_sequence
   *   The raw component tree sequence, which may have string keys (typically if
   *   importing an exported config entity or loading a saved config entity), or
   *   integer keys (if constructing a config-defined component tree initially).
   *
   * @return ComponentTreeItemKeyedSequence
   *   The same sequence (the same values in the same order), but now
   *   deterministic keys that uniquely identify the component instance using
   *   its instance UUID (to allow symmetrical config translations to target a
   *   given component instance even when that instance is moved).
   */
  private static function asDeterministicallyAndTranslatableKeyedComponentTreeSequence(array $component_tree_sequence): array {
    return \array_combine(
      \array_column($component_tree_sequence, 'uuid'),
      \array_values($component_tree_sequence),
    );
  }

  public function setComponentTree(array $values): static {
    $this->set('component_tree', $values);
    return $this;
  }

  /**
   * {@inheritdoc}
   *
   * @todo Works around Typed Data static caching bugs in core's EntityBase, remove after https://www.drupal.org/project/drupal/issues/3571532 is fixed.
   */
  public function createDuplicate() {
    $duplicate = parent::createDuplicate();
    unset($duplicate->typedData);
    return $duplicate;
  }

  /**
   * {@inheritdoc}
   */
  public function preSave(EntityStorageInterface $storage) {
    parent::preSave($storage);
    self::getConfigUpdater()->updateConfigEntityWithComponentTreeInputs($this);
    self::getConfigUpdater()->updateConfigEntityWithComponentTreeInputsAsArrays($this);
    // TRICKY: do not use ::setComponentTree() here because it expects integer
    // keys ("deltas") for component instances. Config-defined component trees
    // do not have deltas but sequence keys. Manipulate the config entity
    // property directly.
    // @see \Drupal\canvas\CanvasConfigUpdater::needsConfigEntityWithComponentTreeSequenceKeysUpdate()
    $this->set('component_tree', self::asDeterministicallyAndTranslatableKeyedComponentTreeSequence(\array_values($this->get('component_tree'))));
  }

  /**
   * {@inheritdoc}
   *
   * @todo Works around Typed Data static caching bugs in core's EntityBase, remove after https://www.drupal.org/project/drupal/issues/3571532 is fixed.
   */
  public function postSave(EntityStorageInterface $storage, $update = TRUE): void {
    unset($this->typedData);
    parent::postSave($storage, $update);
  }

}
