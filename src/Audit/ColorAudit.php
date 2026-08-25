<?php

declare(strict_types=1);

namespace Drupal\canvas\Audit;

use Drupal\canvas\Entity\Color;
use Drupal\canvas\Entity\ComponentTreeEntityInterface;
use Drupal\canvas\JsonSchemaInterpreter\JsonSchemaType;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItemList;
use Drupal\canvas\Utility\ColorPropReferences;
use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\Query\ConditionInterface;
use Drupal\Core\Entity\Query\QueryInterface;

/**
 * Audits usage of Canvas Color entities in component trees.
 *
 * Colors are stored as 'canvas-color:<uuid>' string values in component
 * instance inputs. Config entities declare those references as config
 * dependencies, so config usage is answered by the config dependency graph.
 * Content entities have no such graph, so their usage is found by searching
 * component tree field tables at runtime.
 *
 * That search matches the raw stored JSON, which cannot tell a color prop from
 * a plain string prop holding the same characters. Its hits are therefore
 * candidates: each one is confirmed per prop against the component's JSON
 * schema, the same thing that decides a config dependency. Without that step
 * the two halves of this class would disagree about what a usage is.
 *
 * @see \Drupal\canvas\Plugin\DataType\ComponentInputs::calculateDependencies()
 * @see \Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItem::getPropNamesWithValue()
 */
final class ColorAudit extends ConfigAuditBase {

  /**
   * {@inheritdoc}
   */
  protected function addContentFieldConditions(QueryInterface $query, ConditionInterface $or_group, string $field_name, ConfigEntityInterface $target, array $version_ids): void {
    if ($version_ids !== []) {
      throw new \LogicException('Colors have no versions.');
    }
    $or_group->condition(\sprintf('%s.inputs', $field_name), self::getColorReference($target), 'CONTAINS');
  }

  /**
   * {@inheritdoc}
   */
  protected function componentTreeUsesAuditTarget(ComponentTreeItemList $tree, ConfigEntityInterface $target): bool {
    return self::findColorUsagesInComponentTree($tree, self::getColorReference($target)) !== [];
  }

  /**
   * {@inheritdoc}
   */
  protected function contentEntityUsesAuditTarget(ContentEntityInterface $entity, ConfigEntityInterface $target): bool {
    return self::findColorUsagesInEntity($entity, self::getColorReference($target)) !== [];
  }

  /**
   * Returns content entity color usages with component-level detail.
   *
   * For each content entity (e.g., canvas_page) that uses the color, returns
   * the entity data along with detailed information about each component
   * instance that references the color, including the prop name, component
   * UUID, component ID, and ancestor labels.
   *
   * @param \Drupal\canvas\Entity\Color $color
   *   The color entity to check.
   * @param \Drupal\canvas\Audit\RevisionAuditEnum $which_revisions
   *   Which revisions to check.
   *
   * @return array<int, array{entity: \Drupal\Core\Entity\ContentEntityInterface, usages: array<int, array{component_uuid: string, component_id: string, label: string|null, prop_name: string, ancestor_labels: array<string>}>}>
   *   Array of entries, each containing the entity and its color usages.
   */
  private function getContentColorUsagesWithDetail(Color $color, RevisionAuditEnum $which_revisions): array {
    $reference = self::getColorReference($color);
    $result = [];

    // Load candidates rather than confirmed revisions: confirming is what
    // produces the usages, so doing it here means doing it once.
    foreach ($this->loadContentRevisionCandidates($color, which_revisions: $which_revisions) as $entity) {
      $usages = self::findColorUsagesInEntity($entity, $reference);
      if ($usages === []) {
        continue;
      }
      $result[] = [
        'entity' => $entity,
        'usages' => $usages,
      ];
    }

    return $result;
  }

  /**
   * Returns config entity color usages with component-level detail.
   *
   * Similar to getContentColorUsagesWithDetail(), but for config entities
   * (ContentTemplate, PageRegion, Pattern).
   *
   * @param \Drupal\canvas\Entity\Color $color
   *   The color entity to check.
   *
   * @return array<int, array{entity: \Drupal\Core\Config\Entity\ConfigEntityInterface, usages: array<int, array{component_uuid: string, component_id: string, label: string|null, prop_name: string, ancestor_labels: array<string>}>}>
   *   Array of entries, each containing the entity and its color usages.
   *
   * @see \Drupal\canvas\Plugin\DataType\ComponentInputs::calculateDependencies()
   */
  public function getConfigColorUsagesWithDetail(Color $color): array {
    $reference = self::getColorReference($color);
    $result = [];

    foreach ($this->getComponentTreeConfigEntityTypeIds() as $entity_type_id) {
      foreach ($this->getConfigEntityDependenciesUsingAuditTarget($color, $entity_type_id) as $entity) {
        \assert($entity instanceof ComponentTreeEntityInterface);
        $usages = self::findColorUsagesInComponentTree($entity->getComponentTree(), $reference);
        if (!empty($usages)) {
          $result[] = [
            'entity' => $entity,
            'usages' => $usages,
          ];
        }
      }
    }

    return $result;
  }

  /**
   * Splits content entity usages with detail into current and prior.
   *
   * A usage is 'current' when it is one the delete gate blocks on, so that the
   * two agree: the default revision *and* the latest one count, because either
   * can end up rendered. Everything else is 'prior' — a superseded revision,
   * which does not block deletion.
   *
   * @param \Drupal\canvas\Entity\Color $color
   *   The color entity to check.
   *
   * @return array{current: array<int, array{entity: \Drupal\Core\Entity\ContentEntityInterface, usages: array<int, array{component_uuid: string, component_id: string, label: string|null, prop_name: string, ancestor_labels: array<string>}>}>, prior: array<int, array{entity: \Drupal\Core\Entity\ContentEntityInterface, usages: array<int, array{component_uuid: string, component_id: string, label: string|null, prop_name: string, ancestor_labels: array<string>}>}>}
   *   Associative array with 'current' and 'prior' keys.
   *
   * @see \Drupal\canvas\EntityHandlers\ColorAccessControlHandler::checkAccess()
   */
  public function getContentColorUsagesWithDetailSplit(Color $color): array {
    $split = ['current' => [], 'prior' => []];
    foreach ($this->getContentColorUsagesWithDetail($color, RevisionAuditEnum::All) as $entry) {
      $entity = $entry['entity'];
      // Matches ColorAccessControlHandler: default and latest revisions block
      // deletion. Non-revisionable entities have only one.
      $is_current = !$entity->getEntityType()->isRevisionable()
        || $entity->isDefaultRevision()
        || $entity->isLatestRevision();
      $split[$is_current ? 'current' : 'prior'][] = $entry;
    }
    return $split;
  }

  /**
   * Returns the value a color prop holds while it points at the given color.
   */
  private static function getColorReference(ConfigEntityInterface $color): string {
    \assert($color instanceof Color);
    $color_id = $color->id();
    \assert(\is_string($color_id));
    return ColorPropReferences::reference($color_id);
  }

  /**
   * Finds color usages within a content entity.
   *
   * Iterates all component_tree fields on every translation of the entity and
   * collects usages.
   *
   * TRICKY: the entity query that produced this candidate matches the field
   * table across all translations, but a loaded revision is its default
   * translation. Component tree fields are translatable, so a translation can
   * use the color while the default translation does not — inspecting only the
   * latter would drop the candidate and report the color unused.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The content entity to search.
   * @param string $reference
   *   The stored value a color prop holds while pointing at the color.
   *
   * @return array<int, array{component_uuid: string, component_id: string, label: string|null, prop_name: string, ancestor_labels: array<string>}>
   *   Array of usage details. A component instance prop that uses the color in
   *   more than one translation is reported once, as it is in the first
   *   translation found to use it.
   */
  private static function findColorUsagesInEntity(ContentEntityInterface $entity, string $reference): array {
    $usages = [];

    foreach (\array_keys($entity->getTranslationLanguages()) as $langcode) {
      $translation = $entity->getTranslation($langcode);
      // Iterate all fields looking for component_tree type fields.
      foreach ($translation->getFieldDefinitions() as $field_name => $field_definition) {
        if ($field_definition->getType() !== ComponentTreeItem::PLUGIN_ID) {
          continue;
        }

        $field = $translation->get($field_name);
        if (!$field instanceof ComponentTreeItemList) {
          continue;
        }

        foreach (self::findColorUsagesInComponentTree($field, $reference) as $usage) {
          $usages[$usage['component_uuid'] . ':' . $usage['prop_name']] ??= $usage;
        }
      }
    }

    return \array_values($usages);
  }

  /**
   * Finds color usages within a component tree list.
   *
   * @param \Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItemList $tree
   *   The component tree to search.
   * @param string $reference
   *   The stored value a color prop holds while pointing at the color.
   *
   * @return array<int, array{component_uuid: string, component_id: string, label: string|null, prop_name: string, ancestor_labels: array<string>}>
   *   Array of usage details.
   */
  private static function findColorUsagesInComponentTree(ComponentTreeItemList $tree, string $reference): array {
    $usages = [];

    foreach ($tree as $item) {
      \assert($item instanceof ComponentTreeItem);
      // TRICKY: the entity query that produced these candidates matches the
      // raw stored JSON, so it also hits a plain string prop that merely looks
      // like a color reference. Confirm per prop against the component's JSON
      // schema, which is what decides a config dependency too — otherwise the
      // audit and the config dependency graph would give different answers.
      $prop_names = $item->getPropNamesWithValue(JsonSchemaType::COLOR_SCHEMA_REF, $reference);
      if ($prop_names === []) {
        continue;
      }

      // Build ancestor labels by walking up the parent chain.
      $ancestor_labels = self::buildAncestorLabels($tree, $item);

      $component = $item->getComponent();
      $label = $item->getLabel();
      // Fall back to component type label if no instance label is set.
      if ($label === NULL) {
        $label = $component !== NULL ? (string) $component->label() : NULL;
      }
      else {
        $label = (string) $label;
      }

      foreach ($prop_names as $prop_name) {
        $usages[] = [
          'component_uuid' => $item->getUuid(),
          'component_id' => $item->getComponentId(),
          'label' => $label,
          'prop_name' => $prop_name,
          'ancestor_labels' => $ancestor_labels,
        ];
      }
    }

    return $usages;
  }

  /**
   * Builds ancestor labels by walking up the component tree parent chain.
   *
   * @param \Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItemList $tree
   *   The component tree containing the item.
   * @param \Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItem $item
   *   The component item to build ancestors for.
   *
   * @return array<int, string>
   *   Array of ancestor labels from root to immediate parent.
   */
  private static function buildAncestorLabels(ComponentTreeItemList $tree, ComponentTreeItem $item): array {
    $labels = [];
    $current_uuid = $item->getParentUuid();
    $visited_uuids = [];

    // Walk up the parent chain until we hit a root (null parent_uuid).
    while ($current_uuid !== NULL) {
      if (isset($visited_uuids[$current_uuid])) {
        break;
      }
      $visited_uuids[$current_uuid] = TRUE;

      $parent_item = $tree->getComponentTreeItemByUuid($current_uuid);
      if ($parent_item === NULL) {
        break;
      }

      // Get the label: user label, or component type label, or component ID.
      $label = $parent_item->getLabel();
      if ($label === NULL) {
        $component = $parent_item->getComponent();
        $label = (string) ($component?->label() ?? $parent_item->getComponentId());
      }

      // Prepend to maintain root-first order.
      \array_unshift($labels, (string) $label);

      $current_uuid = $parent_item->getParentUuid();
    }

    return $labels;
  }

}
