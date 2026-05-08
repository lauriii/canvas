<?php

declare(strict_types=1);

namespace Drupal\canvas\EventSubscriber;

use Drupal\canvas\Entity\ContentTemplate;
use Drupal\canvas\Entity\PageRegion;
use Drupal\canvas\Entity\Pattern;
use Drupal\Component\Graph\Graph;
use Drupal\Component\Utility\SortArray;
use Drupal\Core\Config\ConfigEvents;
use Drupal\Core\Config\StorageInterface;
use Drupal\Core\Config\StorageTransformEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Transforms config-defined component trees.
 *
 * Make sure exported config-defined component trees' sequence keys are:
 * - intelligible (to improve DX when diffing versioned, exported config)
 * - tamper-resistant (reordering the component instances in the exported YAML
 *    can be reverted upon import)
 *
 * @see \Drupal\canvas\Entity\ComponentTreeConfigEntityBase
 *
 * @internal
 *
 * @phpstan-import-type ComponentTreeItemKeyedSequence from \Drupal\canvas\Entity\ComponentTreeConfigEntityBase
 */
final readonly class ComponentTreeConfigEntityTransformer implements EventSubscriberInterface {

  /**
   * @see \Drupal\Core\Config\Entity\ConfigEntityType::getConfigPrefix()
   */
  private const array CONFIG_PREFIXES_WITH_COMPONENT_TREES = [
    'canvas.' . ContentTemplate::ENTITY_TYPE_ID . '.',
    'canvas.' . PageRegion::ENTITY_TYPE_ID . '.',
    'canvas.' . Pattern::ENTITY_TYPE_ID . '.',
  ];

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      ConfigEvents::STORAGE_TRANSFORM_IMPORT => ['import'],
      ConfigEvents::STORAGE_TRANSFORM_EXPORT => ['export'],
    ];
  }

  /**
   * @return list<string>
   *   Config names.
   */
  private static function findConfigToTransform(StorageInterface $storage): array {
    $config_to_transform = [];
    foreach (self::CONFIG_PREFIXES_WITH_COMPONENT_TREES as $config_prefix) {
      $config_to_transform = [
        ...$config_to_transform,
        ...$storage->listAll($config_prefix),
      ];
    }
    \assert(\array_is_list($config_to_transform));
    return $config_to_transform;
  }

  /**
   * Reverts any tampering and then reverts what ::export() did.
   *
   * @param \Drupal\Core\Config\StorageTransformEvent $event
   *   The transformation event.
   */
  public static function import(StorageTransformEvent $event): void {
    $storage = $event->getStorage();
    // @see ::export()
    if ($storage->getCollectionName() !== StorageInterface::DEFAULT_COLLECTION) {
      return;
    }

    foreach (self::findConfigToTransform($storage) as $name) {
      $raw = $storage->read($name);
      // @see \Drupal\canvas\Entity\ComponentTreeConfigEntityBase::$component_tree
      \assert(\is_array($raw) && \array_key_exists('component_tree', $raw));
      // First, rely on the keys' position encoding to be able to recover the
      // correct order if they have been manually reordered (e.g. when a site
      // builder was resolving a merge conflict between exported config).
      // In other words: simulate `orderby: key` for this `type: sequence`, but
      // that cannot be specified in config schema, since to ensure
      // translatability, the sequence keys must be stable.
      // @see \Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItemListInstantiatorTrait::generateComponentTreeKeys()
      // @see \Drupal\Core\Config\StorableConfigBase::castValue()
      \ksort($raw['component_tree']);
      // Second, strip the position encoding, because the position has been
      // recovered. This leaves just UUIDs as keys, which is how translation
      // configuration can reliably target component instances, even if they are
      // moved around within the component tree.
      $raw['component_tree'] = \array_combine(
        \array_map(
          // @phpcs:disable Drupal.Files.LineLength.TooLong
          // For each key, extract the string after the last colon. Examples:
          // - `0:4f785025-9bd9-4752-9dd6-068b957b03ee` → `4f785025-9bd9-4752-9dd6-068b957b03ee`
          // - `0:the_body:0:the_body:0:b7e2cf39-d62f-4ee8-99b2-27a89f1ac196` → `b7e2cf39-d62f-4ee8-99b2-27a89f1ac196`
          // @phpcs:enable
          fn (string $sequence_key): string  => \array_reverse(explode(':', $sequence_key))[0],
          \array_keys($raw['component_tree']),
        ),
        \array_values($raw['component_tree']),
      );
      $storage->write($name, $raw);
    }
  }

  /**
   * Transforms the component tree keys to be intelligible and tamper-resistant.
   *
   * @param \Drupal\Core\Config\StorageTransformEvent $event
   *   The transformation event.
   */
  public static function export(StorageTransformEvent $event): void {
    $storage = $event->getStorage();
    // Export transformation applies only to the default collection — aka the
    // default translation (English). This is fine as long as only symmetrical
    // translations are supported for config-defined component trees: the order
    // of translation key-value pairs does not matter.
    // (If/when support would be added for asymmetrical translations of config-
    // defined component trees, that would change.)
    if ($storage->getCollectionName() !== StorageInterface::DEFAULT_COLLECTION) {
      // This should not actually be possible in Drupal core's "config export"
      // implementation, this is just a precaution.
      // @see \Drupal\Core\Config\ExportStorageManager::getStorage()
      // @see \Drupal\Core\Config\StorageCopyTrait::replaceStorageContents()
      // @see https://en.wikipedia.org/wiki/Robustness_principle
      return;
    }

    foreach (self::findConfigToTransform($storage) as $name) {
      $raw = $storage->read($name);
      // @see \Drupal\canvas\Entity\ComponentTreeConfigEntityBase::$component_tree
      \assert(\is_array($raw) && \array_key_exists('component_tree', $raw));
      // @phpcs:disable Drupal.Files.LineLength.TooLong
      // Examples of how the original keys (UUIDs of component instances) are
      // transformed to intelligible and tamper-resistant keys that encode
      // position, too:
      // - `4f785025-9bd9-4752-9dd6-068b957b03ee` → `0:4f785025-9bd9-4752-9dd6-068b957b03ee`
      // - `b7e2cf39-d62f-4ee8-99b2-27a89f1ac196` → `0:the_body:0:the_body:0:b7e2cf39-d62f-4ee8-99b2-27a89f1ac196`
      // @phpcs:enable
      $raw['component_tree'] = self::computeExportSequenceKeys($raw['component_tree']);
      $storage->write($name, $raw);
    }
  }

  /**
   * Computes component tree sequence keys used for exporting.
   *
   * @param ComponentTreeItemKeyedSequence $tree
   *   A config-defined component tree with component instance UUIDs as sequence
   *   keys.
   *
   * @return ComponentTreeItemKeyedSequence
   *   The same config-defined component tree, with the same values, but with
   *   keys that make it intelligible and tamper-resistant. See ::export() and
   *   ::import().
   *
   * @throws \InvalidArgumentException
   *   When the given component tree is internally inconsistent (referring to a
   *   non-existent parent), making it impossible to compute a result.
   *
   * @see \Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItemList::constructDepthFirstGraph()
   */
  private static function computeExportSequenceKeys(array $tree): array {
    $graph = [];
    // First construct a graph so we can order the component instances (i.e.
    // items in a ComponentTreeItemList) based on their depth.
    $top_level_delta = 0;
    foreach ($tree as $value) {
      \assert(\array_key_exists('uuid', $value));
      $uuid = $value['uuid'];
      if (!\array_key_exists($uuid, $graph)) {
        // Create the initial entry for this item in the graph.
        $graph[$uuid] = [
          // Children that reference this item.
          'edges' => [],
          // UUIDs of children keyed by slot name.
          'slot_children' => [],
        ];
      }
      $slot = $value['slot'] ?? NULL;
      if ($slot !== NULL) {
        // Store the slot this item is in.
        $graph[$uuid]['slot'] = $slot;
      }
      if (\array_key_exists('parent_uuid', $value) && $value['parent_uuid'] !== NULL) {
        $parent_uuid = $value['parent_uuid'];
        // Flag this item as a child of its parent.
        $graph[$parent_uuid]['edges'][$uuid] = TRUE;
        if ($slot !== NULL) {
          // And the slot that it lives in.
          $graph[$parent_uuid]['slot_children'][$slot][] = $uuid;
          // And the delta position it has in this slot.
          $graph[$uuid]['delta'] = \count($graph[$parent_uuid]['slot_children'][$slot]) - 1;
        }
      }
      else {
        $graph[$uuid]['delta'] = $top_level_delta;
        $top_level_delta++;
      }
    }

    // Then sort the graph.
    $sorted_graph = (new Graph($graph))->searchAndSort();
    \uasort($sorted_graph, SortArray::sortByWeightElement(...));

    // Keep track of the component items by their UUID.
    /** @var ComponentTreeItemKeyedSequence $tree */
    $uuid_lookup = \array_combine(\array_column($tree, 'uuid'), $tree);
    $keyed_tree = [];
    $parent_key_lookup = [];

    // Loop over each vertex in the graph and construct a keyed array.
    foreach ($sorted_graph as $uuid => $graph) {
      // If this UUID is not in the lookup, it could mean that there is an
      // invalid parent_uuid, but that parent item does not exist in the tree.
      // Validation doesn't happen until after this, so we can't rely on it
      // here.
      if (!\array_key_exists($uuid, $uuid_lookup)) {
        continue;
      }
      // Grab our item from the lookup.
      $item = $uuid_lookup[$uuid];
      if (!\array_key_exists('slot', $graph)) {
        $delta = (string) $graph['delta'];
        // This is a top level component instance, use its original input order.
        $keyed_tree[$delta] = $item;
        // Record the key of this component instance for child component
        // instances to use when constructing their key.
        $parent_key_lookup[$uuid] = $delta;
        continue;
      }
      \assert(\array_key_exists('reverse_paths', $graph));
      $parents = \array_keys($graph['reverse_paths']);
      \assert(\count($parents) > 0);
      // The parent UUID is the first item in the reverse path.
      $parent_uuid = \reset($parents);
      if (!\array_key_exists($parent_uuid, $parent_key_lookup)) {
        throw new \InvalidArgumentException(\sprintf('Invalid component tree: component instance %s indicates it is a child of the parent component instance %s, but that parent does not exist in the tree.', $uuid, $parent_uuid));
      }
      // Start with the key of our parent.
      $key = $parent_key_lookup[$parent_uuid];
      // Then append the slot and our relative position (delta) in the slot.
      $key .= ':' . $graph['slot'] . ':' . $graph['delta'];
      // Store this key for any children to retrieve.
      $parent_key_lookup[$uuid] = $key;
      // Add this component to the keyed tree.
      $keyed_tree[$key] = $item;
    }

    // Finally, append the component instance UUID at the end of each key to:
    // - allow reliable config translations
    // - while avoiding it changing the position within the sequence.
    foreach ($keyed_tree as $key => $item) {
      $keyed_tree[$key . ':' . $item['uuid']] = $item;
      unset($keyed_tree[$key]);
    }

    // Order the items by the key.
    \ksort($keyed_tree);
    /** @var ComponentTreeItemKeyedSequence */
    return $keyed_tree;
  }

}
