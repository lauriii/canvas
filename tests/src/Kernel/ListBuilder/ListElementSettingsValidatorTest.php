<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\ListBuilder;

use Drupal\canvas\ListBuilder\ListElementFieldTypeFamily;
use Drupal\canvas\ListBuilder\ListElementSettingsValidator;
use Drupal\Core\Entity\Entity\EntityViewDisplay;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\canvas\Traits\ConstraintViolationsTestTrait;
use Drupal\Tests\node\Traits\ContentTypeCreationTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests validation of the List element's settings blob.
 */
#[CoversClass(ListElementSettingsValidator::class)]
#[CoversClass(ListElementFieldTypeFamily::class)]
#[Group('canvas')]
#[RunTestsInSeparateProcesses]
final class ListElementSettingsValidatorTest extends CanvasKernelTestBase {

  use ConstraintViolationsTestTrait;
  use ContentTypeCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'node',
    'field',
    'taxonomy',
  ];

  /**
   * The validator under test.
   */
  private ListElementSettingsValidator $validator;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('taxonomy_term');
    $this->installConfig(['node']);
    $this->createContentType(['type' => 'article'], FALSE);

    // One field per type family, in addition to the `title` (Text), `status`
    // (Options), and `created` (Date) base fields.
    $field_storages = [
      'field_badge' => ['type' => 'list_string', 'settings' => ['allowed_values' => ['blue' => 'Blue', 'green' => 'Green']]],
      'field_tags' => ['type' => 'entity_reference', 'settings' => ['target_type' => 'taxonomy_term']],
      'field_event_date' => ['type' => 'datetime', 'settings' => ['datetime_type' => 'date']],
      'field_count' => ['type' => 'integer'],
    ];
    foreach ($field_storages as $field_name => $storage) {
      FieldStorageConfig::create($storage + [
        'field_name' => $field_name,
        'entity_type' => 'node',
      ])->save();
      FieldConfig::create([
        'field_name' => $field_name,
        'entity_type' => 'node',
        'bundle' => 'article',
      ])->save();
    }

    // Enable the `teaser` view mode for articles, so that it is a valid
    // display view mode choice.
    EntityViewDisplay::create([
      'targetEntityType' => 'node',
      'bundle' => 'article',
      'mode' => 'teaser',
      'status' => TRUE,
    ])->save();

    $validator = $this->container->get(ListElementSettingsValidator::class);
    \assert($validator instanceof ListElementSettingsValidator);
    $this->validator = $validator;
  }

  /**
   * Returns the canonical valid settings blob the test cases start from.
   */
  private static function validSettings(): array {
    return [
      'source' => ['entity_type' => 'node', 'bundle' => 'article'],
      'display' => ['mode' => 'title_linked'],
      'limit' => 3,
      'pagination' => ['mode' => 'none', 'page_size' => 10],
      'filters' => [
        'conjunction' => 'and',
        'conditions' => [
          ['field' => 'title', 'operator' => 'contains', 'value' => 'x'],
        ],
      ],
      'sorts' => [['field' => 'created', 'direction' => 'desc']],
      'layout' => ['mode' => 'stack', 'gap' => 'medium'],
    ];
  }

  public function testValidSettings(): void {
    $settings = self::validSettings();
    // One valued condition per type family.
    $settings['filters']['conditions'] = [
      ['field' => 'title', 'operator' => 'contains', 'value' => 'Alpha'],
      ['field' => 'status', 'operator' => 'equals', 'value' => TRUE],
      ['field' => 'field_badge', 'operator' => 'not_equals', 'value' => 'blue'],
      ['field' => 'field_tags', 'operator' => 'equals', 'value' => 3],
      ['field' => 'created', 'operator' => 'between', 'value' => ['min' => '2024-01-01', 'max' => '2024-12-31']],
      ['field' => 'field_event_date', 'operator' => 'equals', 'value' => '2024-06-01'],
      ['field' => 'field_count', 'operator' => 'gte', 'value' => 5],
    ];
    $settings['sorts'] = [
      ['field' => 'field_count', 'direction' => 'asc'],
      ['field' => 'created', 'direction' => 'desc'],
    ];
    self::assertSame([], self::violationsToArray($this->validator->validate($settings)));
  }

  public function testValidSettingsVariants(): void {
    $variants = [
      'view mode display' => ['display' => ['mode' => 'view_mode', 'view_mode' => 'teaser']],
      'item template display' => ['display' => ['mode' => 'item_template']],
      'no limit with infinite scroll' => ['limit' => NULL, 'pagination' => ['mode' => 'infinite_scroll', 'page_size' => 10]],
      'load more pagination with the maximum page size' => ['pagination' => ['mode' => 'load_more', 'page_size' => 100]],
      'or conjunction without conditions' => ['filters' => ['conjunction' => 'or', 'conditions' => []]],
      'no sorts' => ['sorts' => []],
      'row layout' => ['layout' => ['mode' => 'row', 'gap' => 'small', 'items_per_row' => 4]],
      'grid layout' => ['layout' => ['mode' => 'grid', 'gap' => 'none', 'max_per_row' => 12]],
      'stack layout with distribution and alignment' => ['layout' => ['mode' => 'stack', 'gap' => 'large', 'distribute' => 'space_between', 'align' => 'center']],
    ];
    foreach ($variants as $label => $overrides) {
      $settings = \array_merge(self::validSettings(), $overrides);
      self::assertSame([], self::violationsToArray($this->validator->validate($settings)), $label);
    }
  }

  /**
   * Asserts every allowed operator of every type family validates cleanly.
   */
  public function testAllowedOperatorMatrix(): void {
    $conditions = [
      // Text family: the `title` base field.
      'text is_set' => ['field' => 'title', 'operator' => 'is_set'],
      'text not_set' => ['field' => 'title', 'operator' => 'not_set'],
      'text contains' => ['field' => 'title', 'operator' => 'contains', 'value' => 'Alpha'],
      'text not_contains' => ['field' => 'title', 'operator' => 'not_contains', 'value' => 'Beta'],
      'text starts_with' => ['field' => 'title', 'operator' => 'starts_with', 'value' => 'A'],
      'text ends_with' => ['field' => 'title', 'operator' => 'ends_with', 'value' => 'a'],
      'text equals' => ['field' => 'title', 'operator' => 'equals', 'value' => 'Alpha'],
      'text not_equals' => ['field' => 'title', 'operator' => 'not_equals', 'value' => 'Beta'],
      // A condition whose operator needs a value but has none is inert, and
      // therefore valid.
      'text contains without a value is inert' => ['field' => 'title', 'operator' => 'contains'],
      // Options family: the `status` boolean base field.
      'boolean is_set' => ['field' => 'status', 'operator' => 'is_set'],
      'boolean not_set' => ['field' => 'status', 'operator' => 'not_set'],
      'boolean equals' => ['field' => 'status', 'operator' => 'equals', 'value' => TRUE],
      'boolean not_equals' => ['field' => 'status', 'operator' => 'not_equals', 'value' => FALSE],
      // Options family: a list_string field.
      'selection is_set' => ['field' => 'field_badge', 'operator' => 'is_set'],
      'selection not_set' => ['field' => 'field_badge', 'operator' => 'not_set'],
      'selection equals' => ['field' => 'field_badge', 'operator' => 'equals', 'value' => 'blue'],
      'selection not_equals' => ['field' => 'field_badge', 'operator' => 'not_equals', 'value' => 'green'],
      // Reference family: a taxonomy term reference field.
      'reference is_set' => ['field' => 'field_tags', 'operator' => 'is_set'],
      'reference not_set' => ['field' => 'field_tags', 'operator' => 'not_set'],
      'reference equals' => ['field' => 'field_tags', 'operator' => 'equals', 'value' => 3],
      'reference not_equals' => ['field' => 'field_tags', 'operator' => 'not_equals', 'value' => 5],
      'reference equals without a value is inert' => ['field' => 'field_tags', 'operator' => 'equals'],
      // Date family: the `created` timestamp base field.
      'date is_set' => ['field' => 'created', 'operator' => 'is_set'],
      'date not_set' => ['field' => 'created', 'operator' => 'not_set'],
      'date equals' => ['field' => 'created', 'operator' => 'equals', 'value' => '2024-01-15'],
      'date not_equals' => ['field' => 'created', 'operator' => 'not_equals', 'value' => '2024-02-01'],
      'date between' => ['field' => 'created', 'operator' => 'between', 'value' => ['min' => '2024-01-01', 'max' => '2024-12-31']],
      // Date family: a datetime field.
      'datetime equals' => ['field' => 'field_event_date', 'operator' => 'equals', 'value' => '2024-06-01'],
      'datetime between' => ['field' => 'field_event_date', 'operator' => 'between', 'value' => ['min' => '2024-06-01', 'max' => '2024-06-30']],
      // Number family: an integer field.
      'number is_set' => ['field' => 'field_count', 'operator' => 'is_set'],
      'number not_set' => ['field' => 'field_count', 'operator' => 'not_set'],
      'number equals' => ['field' => 'field_count', 'operator' => 'equals', 'value' => 5],
      'number not_equals' => ['field' => 'field_count', 'operator' => 'not_equals', 'value' => 3],
      'number gt' => ['field' => 'field_count', 'operator' => 'gt', 'value' => 1],
      'number gte' => ['field' => 'field_count', 'operator' => 'gte', 'value' => 2],
      'number lt' => ['field' => 'field_count', 'operator' => 'lt', 'value' => 10],
      'number lte' => ['field' => 'field_count', 'operator' => 'lte', 'value' => 9],
      'number equals a numeric string' => ['field' => 'field_count', 'operator' => 'equals', 'value' => '5'],
    ];
    foreach ($conditions as $label => $condition) {
      $settings = self::validSettings();
      $settings['filters']['conditions'] = [$condition];
      self::assertSame([], self::violationsToArray($this->validator->validate($settings)), $label);
    }
  }

  /**
   * Asserts invalid settings are rejected with the documented property path.
   */
  public function testInvalidSettings(): void {
    $cases = [];

    $settings = self::validSettings();
    $settings['filters']['conditions'] = [['field' => 'nonexistent', 'operator' => 'is_set']];
    $cases['unknown filter field'] = [$settings, ['filters.conditions.0.field']];

    $settings = self::validSettings();
    $settings['filters']['conditions'] = [['field' => 'title', 'operator' => 'gt', 'value' => 5]];
    $cases['operator not allowed for the field type family'] = [$settings, ['filters.conditions.0.operator']];

    $settings = self::validSettings();
    $settings['filters']['conditions'] = [['field' => 'title', 'operator' => 'is_set', 'value' => 'x']];
    $cases['value on a presence operator'] = [$settings, ['filters.conditions.0.value']];

    $settings = self::validSettings();
    $settings['filters']['conditions'] = [['field' => 'field_tags', 'operator' => 'equals', 'value' => 0]];
    $cases['non-positive reference value'] = [$settings, ['filters.conditions.0.value']];

    $settings = self::validSettings();
    $settings['filters']['conditions'] = [['field' => 'field_tags', 'operator' => 'equals', 'value' => '3']];
    $cases['string reference value'] = [$settings, ['filters.conditions.0.value']];

    $settings = self::validSettings();
    $settings['filters']['conditions'] = [['field' => 'created', 'operator' => 'between', 'value' => ['min' => '2024-12-31', 'max' => '2024-01-01']]];
    $cases['between with minimum after maximum'] = [$settings, ['filters.conditions.0.value']];

    $settings = self::validSettings();
    $settings['filters']['conditions'] = [['field' => 'created', 'operator' => 'equals', 'value' => 'not-a-date']];
    $cases['malformed date value'] = [$settings, ['filters.conditions.0.value']];

    $settings = self::validSettings();
    $settings['filters']['conditions'] = [['field' => 'field_count', 'operator' => 'equals', 'value' => 'abc']];
    $cases['non-numeric number value'] = [$settings, ['filters.conditions.0.value']];

    $settings = self::validSettings();
    $settings['filters']['conjunction'] = 'xor';
    $cases['unknown conjunction'] = [$settings, ['filters.conjunction']];

    $settings = self::validSettings();
    $settings['sorts'] = [['field' => 'field_tags', 'direction' => 'asc']];
    $cases['sort on an entity reference field'] = [$settings, ['sorts.0.field']];

    $settings = self::validSettings();
    $settings['sorts'] = [['field' => 'created', 'direction' => 'descending']];
    $cases['unknown sort direction'] = [$settings, ['sorts.0.direction']];

    $settings = self::validSettings();
    $settings['limit'] = 0;
    $cases['limit below one'] = [$settings, ['limit']];

    $settings = self::validSettings();
    $settings['limit'] = NULL;
    $settings['pagination'] = ['mode' => 'load_more', 'page_size' => 10];
    $cases['no limit without infinite scroll'] = [$settings, ['pagination.mode']];

    $settings = self::validSettings();
    $settings['source']['bundle'] = 'nonexistent';
    $cases['unknown bundle'] = [$settings, ['source.bundle']];

    $settings = self::validSettings();
    $settings['source']['entity_type'] = 'taxonomy_term';
    $cases['unsupported entity type'] = [$settings, ['source.entity_type']];

    $settings = self::validSettings();
    $settings['pagination']['page_size'] = 0;
    $cases['page size below one'] = [$settings, ['pagination.page_size']];

    $settings = self::validSettings();
    $settings['pagination']['page_size'] = 101;
    $cases['page size above the maximum'] = [$settings, ['pagination.page_size']];

    $settings = self::validSettings();
    $settings['layout']['items_per_row'] = 4;
    $cases['layout key not allowed for the stack layout'] = [$settings, ['layout.items_per_row']];

    $settings = self::validSettings();
    $settings['layout'] = ['mode' => 'row', 'gap' => 'small', 'items_per_row' => 13];
    $cases['items per row above the maximum'] = [$settings, ['layout.items_per_row']];

    $settings = self::validSettings();
    $settings['layout'] = ['mode' => 'grid', 'gap' => 'huge', 'max_per_row' => 3];
    $cases['unknown gap'] = [$settings, ['layout.gap']];

    $settings = self::validSettings();
    $settings['display'] = ['mode' => 'card'];
    $cases['unknown display mode'] = [$settings, ['display.mode']];

    $settings = self::validSettings();
    $settings['display'] = ['mode' => 'view_mode'];
    $cases['view mode display without a view mode'] = [$settings, ['display.view_mode']];

    $settings = self::validSettings();
    $settings['display'] = ['mode' => 'view_mode', 'view_mode' => 'nonexistent'];
    $cases['unknown view mode'] = [$settings, ['display.view_mode']];

    $settings = self::validSettings();
    $settings['display'] = ['mode' => 'title_linked', 'view_mode' => 'teaser'];
    $cases['view mode on a non-view-mode display'] = [$settings, ['display.view_mode']];

    $settings = self::validSettings();
    unset($settings['layout']);
    $cases['missing required setting'] = [$settings, ['layout']];

    $settings = self::validSettings();
    $settings['extra'] = TRUE;
    $cases['unknown setting'] = [$settings, ['extra']];

    foreach ($cases as $label => [$case_settings, $expected_paths]) {
      self::assertSame(
        $expected_paths,
        \array_keys(self::violationsToArray($this->validator->validate($case_settings))),
        $label,
      );
    }
  }

  /**
   * Tests the field type → family mapping and the per-family operators.
   */
  public function testFieldTypeFamilyMapping(): void {
    $expected_families = [
      'string' => ListElementFieldTypeFamily::Text,
      'string_long' => ListElementFieldTypeFamily::Text,
      'text_with_summary' => ListElementFieldTypeFamily::Text,
      'email' => ListElementFieldTypeFamily::Text,
      'boolean' => ListElementFieldTypeFamily::Options,
      'list_string' => ListElementFieldTypeFamily::Options,
      'list_integer' => ListElementFieldTypeFamily::Options,
      'language' => ListElementFieldTypeFamily::Options,
      'entity_reference' => ListElementFieldTypeFamily::Reference,
      'image' => ListElementFieldTypeFamily::Reference,
      'link' => ListElementFieldTypeFamily::Reference,
      'datetime' => ListElementFieldTypeFamily::Date,
      'timestamp' => ListElementFieldTypeFamily::Date,
      'created' => ListElementFieldTypeFamily::Date,
      'changed' => ListElementFieldTypeFamily::Date,
      'integer' => ListElementFieldTypeFamily::Number,
      'decimal' => ListElementFieldTypeFamily::Number,
      'float' => ListElementFieldTypeFamily::Number,
      // Unmapped field types degrade to the Unknown family.
      'daterange' => ListElementFieldTypeFamily::Unknown,
      'telephone' => ListElementFieldTypeFamily::Unknown,
    ];
    foreach ($expected_families as $field_type => $family) {
      self::assertSame($family, ListElementFieldTypeFamily::fromFieldType($field_type), $field_type);
    }

    // The Unknown family only supports presence checks, with or without a
    // target ID.
    self::assertSame(['is_set', 'not_set'], ListElementFieldTypeFamily::Unknown->allowedOperators());
    self::assertSame(['is_set', 'not_set'], ListElementFieldTypeFamily::Unknown->allowedOperators(TRUE));
    // Reference fields only support equality when they reference by target
    // ID (e.g. link fields do not).
    self::assertSame(['is_set', 'not_set'], ListElementFieldTypeFamily::Reference->allowedOperators());
    self::assertSame(['is_set', 'not_set', 'equals', 'not_equals'], ListElementFieldTypeFamily::Reference->allowedOperators(TRUE));

    self::assertFalse(ListElementFieldTypeFamily::Reference->isSortable());
    self::assertFalse(ListElementFieldTypeFamily::Unknown->isSortable());
    self::assertTrue(ListElementFieldTypeFamily::Text->isSortable());
    self::assertTrue(ListElementFieldTypeFamily::Options->isSortable());
    self::assertTrue(ListElementFieldTypeFamily::Date->isSortable());
    self::assertTrue(ListElementFieldTypeFamily::Number->isSortable());
  }

}
