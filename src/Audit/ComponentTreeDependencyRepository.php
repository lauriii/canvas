<?php

declare(strict_types=1);

namespace Drupal\experience_builder\Audit;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\DatabaseException;
use Drupal\Core\Database\Query\Delete;
use Drupal\Core\Database\Query\Insert;
use Drupal\Core\Database\Query\Merge;
use Drupal\Core\Database\Query\Select;
use Drupal\Core\Database\Query\SelectInterface;
use Drupal\Core\Database\Query\Update;
use Drupal\Core\Database\StatementInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Entity\RevisionableInterface;
use Drupal\Core\Entity\RevisionableStorageInterface;
use Drupal\Core\Pager\PagerManagerInterface;
use Drupal\Core\Pager\PagerParametersInterface;
use Drupal\experience_builder\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\field\FieldConfigInterface;

/**
 * Repository for storing content entities' component tree dependencies.
 *
 * This stores the full dependency information of every component tree of every
 * revision and translation of every XB field instance in an efficiently
 * queryable table.
 *
 * @see \Drupal\experience_builder\Plugin\Field\FieldType\ComponentTreeItem::calculateFieldItemValueDependencies()
 */
final class ComponentTreeDependencyRepository {

  private const string TABLE_NAME = 'xb_component_tree_dependencies';

  private const string SOURCE_ID = 'source_id';
  private const string SOURCE_ID_STRING = 'source_id_string';
  private const string SOURCE_TYPE = 'source_type';
  private const string SOURCE_FIELD_NAME = 'source_field_name';
  private const string SOURCE_REVISION_ID = 'source_revision_id';
  private const string SOURCE_REVISION_ID_STRING = 'source_revision_id_string';
  private const string SOURCE_LANGCODE = 'source_langcode';
  private const string SOURCE_DELTA = 'source_delta';
  private const string DEPENDENCY_TYPE = 'dependency_type';
  private const string DEPENDENCY_NAME = 'dependency_name';

  /**
   * Static caches.
   */
  private array $revisionIdColumns = [];
  private array $entityIdColumns = [];
  private array $supportsRevisions = [];

  public function __construct(
    private readonly Connection $connection,
    private readonly EntityFieldManagerInterface $entityFieldManager,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly PagerManagerInterface $pagerManager,
    private readonly PagerParametersInterface $pagerParameters,
  ) {
  }

  public function onFieldConfigDelete(FieldConfigInterface $field): self {
    $keys = [
      self::SOURCE_TYPE => $field->getTargetEntityTypeId(),
      self::SOURCE_FIELD_NAME => $field->getName(),
    ];
    return $this->removeEntityRecords($keys);
  }

  public function onEntityDelete(FieldableEntityInterface $entity): self {
    $keys = $this->buildKeys($entity, with_revision_keys: FALSE);
    return $this->removeEntityRecords($keys);
  }

  public function onEntityRevisionDelete(FieldableEntityInterface $entity): self {
    $keys = $this->buildKeys($entity, with_revision_keys: TRUE);
    return $this->removeEntityRecords($keys);
  }

  public function onEntityUpdateOrInsert(FieldableEntityInterface $entity): self {
    $field_map = $this->entityFieldManager->getFieldMapByFieldType(ComponentTreeItem::PLUGIN_ID);
    $entity_type_id = $entity->getEntityTypeId();
    if (!\array_key_exists($entity_type_id, $field_map)) {
      // There are no component tree item fields on this entity, nothing to do.
      return $this;
    }
    $matching_bundle = \array_filter($field_map[$entity_type_id], static fn (array $detail): bool => \in_array($entity->bundle(), $detail['bundles'], TRUE));
    if (\count($matching_bundle) === 0) {
      return $this;
    }
    $keys = $this->buildKeys($entity, with_revision_keys: TRUE);
    // Remove existing entries for this entity.
    $this->removeEntityRecords($keys);
    // Write new records for this entity.
    $insert = $this->connection->insert(self::TABLE_NAME)
      ->fields(\array_merge(\array_keys($keys), [
        self::SOURCE_FIELD_NAME,
        self::SOURCE_DELTA,
        self::DEPENDENCY_NAME,
        self::DEPENDENCY_TYPE,
      ]));
    $values = FALSE;
    foreach (\array_keys($matching_bundle) as $field_name) {
      foreach ($entity->get($field_name) as $delta => $field_item) {
        \assert($field_item instanceof ComponentTreeItem);
        foreach ($field_item->calculateFieldItemValueDependencies(TRUE) as $dependency_type => $dependencies) {
          foreach ($dependencies as $dependency) {
            $values = TRUE;
            $insert->values(\array_merge($keys, [
              self::SOURCE_FIELD_NAME => $field_name,
              self::SOURCE_DELTA => $delta,
              self::DEPENDENCY_NAME => $dependency,
              self::DEPENDENCY_TYPE => $dependency_type,
            ]));
          }
        }
      }
    }
    if ($values) {
      $this->executeQuery($insert);
    }
    return $this;
  }

  public function getPluginDependents(string $name): array {
    $query = $this->buildDependentQuery('plugin', $name);
    $result = $this->executeQuery($query);
    \assert($result instanceof StatementInterface);
    $results = $result->fetchAll(\PDO::FETCH_ASSOC);
    return \array_map(fn (array $record) => [
      '%used_field' => $name,
      '%entity_type' => $record[self::SOURCE_TYPE],
      '%entity_id' => $record[$this->getIdColumn($record[self::SOURCE_TYPE])],
      '%revision_id' => $record[$this->getRevisionIdColumn($record[self::SOURCE_TYPE])],
    ], $results);
  }

  /**
   * @todo Either remove the ::loadMultiple() and ::loadMultipleRevisions() or create a separate method for it in https://www.drupal.org/i/3522953
   */
  public function getConfigurationDependents(string $name, ?int $limit = NULL, int $pager_element = 0): array {
    $query = $this->buildDependentQuery('config', $name);
    if ($limit !== NULL) {
      $page = $this->pagerParameters->findPage($pager_element);
      $count_query = clone $query;
      $count_result = $this->executeQuery($count_query->countQuery());
      \assert($count_result instanceof StatementInterface);
      $total = (int) $count_result->fetchField();
      \assert(\is_int($total));
      $start = $page * $limit;
      $this->pagerManager->createPager($total, $limit, $pager_element);
      $query->range($start, $limit);
    }
    $result = $this->executeQuery($query);
    \assert($result instanceof StatementInterface);
    $results = $result->fetchAll(\PDO::FETCH_ASSOC);
    $grouped = \array_reduce($results, function (array $carry, array $record) {
      $entity_type_id = $record[self::SOURCE_TYPE];
      $carry[$entity_type_id] ??= [];
      $carry[$entity_type_id][] = $record[$this->getIdColumn($entity_type_id)];
      return $carry;
    }, []);
    $dependents = [];
    // Order by entity-type ID for a consistent order.
    ksort($grouped);
    foreach ($grouped as $entity_type_id => $entities_ids) {
      $storage = $this->entityTypeManager->getStorage($entity_type_id);
      $entities_ids = \array_unique($entities_ids);
      // Order by entity ID for a consistent order.
      sort($entities_ids);
      if ($this->supportsRevisions($entity_type_id)) {
        \assert($storage instanceof RevisionableStorageInterface);
        $dependents = \array_merge($dependents, $storage->loadMultipleRevisions($entities_ids));
        continue;
      }
      $dependents = \array_merge($dependents, $storage->loadMultiple($entities_ids));
    }
    return $dependents;
  }

  private function removeEntityRecords(array $keys): self {
    $query = $this->connection->delete(self::TABLE_NAME);
    foreach ($keys as $name => $value) {
      $query->condition($name, $value);
    }
    $this->executeQuery($query);
    return $this;
  }

  private function getRevisionIdColumn(string $entity_type_id): string {
    if (!\array_key_exists($entity_type_id, $this->revisionIdColumns)) {
      $entity_type = $this->entityTypeManager->getDefinition($entity_type_id);
      $revision_key = $entity_type->getKey('revision');
      $definitions = $this->entityFieldManager->getFieldStorageDefinitions($entity_type_id);
      $this->revisionIdColumns[$entity_type_id] = $definitions[$revision_key]->getType() === 'integer' ? self::SOURCE_REVISION_ID : self::SOURCE_REVISION_ID_STRING;
    }
    return $this->revisionIdColumns[$entity_type_id];
  }

  private function getEntityIdColumn(string $entity_type_id): string {
    if (!\array_key_exists($entity_type_id, $this->entityIdColumns)) {
      $entity_type = $this->entityTypeManager->getDefinition($entity_type_id);
      $id_key = $entity_type->getKey('id');
      $definitions = $this->entityFieldManager->getFieldStorageDefinitions($entity_type_id);
      $this->entityIdColumns[$entity_type_id] = $definitions[$id_key]->getType() === 'integer' ? self::SOURCE_ID : self::SOURCE_ID_STRING;
    }
    return $this->entityIdColumns[$entity_type_id];
  }

  private function supportsRevisions(string $entity_type_id): bool {
    if (!\array_key_exists($entity_type_id, $this->supportsRevisions)) {
      $entity_type = $this->entityTypeManager->getDefinition($entity_type_id);
      $this->supportsRevisions[$entity_type_id] = $entity_type->isRevisionable();
    }
    return $this->supportsRevisions[$entity_type_id];
  }

  private function getIdColumn(string $entity_type_id): string {
    return $this->supportsRevisions($entity_type_id) ? $this->getRevisionIdColumn($entity_type_id) : $this->getEntityIdColumn($entity_type_id);
  }

  private function executeQuery(Delete|Select|Merge|Insert|Update|SelectInterface $query): StatementInterface|int|string|null {
    try {
      $retry = clone $query;
      return $query->execute();
    }
    catch (\Exception $e) {
      if ($this->ensureTableExists()) {
        return $retry->execute();
      }
      throw $e;
    }
  }

  /**
   * Check if the table exists and create it if not.
   *
   * @return bool
   *   TRUE if the table exists, FALSE if it does not exists.
   */
  protected function ensureTableExists() {
    try {
      $database_schema = $this->connection->schema();
      $database_schema->createTable(self::TABLE_NAME, $this->schemaDefinition());
    }
    // If the table already exists, then attempting to recreate it will throw an
    // exception. In this case just catch the exception and do nothing.
    catch (DatabaseException) {
    }
    catch (\Exception) {
      return FALSE;
    }
    return TRUE;
  }

  private function schemaDefinition(): array {
    return [
      'description' => 'Tracks component tree dependencies for content entities.',
      'fields' => [
        self::SOURCE_ID => [
          'description' => "The entity with a component tree's entity ID.",
          'type' => 'int',
          'unsigned' => TRUE,
          'not null' => TRUE,
          'default' => 0,
        ],
        self::SOURCE_ID_STRING => [
          'description' => "The entity with a component tree's entity ID, when the entity uses string IDs.",
          'type' => 'varchar_ascii',
          'length' => 255,
          'not null' => TRUE,
          'default' => '',
        ],
        self::SOURCE_TYPE => [
          'description' => "The entity with a component tree's entity type.",
          'type' => 'varchar_ascii',
          'length' => 128,
          'not null' => TRUE,
          'default' => '',
        ],
        self::SOURCE_FIELD_NAME => [
          'description' => 'The field in which the component tree is stored.',
          'type' => 'varchar_ascii',
          'length' => 128,
          'not null' => TRUE,
          'default' => '',
        ],
        self::SOURCE_DELTA => [
          'description' => 'The field delta in which the component tree is stored.',
          'type' => 'int',
          'unsigned' => TRUE,
          'not null' => TRUE,
        ],
        self::SOURCE_REVISION_ID => [
          'type' => 'int',
          'unsigned' => TRUE,
          'not null' => TRUE,
          'description' => "The entity with a component tree's revision ID.",
          'default' => 0,
        ],
        self::SOURCE_REVISION_ID_STRING => [
          'type' => 'varchar',
          'length' => 128,
          'not null' => TRUE,
          'description' => "The entity with a component tree's revision ID, when the entity uses string IDs.",
          'default' => '',
        ],
        self::SOURCE_LANGCODE => [
          'type' => 'varchar_ascii',
          'length' => 32,
          'not null' => TRUE,
          'default' => '',
          'description' => "The entity with a component tree's langcode.",
        ],
        self::DEPENDENCY_TYPE => [
          'description' => "The dependency type, one of 'config', 'content', 'module', 'theme' or 'plugin'.",
          'type' => 'varchar_ascii',
          'length' => 8,
          'not null' => TRUE,
          'default' => '',
        ],
        self::DEPENDENCY_NAME => [
          'description' => 'The dependency name. The value will depend on the dependency type. For module and theme dependencies it will be the machine name. For plugin it will be the plugin ID. For config it will be the configuration object name. For content it will be a string in the form {entity_type}:{bundle}:{uuid}',
          'type' => 'varchar_ascii',
          // Modules and themes can be 50 chars long, configuration object names
          // can be 255, content dependencies can be 102 (32 for the entity type
          // plus 32 for the bundle plus 36 for the UUID, plus 2 for the
          // separating colons - {entity_type}:{bundle}:{uuid}).
          // Technically there is no limit to a plugin ID length but practically
          // 255 should suffice.
          // @see \Drupal\Core\Config\DatabaseStorage::schemaDefinition
          // @see \Drupal\Core\Entity\EntityTypeInterface::BUNDLE_MAX_LENGTH
          // @see \Drupal\Core\Entity\EntityTypeInterface::ID_MAX_LENGTH
          // @see \DRUPAL_EXTENSION_NAME_MAX_LENGTH
          // @see https://en.wikipedia.org/wiki/Universally_unique_identifier#Version_4_(random)
          'length' => 255,
          'not null' => TRUE,
          'default' => '',
        ],
      ],
      'primary key' => [
        self::SOURCE_ID,
        self::SOURCE_ID_STRING,
        self::SOURCE_TYPE,
        self::SOURCE_FIELD_NAME,
        self::SOURCE_DELTA,
        self::SOURCE_REVISION_ID,
        self::SOURCE_REVISION_ID_STRING,
        self::SOURCE_LANGCODE,
        self::DEPENDENCY_TYPE,
        self::DEPENDENCY_NAME,
      ],
      'indexes' => [
        'source_entity' => [
          self::SOURCE_TYPE,
          self::SOURCE_ID,
          self::SOURCE_ID_STRING,
          self::SOURCE_REVISION_ID,
          self::SOURCE_REVISION_ID_STRING,
          self::SOURCE_LANGCODE,
        ],
        'dependency' => [self::DEPENDENCY_TYPE, self::DEPENDENCY_NAME],
      ],
    ];
  }

  private function buildKeys(FieldableEntityInterface $entity, bool $with_revision_keys): array {
    $entity_type_id = $entity->getEntityTypeId();
    $keys = [
      self::SOURCE_TYPE => $entity_type_id,
      $this->getEntityIdColumn($entity_type_id) => $entity->id(),
    ];
    if ($with_revision_keys && $this->supportsRevisions($entity_type_id)) {
      \assert($entity instanceof RevisionableInterface);
      $keys[$this->getRevisionIdColumn($entity_type_id)] = $entity->getRevisionId();
    }
    $keys[self::SOURCE_LANGCODE] = $entity->language()->getId();
    return $keys;
  }

  protected function buildDependentQuery(string $dependency_type, string $name): SelectInterface {
    $alias = 'dependencies';
    $query = $this->connection->select(self::TABLE_NAME, $alias)
      ->condition(self::DEPENDENCY_TYPE, $dependency_type)
      ->condition(self::DEPENDENCY_NAME, $name);
    $query->fields($alias, [
      self::SOURCE_ID,
      self::SOURCE_REVISION_ID,
      self::SOURCE_TYPE,
      self::SOURCE_ID_STRING,
      self::SOURCE_REVISION_ID_STRING,
    ]);
    // We don't add an order-by clause here to avoid a file-sort because we're
    // selecting based on the dependency index.
    return $query;
  }

}
