<?php

declare(strict_types=1);

namespace Drupal\canvas\Health;

use Drupal\Core\Config\Entity\ConfigEntityTypeInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeInterface;

/**
 * Identifies one entity — or a revision or translation of one — to check.
 *
 * $revisionId is NULL for config entities, default-revision content, and
 * auto-save entries; it's set only for past and forward revisions.
 * $langcode, $dataHash, and $entity are set only for auto-save entries: a
 * snapshot is always per-translation and already has its entity loaded. For
 * everything else, Doctor loads the entity itself from $entityType and
 * $entityId (or $revisionId).
 *
 * @internal
 */
final readonly class CheckEntity {

  public function __construct(
    public EntityTypeInterface $entityType,
    public string $entityId,
    public ?string $revisionId = NULL,
    public ?string $langcode = NULL,
    public ?string $dataHash = NULL,
    public ?EntityInterface $entity = NULL,
  ) {}

  /**
   * The full config object name, e.g. "canvas.component.my_button".
   *
   * Only meaningful when $entityType is a config entity type.
   *
   * @see \Drupal\Core\Config\Entity\ConfigEntityBase::getConfigDependencyName
   */
  public function configName(): string {
    \assert($this->entityType instanceof ConfigEntityTypeInterface);
    // Same implementation than ConfigEntityBase::getConfigDependencyName,
    // but we avoid loading the config entity.
    return $this->entityType->getConfigPrefix() . '.' . $this->entityId;
  }

}
