<?php

declare(strict_types=1);

namespace Drupal\canvas\Controller;

use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\Component\Serialization\Json;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Sql\DefaultTableMapping;
use Drupal\Core\Entity\Sql\SqlContentEntityStorage;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Counts component instances per style option, for the brand kit.
 *
 * Counts the current revision of content, once per instance: translations
 * share styles, so they are not counted again. Config trees (patterns,
 * content templates) are not counted.
 *
 * Ponytail: one pass over every component tree table, uncached; it runs only
 * when a site builder opens the brand kit's Styles tab. Cache it with the
 * entity list tags if that becomes slow.
 *
 * @see \Drupal\canvas\Health\Doctor::runComponentStylesCheck()
 */
final class ApiStyleUsageController {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntityFieldManagerInterface $entityFieldManager,
    private readonly Connection $database,
  ) {}

  public function __invoke(): JsonResponse {
    $counts = [];
    $seen = [];
    foreach ($this->entityFieldManager->getFieldMapByFieldType(ComponentTreeItem::PLUGIN_ID) as $entity_type_id => $fields) {
      $storage = $this->entityTypeManager->getStorage($entity_type_id);
      if (!$storage instanceof SqlContentEntityStorage) {
        continue;
      }
      $mapping = $storage->getTableMapping();
      \assert($mapping instanceof DefaultTableMapping);
      $definitions = $this->entityFieldManager->getFieldStorageDefinitions($entity_type_id);
      foreach (\array_keys($fields) as $field_name) {
        if (!isset($definitions[$field_name])) {
          continue;
        }
        $column = static fn (string $property): string => $mapping->getFieldColumnName($definitions[$field_name], $property);
        $rows = $this->database->select($mapping->getDedicatedDataTableName($definitions[$field_name]), 't')
          ->fields('t', ['entity_id', $column('uuid'), $column('styles')])
          ->isNotNull($column('styles'))
          ->execute() ?? [];
        foreach ($rows as $row) {
          $key = $entity_type_id . ':' . $row->entity_id . ':' . $row->{$column('uuid')};
          if (isset($seen[$key])) {
            continue;
          }
          $seen[$key] = TRUE;
          foreach ((array) (Json::decode((string) $row->{$column('styles')}) ?? []) as $style_id => $option_id) {
            if (\is_string($option_id)) {
              $counts[$style_id][$option_id] = ($counts[$style_id][$option_id] ?? 0) + 1;
            }
          }
        }
      }
    }
    // JSON objects, even when empty.
    return new JsonResponse(['usage' => (object) \array_map(static fn (array $options): object => (object) $options, $counts)]);
  }

}
