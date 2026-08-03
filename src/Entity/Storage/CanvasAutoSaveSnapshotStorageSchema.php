<?php

declare(strict_types=1);

namespace Drupal\canvas\Entity\Storage;

use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\Sql\SqlContentEntityStorageSchema;

/**
 * Adds the one-row-per-target-per-workspace unique key for snapshots.
 *
 * Concurrent first saves for the same target must not create duplicate
 * snapshot rows; the write path upserts on this key. The workspace is part
 * of the key: each workspace stages its own draft of the same target.
 *
 * @see \Drupal\canvas\AutoSave\Workspace\AutoSaveSnapshotRepository::persist()
 */
final class CanvasAutoSaveSnapshotStorageSchema extends SqlContentEntityStorageSchema {

  /**
   * {@inheritdoc}
   */
  protected function getEntitySchema(ContentEntityTypeInterface $entity_type, $reset = FALSE): array {
    $schema = parent::getEntitySchema($entity_type, $reset);
    $schema['canvas_auto_save_snapshot']['unique keys']['canvas_auto_save_snapshot__target'] = [
      'workspace',
      'target_entity_type_id',
      'target_entity_id',
      'target_langcode',
    ];
    return $schema;
  }

}
