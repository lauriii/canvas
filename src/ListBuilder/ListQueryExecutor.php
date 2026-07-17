<?php

declare(strict_types=1);

namespace Drupal\canvas\ListBuilder;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\ConditionInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\datetime\Plugin\Field\FieldType\DateTimeItemInterface;

/**
 * Executes the List element's constrained query DSL through entity queries.
 *
 * Queries always run with access checks, are restricted to the current
 * content language (plus language-neutral content), and are always ranged:
 * the executor fetches one row more than the requested window to detect
 * whether further pages exist, so no count queries are needed.
 *
 * @see \Drupal\canvas\ListBuilder\ListElementSettingsValidator
 * @see docs/adr/0020-list-element-component-source-with-constrained-query-dsl.md
 *
 * @internal
 */
final class ListQueryExecutor {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntityRepositoryInterface $entityRepository,
    private readonly LanguageManagerInterface $languageManager,
    private readonly ListElementFieldInfo $fieldInfo,
    private readonly Connection $database,
    private readonly AccountInterface $currentUser,
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Runs the query for one window of a List element.
   *
   * @param array $settings
   *   Valid canonical List element settings.
   * @param int $offset
   *   The zero-based offset of the window. 0 renders the initial page.
   *
   * @return \Drupal\canvas\ListBuilder\ListQueryResult
   *   The window's entities and whether more results exist.
   */
  public function execute(array $settings, int $offset = 0): ListQueryResult {
    $entity_type_id = $settings['source']['entity_type'];
    $bundle = $settings['source']['bundle'];
    $langcode = $this->languageManager->getCurrentLanguage(LanguageInterface::TYPE_CONTENT)->getId();

    $cacheability = (new CacheableMetadata())
      ->addCacheTags([\sprintf('%s_list:%s', $entity_type_id, $bundle)])
      ->addCacheContexts(['languages:language_content', 'user.permissions']);
    // With node grants in play, the query varies by the user's grants, not
    // just their permissions.
    if ($entity_type_id === 'node' && $this->moduleHandler->hasImplementations('node_grants')) {
      $cacheability->addCacheContexts(['user.node_grants:view']);
    }

    $window = self::getWindowSize($settings, $offset);
    if ($window < 1) {
      return new ListQueryResult([], FALSE, $cacheability, 0);
    }

    $storage = $this->entityTypeManager->getStorage($entity_type_id);
    $entity_type = $storage->getEntityType();
    $fields = $this->fieldInfo->getFilterableFields($entity_type_id, $bundle);

    $query = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition((string) $entity_type->getKey('bundle'), $bundle)
      ->condition((string) $entity_type->getKey('langcode'), [
        $langcode,
        LanguageInterface::LANGCODE_NOT_SPECIFIED,
        LanguageInterface::LANGCODE_NOT_APPLICABLE,
      ], 'IN');

    // On sites without any node grants implementation, core's node access
    // query alter bails out entirely, so accessCheck(TRUE) alone does not
    // exclude unpublished content. Enforce the published flag explicitly for
    // users who may not view unpublished content: without it, inaccessible
    // results would occupy window slots (the render path double-checks entity
    // access, so they would render as gaps). Sites with grants keep their
    // grant-based visibility of unpublished content.
    $published_key = $entity_type->getKey('published');
    $owner_key = $entity_type->getKey('owner');
    if (\is_string($published_key)
      && !($entity_type_id === 'node' && $this->moduleHandler->hasImplementations('node_grants'))
      && !$this->currentUser->hasPermission('bypass node access')
      && !$this->currentUser->hasPermission('view any unpublished content')
      && !$this->currentUser->hasPermission('administer nodes')) {
      if (\is_string($owner_key) && $this->currentUser->isAuthenticated() && $this->currentUser->hasPermission('view own unpublished content')) {
        // Published content, plus the user's own unpublished content.
        $query->condition($query->orConditionGroup()
          ->condition($published_key, 1)
          ->condition($owner_key, $this->currentUser->id()));
        $cacheability->addCacheContexts(['user']);
      }
      else {
        $query->condition($published_key, 1);
      }
    }

    // Conditions and sorts deliberately use entity query's default language
    // handling: passing the current langcode per condition would stop
    // matching language-neutral (und/zxx) values, which the language
    // condition above explicitly includes. The trade-off: a value stored
    // only in another translation can match, with the current translation
    // rendered. Revisit if real multilingual usage surfaces this.
    $conditions = \array_filter($settings['filters']['conditions'], self::isApplicableCondition(...));
    if ($conditions !== []) {
      $target = $settings['filters']['conjunction'] === 'or' ? $query->orConditionGroup() : $query->andConditionGroup();
      foreach ($conditions as $condition) {
        $this->applyCondition($target, $condition, $fields[$condition['field']]['definition']);
      }
      $query->condition($target);
    }

    foreach ($settings['sorts'] as $sort) {
      $definition = $fields[$sort['field']]['definition'];
      $query->sort(ListElementFieldInfo::getQueryColumn($definition), \strtoupper($sort['direction']));
    }
    // A deterministic tiebreaker keeps offset-based pagination stable when
    // sort values are shared between results.
    $query->sort((string) $entity_type->getKey('id'), 'DESC');

    // Fetch one row beyond the window instead of running a count query.
    $ids = $query->range($offset, $window + 1)->execute();
    \assert(\is_array($ids));
    $has_more = \count($ids) > $window;
    if ($has_more) {
      $ids = \array_slice($ids, 0, $window, TRUE);
    }
    $consumed = \count($ids);
    // With a limit, more matching content only means more pages while the
    // limit has not been reached yet.
    if ($settings['limit'] !== NULL && $offset + $window >= $settings['limit']) {
      $has_more = FALSE;
    }

    $entities = [];
    $loaded = $storage->loadMultiple($ids);
    // loadMultiple() does not guarantee the order of the passed IDs, so
    // restore the query's sort order.
    foreach ($ids as $id) {
      if (!isset($loaded[$id])) {
        continue;
      }
      $entity = $this->entityRepository->getTranslationFromContext($loaded[$id], $langcode);
      // The access-checked query already excluded inaccessible content; this
      // guards the render path if query access and entity access ever
      // disagree.
      if ($entity->access('view')) {
        $entities[$id] = $entity;
      }
    }

    return new ListQueryResult($entities, $has_more, $cacheability, $consumed);
  }

  /**
   * Computes the number of items the window at the given offset may hold.
   */
  public static function getWindowSize(array $settings, int $offset): int {
    $limit = $settings['limit'];
    // Without pagination, the initial render is the only window.
    if ($settings['pagination']['mode'] === 'none') {
      \assert(\is_int($limit));
      return $offset === 0 ? $limit : 0;
    }
    $window = $settings['pagination']['page_size'];
    if ($limit !== NULL) {
      $window = \min($window, $limit - $offset);
    }
    return \max($window, 0);
  }

  /**
   * Whether a stored condition is complete enough to apply.
   *
   * Conditions whose operator needs a value but that have none stored are
   * "inert": the editor may still be filling them in, so they are kept in
   * storage but not applied.
   */
  private static function isApplicableCondition(array $condition): bool {
    $operator = $condition['operator'];
    if (\in_array($operator, [ListElementFieldTypeFamily::OP_IS_SET, ListElementFieldTypeFamily::OP_NOT_SET], TRUE)) {
      return TRUE;
    }
    if ($operator === ListElementFieldTypeFamily::OP_BETWEEN) {
      return isset($condition['value']['min'], $condition['value']['max']);
    }
    return \array_key_exists('value', $condition);
  }

  /**
   * Translates one stored condition into entity query conditions.
   */
  private function applyCondition(ConditionInterface|QueryInterface $target, array $condition, FieldDefinitionInterface $definition): void {
    $field_name = $definition->getName();
    $column = ListElementFieldInfo::getQueryColumn($definition);
    $family = ListElementFieldTypeFamily::fromFieldType($definition->getType());
    $operator = $condition['operator'];
    $value = $condition['value'] ?? NULL;

    switch ($operator) {
      case ListElementFieldTypeFamily::OP_IS_SET:
        $target->exists($field_name);
        return;

      case ListElementFieldTypeFamily::OP_NOT_SET:
        $target->notExists($field_name);
        return;
    }

    if ($family === ListElementFieldTypeFamily::Date) {
      $this->applyDateCondition($target, $operator, $value, $definition, $column);
      return;
    }

    if ($family === ListElementFieldTypeFamily::Options && \is_bool($value)) {
      $value = (int) $value;
    }

    match ($operator) {
      ListElementFieldTypeFamily::OP_EQUALS => $target->condition($column, $value),
      ListElementFieldTypeFamily::OP_NOT_EQUALS => $target->condition($column, $value, '<>'),
      ListElementFieldTypeFamily::OP_CONTAINS => $target->condition($column, $value, 'CONTAINS'),
      ListElementFieldTypeFamily::OP_NOT_CONTAINS => $target->condition($column, '%' . $this->database->escapeLike((string) $value) . '%', 'NOT LIKE'),
      ListElementFieldTypeFamily::OP_STARTS_WITH => $target->condition($column, $value, 'STARTS_WITH'),
      ListElementFieldTypeFamily::OP_ENDS_WITH => $target->condition($column, $value, 'ENDS_WITH'),
      ListElementFieldTypeFamily::OP_GREATER_THAN => $target->condition($column, $value, '>'),
      ListElementFieldTypeFamily::OP_GREATER_THAN_OR_EQUAL => $target->condition($column, $value, '>='),
      ListElementFieldTypeFamily::OP_LESS_THAN => $target->condition($column, $value, '<'),
      ListElementFieldTypeFamily::OP_LESS_THAN_OR_EQUAL => $target->condition($column, $value, '<='),
      default => throw new \LogicException('Unsupported operator: ' . $operator),
    };
  }

  /**
   * Applies a date condition, mapping day values onto the storage format.
   *
   * Stored date conditions are calendar days (Y-m-d). Timestamp fields and
   * datetime fields with time granularity compare against the day's boundary
   * moments in the site's time zone; date-only datetime fields compare the
   * stored value directly.
   */
  private function applyDateCondition(ConditionInterface|QueryInterface $target, string $operator, string|array $value, FieldDefinitionInterface $definition, string $column): void {
    if ($operator === ListElementFieldTypeFamily::OP_BETWEEN) {
      \assert(\is_array($value) && isset($value['min'], $value['max']));
      [$min_day, $max_day] = [$value['min'], $value['max']];
    }
    else {
      \assert(\is_string($value));
      $min_day = $max_day = $value;
    }

    $storage_type = $definition->getFieldStorageDefinition()->getType();
    $is_date_only = $storage_type === 'datetime'
      // 'date' is DateTimeItem::DATETIME_TYPE_DATE; the class constant is not
      // referenced so the datetime module stays optional.
      && $definition->getFieldStorageDefinition()->getSetting('datetime_type') === 'date';

    if ($is_date_only) {
      match ($operator) {
        ListElementFieldTypeFamily::OP_EQUALS => $target->condition($column, $min_day),
        ListElementFieldTypeFamily::OP_NOT_EQUALS => $target->condition($column, $min_day, '<>'),
        ListElementFieldTypeFamily::OP_BETWEEN => $target->condition($column, [$min_day, $max_day], 'BETWEEN'),
        default => throw new \LogicException('Unsupported date operator: ' . $operator),
      };
      return;
    }

    $range = [
      $this->toStorageValue($min_day . ' 00:00:00', $storage_type),
      $this->toStorageValue($max_day . ' 23:59:59', $storage_type),
    ];
    match ($operator) {
      ListElementFieldTypeFamily::OP_EQUALS, ListElementFieldTypeFamily::OP_BETWEEN => $target->condition($column, $range, 'BETWEEN'),
      ListElementFieldTypeFamily::OP_NOT_EQUALS => $target->condition($column, $range, 'NOT BETWEEN'),
      default => throw new \LogicException('Unsupported date operator: ' . $operator),
    };
  }

  /**
   * Converts a site-time-zone moment to the field's stored representation.
   */
  private function toStorageValue(string $site_time, string $storage_type): int|string {
    // Editors pick calendar days; interpret them in the site's configured
    // time zone, deterministically across HTTP, CLI, and queue contexts.
    $site_timezone = $this->configFactory->get('system.date')->get('timezone.default') ?: 'UTC';
    $date = new DrupalDateTime($site_time, new \DateTimeZone($site_timezone));
    return $storage_type === 'datetime'
      ? $date->setTimezone(new \DateTimeZone(DateTimeItemInterface::STORAGE_TIMEZONE))->format(DateTimeItemInterface::DATETIME_STORAGE_FORMAT)
      : $date->getTimestamp();
  }

}
