<?php

declare(strict_types=1);

namespace Drupal\canvas\Audit;

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\Entity\ComponentInterface;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItemList;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigManagerInterface;
use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\ConditionInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Audits usage of Canvas Component entities in component trees.
 *
 * A component instance stores the component ID and version it uses, so the
 * entity query is exact and no confirmation step is needed.
 */
final class ComponentAudit extends ConfigAuditBase {

  /**
   * The cache ID of the component IDs used by auto-saved component trees.
   */
  private const string AUTO_SAVE_COMPONENT_IDS_CID = 'canvas.component_audit.auto_save_component_ids';

  public function __construct(
    ConfigManagerInterface $configManager,
    EntityTypeManagerInterface $entityTypeManager,
    EntityFieldManagerInterface $entityFieldManager,
    AutoSaveManager $autoSaveManager,
    // The in-memory cache that AutoSaveManager invalidates, by cache tag, on
    // every auto-save write.
    #[Autowire(service: 'canvas.auto_save.entity_memory_cache')]
    private readonly CacheBackendInterface $autoSaveMemoryCache,
  ) {
    parent::__construct($configManager, $entityTypeManager, $entityFieldManager, $autoSaveManager);
  }

  /**
   * {@inheritdoc}
   */
  protected function addContentFieldConditions(QueryInterface $query, ConditionInterface $or_group, string $field_name, ConfigEntityInterface $target, array $version_ids): void {
    \assert($target instanceof ComponentInterface);
    if ($version_ids) {
      $and_group = $query->andConditionGroup();
      $and_group->condition(\sprintf('%s.component_id', $field_name), [$target->id()], 'IN');
      $and_group->condition(\sprintf('%s.component_version', $field_name), $version_ids, 'IN');
      $or_group->condition($and_group);
      return;
    }
    $or_group->condition(\sprintf('%s.component_id', $field_name), [$target->id()], 'IN');
  }

  /**
   * {@inheritdoc}
   */
  protected function componentTreeUsesAuditTarget(ComponentTreeItemList $tree, ConfigEntityInterface $target, array $version_ids = []): bool {
    \assert($target instanceof ComponentInterface);
    if ($version_ids === []) {
      return \in_array($target->id(), $tree->getComponentIdList(), TRUE);
    }
    foreach ($tree->componentTreeItemsIterator() as $item) {
      if ($item->getComponentId() === $target->id() && \in_array($item->getComponentVersion(), $version_ids, TRUE)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * {@inheritdoc}
   *
   * The entity query is exact, so its result answers this without loading any
   * content revision.
   */
  public function hasContentUsages(ConfigEntityInterface $target, RevisionAuditEnum $which_revisions = RevisionAuditEnum::All): bool {
    foreach ($this->getContentRevisionIdsUsingAuditTarget($target, which_revisions: $which_revisions) as $ids) {
      if ($ids !== []) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * {@inheritdoc}
   *
   * Rebuilding every auto-save is expensive, and listing code components asks
   * this for each of them. So the IDs of all components used by auto-saves are
   * computed once, and kept until the next auto-save write invalidates them.
   */
  public function hasAutoSaveUsages(ConfigEntityInterface $target): bool {
    \assert($target instanceof ComponentInterface);
    return isset($this->getComponentIdsUsedByAutoSaves()[$target->id()]);
  }

  /**
   * Gets the IDs of the components used by auto-saved component trees.
   *
   * @return array<string, true>
   *   The component IDs, as keys.
   */
  private function getComponentIdsUsedByAutoSaves(): array {
    $cached = $this->autoSaveMemoryCache->get(self::AUTO_SAVE_COMPONENT_IDS_CID);
    if ($cached !== FALSE) {
      return $cached->data;
    }
    $component_ids = [];
    foreach ($this->getAutoSavedComponentTrees() as [, $tree]) {
      $component_ids += \array_fill_keys($tree->getComponentIdList(), TRUE);
    }
    $this->autoSaveMemoryCache->set(self::AUTO_SAVE_COMPONENT_IDS_CID, $component_ids, tags: [AutoSaveManager::CACHE_TAG]);
    return $component_ids;
  }

}
