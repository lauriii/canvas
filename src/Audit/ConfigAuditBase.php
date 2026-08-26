<?php

declare(strict_types=1);

namespace Drupal\canvas\Audit;

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\Entity\ComponentTreeEntityInterface;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItemList;
use Drupal\Component\Assertion\Inspector;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Config\ConfigManagerInterface;
use Drupal\Core\Config\Entity\ConfigEntityDependency;
use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\Core\Config\Entity\ConfigEntityTypeInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Entity\Query\ConditionInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Entity\RevisionableStorageInterface;

/**
 * Audits where a config entity is used by component trees.
 *
 * Component trees live in three places: `component_tree` fields on content
 * entity revisions, config entities implementing ComponentTreeEntityInterface,
 * and auto-saves. This class knows how to search each of those. A subclass
 * only states how a tree references its audit target: which entity query
 * condition finds candidate content revisions, and what makes a tree match.
 *
 * @internal
 * @todo Improve in https://www.drupal.org/project/canvas/issues/3522953.
 */
abstract class ConfigAuditBase {

  public function __construct(
    protected readonly ConfigManagerInterface $configManager,
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly EntityFieldManagerInterface $entityFieldManager,
    protected readonly AutoSaveManager $autoSaveManager,
  ) {}

  /**
   * Adds the per-field entity query conditions finding content revisions.
   *
   * Conditions target content revisions that use the audit target.
   */
  abstract protected function addContentFieldConditions(QueryInterface $query, ConditionInterface $or_group, string $field_name, ConfigEntityInterface $target, array $version_ids): void;

  /**
   * Checks whether a component tree uses the audit target.
   */
  abstract protected function componentTreeUsesAuditTarget(ComponentTreeItemList $tree, ConfigEntityInterface $target): bool;

  /**
   * Confirms a loaded content revision uses the audit target.
   *
   * Override when the entity query only finds candidates.
   */
  protected function contentEntityUsesAuditTarget(ContentEntityInterface $entity, ConfigEntityInterface $target): bool {
    return TRUE;
  }

  public function getContentRevisionIdsUsingAuditTarget(ConfigEntityInterface $target, array $version_ids = [], RevisionAuditEnum $which_revisions = RevisionAuditEnum::All): array {
    // @see ::getAutoSavesUsingAuditTarget()
    if ($which_revisions === RevisionAuditEnum::AutoSave) {
      throw new \LogicException();
    }

    $field_map = $this->entityFieldManager->getFieldMapByFieldType(ComponentTreeItem::PLUGIN_ID);
    $dependencies = [];
    foreach ($field_map as $entity_type_id => $detail) {
      $field_names = \array_keys($detail);
      $storage = $this->entityTypeManager->getStorage($entity_type_id);
      $entity_type = $this->entityTypeManager->getDefinition($entity_type_id);
      $query = $storage->getQuery()->accessCheck(FALSE);
      if ($entity_type->isRevisionable()) {
        // Only check the latest revision, this is the case for code components
        // as deletion can only happen when it is not used, checking all
        // revisions is too restrictive.
        match ($which_revisions) {
          RevisionAuditEnum::All => $query->allRevisions(),
          RevisionAuditEnum::Default => $query->currentRevision(),
          RevisionAuditEnum::Latest => $query->latestRevision(),
        };
      }
      $or_group = $query->orConditionGroup();
      foreach ($field_names as $field_name) {
        $this->addContentFieldConditions($query, $or_group, (string) $field_name, $target, $version_ids);
      }
      $query->condition($or_group);
      $ids = $query->execute();
      ksort($ids);
      $dependencies[$entity_type_id] = $ids;
    }
    ksort($dependencies);
    return $dependencies;
  }

  /**
   * @return \Drupal\Core\Entity\ContentEntityInterface[]
   */
  public function getContentRevisionsUsingAuditTarget(ConfigEntityInterface $target, array $version_ids = [], RevisionAuditEnum $which_revisions = RevisionAuditEnum::All): array {
    return \array_values(\array_filter(
      $this->loadContentRevisionCandidates($target, $version_ids, $which_revisions),
      fn (ContentEntityInterface $entity): bool => $this->contentEntityUsesAuditTarget($entity, $target),
    ));
  }

  /**
   * Loads the content revisions the entity query finds, before confirmation.
   *
   * A subclass whose query only finds candidates, and that needs more than a
   * yes/no from confirming them, loads from here so it confirms each once.
   *
   * @return \Drupal\Core\Entity\ContentEntityInterface[]
   */
  protected function loadContentRevisionCandidates(ConfigEntityInterface $target, array $version_ids = [], RevisionAuditEnum $which_revisions = RevisionAuditEnum::All): array {
    // @see ::getAutoSavesUsingAuditTarget()
    if ($which_revisions === RevisionAuditEnum::AutoSave) {
      throw new \LogicException();
    }

    $entity_ids = $this->getContentRevisionIdsUsingAuditTarget($target, $version_ids, $which_revisions);
    $dependencies = [];
    foreach ($entity_ids as $entity_type_id => $ids) {
      $storage = $this->entityTypeManager->getStorage($entity_type_id);
      $entity_type = $this->entityTypeManager->getDefinition($entity_type_id);
      if ($ids !== NULL && \count($ids) > 0) {
        if ($entity_type->isRevisionable()) {
          \assert($storage instanceof RevisionableStorageInterface);
          $dependencies = \array_merge($dependencies, $storage->loadMultipleRevisions(\array_keys($ids)));
          continue;
        }
        $dependencies = \array_merge($dependencies, $storage->loadMultiple($ids));
      }
    }
    \assert(Inspector::assertAllObjects($dependencies, ContentEntityInterface::class));
    /** @var \Drupal\Core\Entity\ContentEntityInterface[] $dependencies */
    return $dependencies;
  }

  /**
   * @return array<\Drupal\Core\Config\Entity\ConfigEntityInterface>
   */
  public function getConfigEntityDependenciesUsingAuditTarget(ConfigEntityInterface $target, string $config_entity_type_id): array {
    $config_entity_definition = $this->entityTypeManager->getDefinition($config_entity_type_id);
    \assert($config_entity_definition instanceof ConfigEntityTypeInterface);
    $config_prefix = $config_entity_definition->getConfigPrefix() . '.';
    $dependents = $this->configManager->getConfigDependencyManager()->getDependentEntities('config', $target->getConfigDependencyName());
    $dependents = array_filter($dependents, fn(ConfigEntityDependency $dependency) => str_starts_with($dependency->getConfigDependencyName(), $config_prefix));
    $dependencies = \array_map(fn(ConfigEntityDependency $dependency): ?EntityInterface => $this->entityTypeManager->getStorage($config_entity_type_id)->load(str_replace($config_prefix, '', $dependency->getConfigDependencyName())), $dependents);
    \assert(Inspector::assertAllObjects($dependencies, ConfigEntityInterface::class));
    return $dependencies;
  }

  public function getConfigEntityUsageCount(ConfigEntityInterface $target): int {
    // @todo Add static caching in https://www.drupal.org/i/3522953 — config cannot change mid-request
    return count($this->configManager->getConfigDependencyManager()->getDependentEntities('config', $target->getConfigDependencyName()));
  }

  public function hasUsages(ConfigEntityInterface $target, RevisionAuditEnum $which_revisions = RevisionAuditEnum::All): bool {
    // Special case: auto-saves.
    if ($which_revisions === RevisionAuditEnum::AutoSave) {
      return !empty($this->getAutoSavesUsingAuditTarget($target));
    }

    // @todo Field config default values
    // @todo Base field definition default values
    // @todo What if there are asymmetric content translations, or the translated
    //   config provide different defaults? Verify and test in
    //   https://www.drupal.org/i/3522198
    $entity_types = $this->getComponentTreeConfigEntityTypeIds();
    \assert(\count($entity_types) > 0);
    // Check config entities first as the calculation is less expensive.
    foreach ($entity_types as $entity_type_id) {
      $usages = $this->getConfigEntityDependenciesUsingAuditTarget($target, $entity_type_id);
      if (\count($usages) > 0) {
        return TRUE;
      }
    }
    return $this->hasContentUsages($target, $which_revisions);
  }

  /**
   * Checks if the audit target is used by a content entity revision.
   *
   * Config usage is deliberately not considered: callers either consult the
   * config dependency graph themselves, or must not consult it because the
   * operation in progress is changing it.
   */
  public function hasContentUsages(ConfigEntityInterface $target, RevisionAuditEnum $which_revisions = RevisionAuditEnum::All): bool {
    return $this->getContentRevisionsUsingAuditTarget($target, which_revisions: $which_revisions) !== [];
  }

  /**
   * Returns auto-saved component trees using the audit target.
   *
   * Auto-saves are unsaved drafts, so they are invisible to entity queries and
   * must be scanned separately.
   *
   * @return array<string, array<string, int|string>>
   *   Array keyed by entity type ID, each mapping a simulated revision ID to
   *   an entity ID.
   */
  public function getAutoSavesUsingAuditTarget(ConfigEntityInterface $target, array $version_ids = []): array {
    if (!empty($version_ids)) {
      // @todo Support checking specific versions of components.
      throw new \LogicException('not yet implemented');
    }
    $dependencies = [];
    foreach ($this->autoSaveManager->getAllAutoSaveList(with_entities: TRUE, with_conflicts: FALSE) as $autoSave) {
      $entity = $autoSave['entity'];
      \assert(!\is_null($entity));
      if (!$entity instanceof ComponentTreeEntityInterface) {
        // @todo Post-1.0, the restrictions that https://www.drupal.org/i/3520487 added will be lifted, meaning node component trees can be edited again. This will then need to be expanded to use the ComponentTreeLoader when appropriate.
        if ($entity instanceof FieldableEntityInterface) {
          // @phpcs:ignore Drupal.Semantics.FunctionTriggerError.TriggerErrorTextLayoutRelaxed
          trigger_error(\sprintf('Not yet implemented: auto-save usages for %s entities.', $entity->getEntityTypeId()), E_USER_DEPRECATED);
        }
        continue;
      }
      if ($this->componentTreeUsesAuditTarget($entity->getComponentTree(), $target)) {
        $entity_type_id = $autoSave['entity_type'];
        $entity_id = $autoSave['entity_id'];
        $simulated_revision_id = 'auto-save-' . $autoSave['data_hash'];
        $dependencies[$entity_type_id][$simulated_revision_id] = $entity_id;
      }
    }
    return $dependencies;
  }

  /**
   * Returns the cache tags any answer from this audit depends on.
   *
   * A usage answer is derived from content entity revisions, from config
   * entity component trees, and from auto-saves. None of those is a cacheable
   * dependency of the audit target itself. Anything cached alongside such an
   * answer — a delete access result, most of all — must carry these, or it
   * outlives the usage it reports.
   *
   * Config entity types are covered as well as content ones: a Pattern that
   * starts using the target changes the answer just as a Page does. Their list
   * cache tags suffice, because ConfigEntityBase::invalidateTagsOnSave()
   * invalidates those on every save, not only on create.
   *
   * @return string[]
   *   Cache tags.
   */
  public function getUsageCacheTags(): array {
    $tags = [AutoSaveManager::CACHE_TAG];
    $entity_type_ids = \array_merge(
      \array_keys($this->entityFieldManager->getFieldMapByFieldType(ComponentTreeItem::PLUGIN_ID)),
      $this->getComponentTreeConfigEntityTypeIds(),
    );
    foreach ($entity_type_ids as $entity_type_id) {
      $tags = Cache::mergeTags($tags, \array_values($this->entityTypeManager->getDefinition($entity_type_id)->getListCacheTags()));
    }
    // Cache::mergeTags() preserves insertion order, which here is entity type
    // discovery order. Sort so the result is stable enough to assert against.
    \sort($tags);
    return $tags;
  }

  /**
   * Returns the IDs of config entity types that carry a component tree.
   *
   * @return string[]
   *   Entity type IDs.
   */
  protected function getComponentTreeConfigEntityTypeIds(): array {
    return \array_keys(\array_filter($this->entityTypeManager->getDefinitions(), static fn (EntityTypeInterface $type): bool => $type instanceof ConfigEntityTypeInterface && $type->entityClassImplements(ComponentTreeEntityInterface::class)));
  }

}
