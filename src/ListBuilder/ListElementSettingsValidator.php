<?php

declare(strict_types=1);

namespace Drupal\canvas\ListBuilder;

use Drupal\Core\Entity\EntityDisplayRepositoryInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\ConstraintViolationListInterface;

/**
 * Validates the List element's settings blob.
 *
 * The settings structure is the constrained query DSL described in ADR 0020:
 * source, display, limit, pagination, filters, sorts, and layout. Structural
 * validation and the semantic rules (fields exist on the source bundle,
 * operators match the field's type family, sorts reference sortable fields,
 * "no limit" implies infinite scroll) all live here so that every write path
 * of the `list` component source produces identical violations.
 *
 * @see \Drupal\canvas\Plugin\Canvas\ComponentSource\ListComponent
 * @see docs/adr/0020-list-element-component-source-with-constrained-query-dsl.md
 *
 * @internal
 */
final class ListElementSettingsValidator {

  use StringTranslationTrait;

  public const array DISPLAY_MODES = ['view_mode', 'title_linked', 'item_template'];
  public const array PAGINATION_MODES = ['none', 'load_more', 'infinite_scroll'];
  public const array LAYOUT_MODES = ['stack', 'row', 'grid'];
  public const array GAPS = ['none', 'small', 'medium', 'large'];
  public const array DISTRIBUTIONS = ['start', 'center', 'end', 'space_between'];
  public const array ALIGNMENTS = ['start', 'center', 'end', 'stretch'];
  public const array CONJUNCTIONS = ['and', 'or'];
  public const array SORT_DIRECTIONS = ['asc', 'desc'];
  public const int MAX_PAGE_SIZE = 100;
  public const int MAX_PER_ROW = 12;

  public function __construct(
    private readonly EntityTypeBundleInfoInterface $bundleInfo,
    private readonly EntityDisplayRepositoryInterface $displayRepository,
    private readonly ListElementFieldInfo $fieldInfo,
  ) {}

  /**
   * Validates a canonical List element settings array.
   *
   * @param array $settings
   *   The settings blob, in its canonical (stored) form.
   *
   * @return \Symfony\Component\Validator\ConstraintViolationListInterface
   *   Violations with property paths relative to the settings blob root.
   */
  public function validate(array $settings): ConstraintViolationListInterface {
    $violations = new ConstraintViolationList();

    foreach (['source', 'display', 'pagination', 'filters', 'sorts', 'layout'] as $key) {
      if (!\array_key_exists($key, $settings)) {
        self::addViolation($violations, $settings, $key, NULL, $this->t('The %key setting is required.', ['%key' => $key]));
      }
    }
    if (!\array_key_exists('limit', $settings)) {
      self::addViolation($violations, $settings, 'limit', NULL, $this->t('The %key setting is required.', ['%key' => 'limit']));
    }
    $unknown_keys = \array_diff(\array_keys($settings), [
      'source',
      'display',
      'limit',
      'pagination',
      'filters',
      'sorts',
      'layout',
    ]);
    foreach ($unknown_keys as $key) {
      self::addViolation($violations, $settings, (string) $key, $settings[$key], $this->t('Unknown setting %key.', ['%key' => $key]));
    }
    if (\count($violations) > 0) {
      return $violations;
    }

    $bundle_is_valid = $this->validateSource($violations, $settings);
    // Field- and view-mode-dependent rules are only meaningful for a valid
    // source; structural rules below always run.
    $fields = $bundle_is_valid
      ? $this->fieldInfo->getFilterableFields($settings['source']['entity_type'], $settings['source']['bundle'])
      : [];

    $this->validateDisplay($violations, $settings, $bundle_is_valid);
    $this->validateLimitAndPagination($violations, $settings);
    $this->validateFilters($violations, $settings, $fields, $bundle_is_valid);
    $this->validateSorts($violations, $settings, $bundle_is_valid);
    $this->validateLayout($violations, $settings);

    return $violations;
  }

  private function validateSource(ConstraintViolationList $violations, array $settings): bool {
    $source = $settings['source'];
    if (!\is_array($source) || \array_diff(\array_keys($source), ['entity_type', 'bundle']) !== [] || !isset($source['entity_type'], $source['bundle'])) {
      self::addViolation($violations, $settings, 'source', $source, $this->t('The source must specify an entity type and a bundle.'));
      return FALSE;
    }
    // Only nodes are supported initially; the stored entity type keeps other
    // sources possible without a storage change.
    if ($source['entity_type'] !== 'node') {
      self::addViolation($violations, $settings, 'source.entity_type', $source['entity_type'], $this->t('The %value entity type is not supported as a List source.', ['%value' => $source['entity_type']]));
      return FALSE;
    }
    if (!\is_string($source['bundle']) || !\array_key_exists($source['bundle'], $this->bundleInfo->getBundleInfo('node'))) {
      self::addViolation($violations, $settings, 'source.bundle', $source['bundle'], $this->t('The %value content type does not exist.', ['%value' => \is_scalar($source['bundle']) ? (string) $source['bundle'] : \gettype($source['bundle'])]));
      return FALSE;
    }
    return TRUE;
  }

  private function validateDisplay(ConstraintViolationList $violations, array $settings, bool $bundle_is_valid): void {
    $display = $settings['display'];
    if (!\is_array($display) || !isset($display['mode'])) {
      self::addViolation($violations, $settings, 'display', $display, $this->t('The display must specify a mode.'));
      return;
    }
    if (!\in_array($display['mode'], self::DISPLAY_MODES, TRUE)) {
      self::addViolation($violations, $settings, 'display.mode', $display['mode'], $this->t('The %value display mode is not one of the allowed modes.', ['%value' => \is_scalar($display['mode']) ? (string) $display['mode'] : \gettype($display['mode'])]));
      return;
    }
    if ($display['mode'] === 'view_mode') {
      if (!isset($display['view_mode']) || !\is_string($display['view_mode']) || $display['view_mode'] === '') {
        self::addViolation($violations, $settings, 'display.view_mode', NULL, $this->t('A view mode is required when items are displayed using a view mode.'));
        return;
      }
      if ($bundle_is_valid) {
        $options = $this->displayRepository->getViewModeOptionsByBundle('node', $settings['source']['bundle']);
        if (!\array_key_exists($display['view_mode'], $options)) {
          self::addViolation($violations, $settings, 'display.view_mode', $display['view_mode'], $this->t('The %value view mode is not available for the selected content type.', ['%value' => $display['view_mode']]));
        }
      }
    }
    elseif (\array_key_exists('view_mode', $display)) {
      self::addViolation($violations, $settings, 'display.view_mode', $display['view_mode'], $this->t('A view mode may only be specified when items are displayed using a view mode.'));
    }
  }

  private function validateLimitAndPagination(ConstraintViolationList $violations, array $settings): void {
    $limit = $settings['limit'];
    if ($limit !== NULL && (!\is_int($limit) || $limit < 1)) {
      self::addViolation($violations, $settings, 'limit', $limit, $this->t('The limit must be a positive number, or empty for no limit.'));
    }

    $pagination = $settings['pagination'];
    if (!\is_array($pagination) || !isset($pagination['mode'], $pagination['page_size'])) {
      self::addViolation($violations, $settings, 'pagination', $pagination, $this->t('The pagination settings must specify a mode and a page size.'));
      return;
    }
    if (!\in_array($pagination['mode'], self::PAGINATION_MODES, TRUE)) {
      self::addViolation($violations, $settings, 'pagination.mode', $pagination['mode'], $this->t('The %value pagination mode is not one of the allowed modes.', ['%value' => \is_scalar($pagination['mode']) ? (string) $pagination['mode'] : \gettype($pagination['mode'])]));
      return;
    }
    if (!\is_int($pagination['page_size']) || $pagination['page_size'] < 1 || $pagination['page_size'] > self::MAX_PAGE_SIZE) {
      self::addViolation($violations, $settings, 'pagination.page_size', $pagination['page_size'], $this->t('The page size must be between 1 and @max.', ['@max' => self::MAX_PAGE_SIZE]));
    }
    // Queries are always ranged: a list without a limit must load further
    // pages as the visitor scrolls, so that "show all items" never means
    // "load all items at once".
    if ($limit === NULL && $pagination['mode'] !== 'infinite_scroll') {
      self::addViolation($violations, $settings, 'pagination.mode', $pagination['mode'], $this->t('A list without a limit must use infinite scroll pagination.'));
    }
  }

  private function validateFilters(ConstraintViolationList $violations, array $settings, array $fields, bool $bundle_is_valid): void {
    $filters = $settings['filters'];
    if (!\is_array($filters) || !isset($filters['conjunction']) || !\array_key_exists('conditions', $filters) || !\is_array($filters['conditions']) || !\array_is_list($filters['conditions'])) {
      self::addViolation($violations, $settings, 'filters', $filters, $this->t('The filters must specify a conjunction and a list of conditions.'));
      return;
    }
    if (!\in_array($filters['conjunction'], self::CONJUNCTIONS, TRUE)) {
      self::addViolation($violations, $settings, 'filters.conjunction', $filters['conjunction'], $this->t('The filter conjunction must be either "and" or "or".'));
    }
    foreach ($filters['conditions'] as $delta => $condition) {
      $this->validateCondition($violations, $settings, $fields, $bundle_is_valid, $delta, $condition);
    }
  }

  private function validateCondition(ConstraintViolationList $violations, array $settings, array $fields, bool $bundle_is_valid, int $delta, mixed $condition): void {
    $path = \sprintf('filters.conditions.%d', $delta);
    $allowed_condition_keys = ['field', 'operator', 'value'];
    if (!\is_array($condition) || !isset($condition['field'], $condition['operator']) || \array_diff(\array_keys($condition), $allowed_condition_keys) !== []) {
      self::addViolation($violations, $settings, $path, $condition, $this->t('Each condition must specify a field and an operator.'));
      return;
    }
    if (!$bundle_is_valid) {
      return;
    }
    if (!\is_string($condition['field']) || !\array_key_exists($condition['field'], $fields)) {
      self::addViolation($violations, $settings, $path . '.field', $condition['field'], $this->t('The %value field does not exist on the selected content type.', ['%value' => \is_scalar($condition['field']) ? (string) $condition['field'] : \gettype($condition['field'])]));
      return;
    }
    $field = $fields[$condition['field']];
    $family = $field['family'];
    \assert($family instanceof ListElementFieldTypeFamily);
    if (!\in_array($condition['operator'], $family->allowedOperators($field['has_target']), TRUE)) {
      self::addViolation($violations, $settings, $path . '.operator', $condition['operator'], $this->t('The %operator operator is not allowed for the %field field.', [
        '%operator' => \is_scalar($condition['operator']) ? (string) $condition['operator'] : \gettype($condition['operator']),
        '%field' => $field['label'],
      ]));
      return;
    }
    $this->validateConditionValue($violations, $settings, $path, $family, $condition);
  }

  private function validateConditionValue(ConstraintViolationList $violations, array $settings, string $path, ListElementFieldTypeFamily $family, array $condition): void {
    $operator = $condition['operator'];
    $has_value = \array_key_exists('value', $condition);

    if (\in_array($operator, [ListElementFieldTypeFamily::OP_IS_SET, ListElementFieldTypeFamily::OP_NOT_SET], TRUE)) {
      if ($has_value) {
        self::addViolation($violations, $settings, $path . '.value', $condition['value'], $this->t('The %operator operator does not use a value.', ['%operator' => $operator]));
      }
      return;
    }
    // A condition whose operator needs a value but that has none stored is
    // "inert": it is kept (the editor may still be filling it in) and the
    // query executor skips it.
    // @see \Drupal\canvas\ListBuilder\ListQueryExecutor::isApplicableCondition()
    if (!$has_value) {
      return;
    }
    $value = $condition['value'];

    if ($operator === ListElementFieldTypeFamily::OP_BETWEEN) {
      $valid = \is_array($value) && \array_diff(\array_keys($value), ['min', 'max']) === []
        && (!\array_key_exists('min', $value) || self::isValidDate($value['min']))
        && (!\array_key_exists('max', $value) || self::isValidDate($value['max']))
        && (!isset($value['min'], $value['max']) || $value['min'] <= $value['max']);
      if (!$valid) {
        self::addViolation($violations, $settings, $path . '.value', $value, $this->t('A between condition requires a minimum and a maximum date, in that order.'));
      }
      return;
    }

    $valid = match ($family) {
      ListElementFieldTypeFamily::Text => \is_string($value) && $value !== '',
      ListElementFieldTypeFamily::Options => \is_scalar($value),
      ListElementFieldTypeFamily::Reference => \is_int($value) && $value > 0,
      ListElementFieldTypeFamily::Date => self::isValidDate($value),
      ListElementFieldTypeFamily::Number => \is_int($value) || \is_float($value) || (\is_string($value) && \is_numeric($value)),
      ListElementFieldTypeFamily::Unknown => FALSE,
    };
    if (!$valid) {
      self::addViolation($violations, $settings, $path . '.value', $value, $this->t('The condition value is not valid for this field and operator.'));
    }
  }

  private function validateSorts(ConstraintViolationList $violations, array $settings, bool $bundle_is_valid): void {
    $sorts = $settings['sorts'];
    if (!\is_array($sorts) || !\array_is_list($sorts)) {
      self::addViolation($violations, $settings, 'sorts', $sorts, $this->t('The sorts must be a list.'));
      return;
    }
    $sortable = $bundle_is_valid
      ? $this->fieldInfo->getSortableFields($settings['source']['entity_type'], $settings['source']['bundle'])
      : [];
    foreach ($sorts as $delta => $sort) {
      $path = \sprintf('sorts.%d', $delta);
      $allowed_sort_keys = ['field', 'direction'];
      if (!\is_array($sort) || !isset($sort['field'], $sort['direction']) || \array_diff(\array_keys($sort), $allowed_sort_keys) !== []) {
        self::addViolation($violations, $settings, $path, $sort, $this->t('Each sort must specify a field and a direction.'));
        continue;
      }
      if (!\in_array($sort['direction'], self::SORT_DIRECTIONS, TRUE)) {
        self::addViolation($violations, $settings, $path . '.direction', $sort['direction'], $this->t('The sort direction must be "asc" or "desc".'));
      }
      if ($bundle_is_valid && (!\is_string($sort['field']) || !\array_key_exists($sort['field'], $sortable))) {
        self::addViolation($violations, $settings, $path . '.field', $sort['field'], $this->t('The %value field cannot be used for sorting.', ['%value' => \is_scalar($sort['field']) ? (string) $sort['field'] : \gettype($sort['field'])]));
      }
    }
  }

  private function validateLayout(ConstraintViolationList $violations, array $settings): void {
    $layout = $settings['layout'];
    if (!\is_array($layout) || !isset($layout['mode'], $layout['gap'])) {
      self::addViolation($violations, $settings, 'layout', $layout, $this->t('The layout must specify a mode and a gap.'));
      return;
    }
    if (!\in_array($layout['mode'], self::LAYOUT_MODES, TRUE)) {
      self::addViolation($violations, $settings, 'layout.mode', $layout['mode'], $this->t('The %value layout is not one of the allowed layouts.', ['%value' => \is_scalar($layout['mode']) ? (string) $layout['mode'] : \gettype($layout['mode'])]));
      return;
    }
    if (!\in_array($layout['gap'], self::GAPS, TRUE)) {
      self::addViolation($violations, $settings, 'layout.gap', $layout['gap'], $this->t('The %value gap is not one of the allowed gaps.', ['%value' => \is_scalar($layout['gap']) ? (string) $layout['gap'] : \gettype($layout['gap'])]));
    }

    $allowed_keys = match ($layout['mode']) {
      'stack' => ['mode', 'gap', 'distribute', 'align'],
      'row' => ['mode', 'gap', 'items_per_row'],
      'grid' => ['mode', 'gap', 'max_per_row'],
    };
    foreach (\array_diff(\array_keys($layout), $allowed_keys) as $key) {
      self::addViolation($violations, $settings, 'layout.' . $key, $layout[$key], $this->t('The %key layout setting is not allowed for the %mode layout.', [
        '%key' => $key,
        '%mode' => $layout['mode'],
      ]));
    }

    if ($layout['mode'] === 'stack') {
      if (\array_key_exists('distribute', $layout) && !\in_array($layout['distribute'], self::DISTRIBUTIONS, TRUE)) {
        self::addViolation($violations, $settings, 'layout.distribute', $layout['distribute'], $this->t('The %value distribution is not one of the allowed distributions.', ['%value' => \is_scalar($layout['distribute']) ? (string) $layout['distribute'] : \gettype($layout['distribute'])]));
      }
      if (\array_key_exists('align', $layout) && !\in_array($layout['align'], self::ALIGNMENTS, TRUE)) {
        self::addViolation($violations, $settings, 'layout.align', $layout['align'], $this->t('The %value alignment is not one of the allowed alignments.', ['%value' => \is_scalar($layout['align']) ? (string) $layout['align'] : \gettype($layout['align'])]));
      }
    }
    foreach (['items_per_row', 'max_per_row'] as $key) {
      if (\array_key_exists($key, $layout) && \in_array($key, $allowed_keys, TRUE)
        && (!\is_int($layout[$key]) || $layout[$key] < 1 || $layout[$key] > self::MAX_PER_ROW)) {
        self::addViolation($violations, $settings, 'layout.' . $key, $layout[$key], $this->t('The @key must be between 1 and @max.', [
          '@key' => \str_replace('_', ' ', $key),
          '@max' => self::MAX_PER_ROW,
        ]));
      }
    }
  }

  private static function isValidDate(mixed $value): bool {
    if (!\is_string($value) || \preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
      return FALSE;
    }
    // Round-trip through \DateTime: overflowed calendar dates (2024-02-31)
    // parse "successfully" but format back to a different day.
    $date = \DateTime::createFromFormat('Y-m-d', $value);
    return $date !== FALSE && $date->format('Y-m-d') === $value;
  }

  private static function addViolation(ConstraintViolationList $violations, array $root, string $path, mixed $invalid_value, \Stringable|string $message): void {
    $violations->add(new ConstraintViolation((string) $message, NULL, [], $root, $path, $invalid_value));
  }

}
