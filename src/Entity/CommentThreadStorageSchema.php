<?php

declare(strict_types=1);

namespace Drupal\canvas\Entity;

use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\Sql\SqlContentEntityStorageSchema;

/**
 * Defines the storage schema handler for comment threads.
 *
 * Indexes the anchor fields, which every thread lookup filters on. They are
 * `string` fields, and that field type declares no index of its own.
 *
 * @see \Drupal\canvas\Entity\CommentThread
 */
final class CommentThreadStorageSchema extends SqlContentEntityStorageSchema {

  /**
   * {@inheritdoc}
   */
  protected function getEntitySchema(ContentEntityTypeInterface $entity_type, $reset = FALSE): array {
    $schema = parent::getEntitySchema($entity_type, $reset);
    $base_table = $entity_type->getBaseTable();
    \assert(\is_string($base_table));
    $schema[$base_table]['indexes'] = ($schema[$base_table]['indexes'] ?? []) + [
      'canvas_comment_thread__surface' => ['surface_type', 'surface_id'],
      'canvas_comment_thread__component_uuid' => ['component_uuid'],
    ];
    return $schema;
  }

}
