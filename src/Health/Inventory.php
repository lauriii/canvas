<?php

declare(strict_types=1);

namespace Drupal\canvas\Health;

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\Entity\StagedConfigUpdate;
use Drupal\canvas\Entity\StagedLanguageConfigOverride;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\Core\Config\Entity\ConfigEntityTypeInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\RevisionableStorageInterface;

/**
 * Finds and counts every entity Doctor might need to check.
 *
 * Doctor owns validating and caching; this owns figuring out what exists to
 * validate in the first place, and how much of it there is.
 *
 * Deliberately does not compute a freshness signature for the CheckEntity
 * items it returns (do not add one, e.g. as a method on CheckEntity).
 * Doctor paginates its checks across possibly-separate cron runs, so it may
 * not get around to actually checking an item until well after this class
 * listed it. A freshness signature has to reflect how things look right when
 * Doctor checks the item, not how they looked back when it was listed here,
 * or it stops meaning anything.
 *
 * Callers ask for a HealthCheck case via getItems()/count() rather than
 * calling a check-specific method by name — the mapping from a check to how
 * it's listed/counted lives entirely in this class.
 *
 * @internal
 */
final readonly class Inventory {

  /**
   * Canvas config entity types that are transient staging, not site data.
   *
   * @see \Drupal\canvas\EntityHandlers\StagedConfigEntityStorageTrait
   */
  private const array EXCLUDED_CONFIG_ENTITY_TYPES = [
    StagedLanguageConfigOverride::ENTITY_TYPE_ID,
    StagedConfigUpdate::ENTITY_TYPE_ID,
  ];

  public function __construct(
    private EntityTypeManagerInterface $entityTypeManager,
    private EntityFieldManagerInterface $entityFieldManager,
    private AutoSaveManager $autoSaveManager,
  ) {}

  /**
   * @return string[]
   */
  private function canvasConfigEntityTypeIds(): array {
    $entity_type_ids = [];
    foreach ($this->entityTypeManager->getDefinitions() as $entity_type_id => $definition) {
      if ($definition instanceof ConfigEntityTypeInterface
        && $definition->getProvider() === 'canvas'
        && !\in_array($entity_type_id, self::EXCLUDED_CONFIG_ENTITY_TYPES, TRUE)) {
        $entity_type_ids[] = $entity_type_id;
      }
    }
    return $entity_type_ids;
  }

  /**
   * Finds every item a given data check needs to validate.
   *
   * $offset/$limit restrict the result to that window, e.g. for Doctor to
   * page through a check's items across separate cron runs. The full list is
   * always computed first (every branch below needs it anyway for count()),
   * then windowed; array_slice() is skipped for the unwindowed default
   * (offset=0, limit=NULL) to avoid copying.
   *
   * @return list<CheckEntity>
   *
   * @throws \LogicException
   *   When $check is a system check.
   */
  public function getItems(HealthCheck $check, int $offset = 0, ?int $limit = NULL): array {
    $items = match ($check) {
      HealthCheck::Config => $this->configEntityItems(),
      HealthCheck::Content => $this->defaultRevisionItems(),
      HealthCheck::ContentPastRevisions => $this->pastRevisionItems(),
      HealthCheck::ContentForwardRevisions => $this->forwardRevisionItems(),
      HealthCheck::AutoSave => $this->autoSaveItems(),
      default => throw new \LogicException(\sprintf('%s has no items; only data checks can be listed.', $check->value)),
    };
    if ($offset === 0 && $limit === NULL) {
      return $items;
    }
    return \array_slice($items, $offset, $limit);
  }

  /**
   * Counts how many items a given data check would need to validate.
   *
   * @throws \LogicException
   *   When $check is a system check.
   */
  public function count(HealthCheck $check): int {
    return match ($check) {
      HealthCheck::Config => $this->countConfigEntityItems(),
      HealthCheck::Content => $this->countDefaultRevisionItems(),
      HealthCheck::ContentPastRevisions => \count($this->pastRevisionItems()),
      HealthCheck::ContentForwardRevisions => \count($this->forwardRevisionItems()),
      HealthCheck::AutoSave => \count($this->autoSaveItems()),
      default => throw new \LogicException(\sprintf('%s has no items; only data checks can be counted.', $check->value)),
    };
  }

  /**
   * Finds every Canvas config entity to check.
   *
   * @return list<CheckEntity>
   *   Sorted by entity type ID, then entity ID.
   */
  private function configEntityItems(): array {
    $items = [];
    foreach ($this->canvasConfigEntityTypeIds() as $entity_type_id) {
      $definition = $this->entityTypeManager->getDefinition($entity_type_id);
      \assert($definition instanceof ConfigEntityTypeInterface);
      $storage = $this->entityTypeManager->getStorage($entity_type_id);
      foreach ($storage->getQuery()->accessCheck(FALSE)->execute() as $id) {
        $items[] = new CheckEntity($definition, (string) $id);
      }
    }
    $sort = function (CheckEntity $a, CheckEntity $b): int {
      $a = [$a->entityType->id(), $a->entityId];
      $b = [$b->entityType->id(), $b->entityId];
      return $a <=> $b;
    };
    \usort($items, $sort);
    return $items;
  }

  /**
   * Count-only variant of configEntityItems(); keep the two in sync.
   *
   * @see configEntityItems()
   */
  private function countConfigEntityItems(): int {
    $count = 0;
    foreach ($this->canvasConfigEntityTypeIds() as $entity_type_id) {
      $count += (int) $this->entityTypeManager->getStorage($entity_type_id)
        ->getQuery()
        ->accessCheck(FALSE)
        ->count()
        ->execute();
    }
    return $count;
  }

  /**
   * Finds every content entity type Canvas can lay out a page onto.
   *
   * Canvas attaches a page layout to content through a "component tree"
   * field, so only entity types with that field matter to the checks below.
   *
   * @return string[]
   */
  private function canvasContentEntityTypeIds(): array {
    return \array_keys($this->entityFieldManager->getFieldMapByFieldType(ComponentTreeItem::PLUGIN_ID));
  }

  /**
   * Finds the default revision of every content entity Canvas can lay out.
   *
   * For entity types that support revisions, the default revision is the
   * one visitors see (as opposed to a newer, unpublished draft — see
   * forwardRevisionItems() and pastRevisionItems() for those). Entity types
   * that don't support revisions at all only ever have one version of each
   * entity, so that one version always counts as the default.
   *
   * @return list<CheckEntity>
   *   Sorted by entity type ID, then entity ID.
   */
  private function defaultRevisionItems(): array {
    $items = [];
    foreach ($this->canvasContentEntityTypeIds() as $entity_type_id) {
      $storage = $this->entityTypeManager->getStorage($entity_type_id);
      $entity_type = $this->entityTypeManager->getDefinition($entity_type_id);
      $id_query = $storage->getQuery()->accessCheck(FALSE);
      // Only revisionable entity types understand "just the default
      // revision, please" — for the rest, every entity has one version, so
      // there's nothing extra to ask for.
      if ($entity_type->isRevisionable()) {
        $id_query->currentRevision();
      }
      foreach ($id_query->execute() as $entity_id) {
        $items[] = new CheckEntity($entity_type, (string) $entity_id);
      }
    }
    $sort = function (CheckEntity $a, CheckEntity $b): int {
      $a = [$a->entityType->id(), $a->entityId];
      $b = [$b->entityType->id(), $b->entityId];
      return $a <=> $b;
    };
    \usort($items, $sort);
    return $items;
  }

  /**
   * Count-only variant of defaultRevisionItems(); keep the two in sync.
   *
   * @see defaultRevisionItems()
   */
  private function countDefaultRevisionItems(): int {
    $count = 0;
    foreach ($this->canvasContentEntityTypeIds() as $entity_type_id) {
      $storage = $this->entityTypeManager->getStorage($entity_type_id);
      $entity_type = $this->entityTypeManager->getDefinition($entity_type_id);
      $query = $storage->getQuery()->accessCheck(FALSE);
      if ($entity_type->isRevisionable()) {
        $query->currentRevision();
      }
      $count += (int) $query->count()->execute();
    }
    return $count;
  }

  /**
   * Finds each entity whose newest revision is an unpublished draft.
   *
   * "Forward" as in ahead of the default revision: a revision that exists
   * and is newer, but isn't the one visitors see yet (see
   * defaultRevisionItems() for that one). Once that draft is published, it
   * becomes the new default and stops showing up here.
   *
   * @return list<CheckEntity>
   *   Sorted by entity type ID, then revision ID. Each has $revisionId set
   *   to the forward revision's ID.
   */
  private function forwardRevisionItems(): array {
    $forward = [];
    foreach ($this->canvasContentEntityTypeIds() as $entity_type_id) {
      $entity_type = $this->entityTypeManager->getDefinition($entity_type_id);
      if (!$entity_type->isRevisionable()) {
        continue;
      }
      $storage = $this->entityTypeManager->getStorage($entity_type_id);
      \assert($storage instanceof RevisionableStorageInterface);
      $latest_rid_map = $storage->getQuery()->accessCheck(FALSE)->latestRevision()->execute();
      $default_rid_map = $storage->getQuery()->accessCheck(FALSE)->currentRevision()->execute();

      // $latest_rid_map is keyed by revision ID, not entity ID. To look up
      // "what's this entity's default revision?" in the loop below, flip
      // $default_rid_map around into entity ID => default revision ID.
      $default_rid_by_entity_id = \array_flip(\array_map('strval', $default_rid_map));

      foreach ($latest_rid_map as $rid => $entity_id) {
        $default_rid = $default_rid_by_entity_id[(string) $entity_id] ?? NULL;
        // Nothing to report if the newest revision is already the default.
        if ($default_rid === NULL || (string) $rid === (string) $default_rid) {
          continue;
        }
        $forward[] = new CheckEntity($entity_type, (string) $entity_id, revisionId: (string) $rid);
      }
    }
    $sort = function (CheckEntity $a, CheckEntity $b): int {
      $a = [$a->entityType->id(), $a->revisionId];
      $b = [$b->entityType->id(), $b->revisionId];
      return $a <=> $b;
    };
    \usort($forward, $sort);
    return $forward;
  }

  /**
   * Finds every content revision that isn't the default or latest one.
   *
   * A content entity can have many revisions, but only two of them get
   * checked elsewhere: the default revision (what visitors see, checked by
   * defaultRevisionItems()) and the latest revision (the newest draft,
   * checked by forwardRevisionItems()). This method finds everything else —
   * old drafts, old published versions — so those get checked too, without
   * checking any revision twice.
   *
   * @return list<CheckEntity>
   *   Sorted by entity type ID, then revision ID. Each has $revisionId set
   *   to the past revision's ID.
   */
  private function pastRevisionItems(): array {
    $past = [];
    foreach ($this->canvasContentEntityTypeIds() as $entity_type_id) {
      $entity_type = $this->entityTypeManager->getDefinition($entity_type_id);
      if (!$entity_type->isRevisionable()) {
        continue;
      }
      $storage = $this->entityTypeManager->getStorage($entity_type_id);
      \assert($storage instanceof RevisionableStorageInterface);

      // A "past" revision is any revision that's neither the default one
      // (the one visitors see, checked by defaultRevisionItems()) nor the
      // latest one (the newest draft, checked by forwardRevisionItems()).
      // There's no way to ask the database for "every revision except
      // these two" directly, so instead we fetch every revision ID, then
      // remove the default and latest ones from that list ourselves.
      $default_rid_map = $storage->getQuery()->accessCheck(FALSE)->currentRevision()->execute();
      $latest_rid_map = $storage->getQuery()->accessCheck(FALSE)->latestRevision()->execute();

      // We only need the revision IDs here, not which entity each belongs
      // to, so combine the default and latest IDs into one list, then turn
      // that list into a lookup table: revision_id => TRUE. That way, the
      // loop below can ask "should this revision be skipped?" with a quick
      // isset() check, instead of searching the whole list every time.
      $excluded_rids = \array_fill_keys(
        \array_map('strval', [...\array_keys($default_rid_map), ...\array_keys($latest_rid_map)]),
        TRUE,
      );

      // Revision queries return revision_id => entity_id.
      $all_rid_map = $storage->getQuery()->accessCheck(FALSE)->allRevisions()->execute();
      foreach ($all_rid_map as $rid => $entity_id) {
        $rid = (string) $rid;
        $entity_id = (string) $entity_id;
        if (!isset($excluded_rids[$rid])) {
          $past[] = new CheckEntity($entity_type, $entity_id, revisionId: $rid);
        }
      }
    }
    $sort = function (CheckEntity $a, CheckEntity $b): int {
      $a = [$a->entityType->id(), $a->revisionId];
      $b = [$b->entityType->id(), $b->revisionId];
      return $a <=> $b;
    };
    \usort($past, $sort);
    return $past;
  }

  /**
   * Finds every auto-save entry whose entity can still be loaded.
   *
   * An auto-save entry is a snapshot of unpublished edits someone made in
   * the Canvas editor. Doctor validates that snapshot as if it were about
   * to be saved for real. Conflict information (used elsewhere to warn
   * editors about overlapping edits) isn't needed for that, so it's
   * skipped here to avoid computing it for nothing.
   *
   * @return list<CheckEntity>
   *   Auto-save entries with a loadable entity, in deterministic order.
   *   Each has $langcode, $dataHash, and $entity set.
   */
  private function autoSaveItems(): array {
    $items = [];
    foreach ($this->autoSaveManager->getAllAutoSaveList(with_entities: TRUE, with_conflicts: FALSE) as $entry) {
      $entity = $entry['entity'] ?? NULL;
      // The entity behind a snapshot may no longer exist, e.g. it was
      // deleted after the snapshot was made; skip anything that didn't load.
      if (!$entity instanceof EntityInterface) {
        continue;
      }
      $entity_type = $this->entityTypeManager->getDefinition((string) $entry['entity_type']);
      $items[] = new CheckEntity(
        entityType: $entity_type,
        entityId: (string) $entry['entity_id'],
        langcode: $entry['langcode'] ?? $entity->language()->getId(),
        dataHash: (string) $entry['data_hash'],
        entity: $entity,
      );
    }
    return $items;
  }

}
