<?php

declare(strict_types=1);

namespace Drupal\canvas\Entity;

use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\Sql\SqlContentEntityStorageSchema;

/**
 * Defines the storage schema handler for comment threads.
 *
 * A base field is indexed only if its field type declares an index in
 * ::schema(), which `string` does not. The anchor fields are strings, so the
 * only way to index them is to add the indexes to the generated entity schema
 * here. Every comment thread lookup filters on the anchor.
 *
 * `canvas_comment.thread` needs no equivalent: it is an `entity_reference`,
 * and that field type does declare an index on its `target_id` column.
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
