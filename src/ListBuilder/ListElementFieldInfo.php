<?php

declare(strict_types=1);

namespace Drupal\canvas\ListBuilder;

use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\Plugin\Field\FieldType\EntityReferenceItem;

/**
 * Provides field metadata for the List element's filters and sorts.
 *
 * The same metadata drives three consumers: the settings form (which fields
 * and operators to offer), the settings validator (which stored conditions
 * are acceptable), and the query executor (how to translate a condition to
 * an entity query).
 *
 * @internal
 */
final class ListElementFieldInfo {

  /**
   * Field types that are never offered for filtering or sorting.
   *
   * These are either structural (IDs, revision bookkeeping), or Canvas' own
   * component tree fields, whose values make no sense as list conditions.
   */
  private const array EXCLUDED_FIELD_TYPES = ['uuid', 'password', 'path', 'component_tree'];

  public function __construct(
    private readonly EntityFieldManagerInterface $entityFieldManager,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Returns the fields of a bundle that the List element can filter on.
   *
   * @return array<string, array{label: string, family: ListElementFieldTypeFamily, has_target: bool, definition: FieldDefinitionInterface}>
   *   Keyed by field name.
   */
  public function getFilterableFields(string $entity_type_id, string $bundle): array {
    $entity_type = $this->entityTypeManager->getDefinition($entity_type_id);
    $excluded_names = \array_filter([
      $entity_type->getKey('id'),
      $entity_type->getKey('revision'),
      $entity_type->getKey('bundle'),
      $entity_type->getKey('langcode'),
      'default_langcode',
      'revision_translation_affected',
    ]);

    $fields = [];
    foreach ($this->entityFieldManager->getFieldDefinitions($entity_type_id, $bundle) as $name => $definition) {
      if (\in_array($name, $excluded_names, TRUE)
        || \str_starts_with($name, 'revision_')
        || \in_array($definition->getFieldStorageDefinition()->getType(), self::EXCLUDED_FIELD_TYPES, TRUE)
        || $definition->isComputed()) {
        continue;
      }
      $fields[$name] = [
        'label' => (string) $definition->getLabel(),
        'family' => ListElementFieldTypeFamily::fromFieldType($definition->getType()),
        'has_target' => \is_a($definition->getItemDefinition()->getClass(), EntityReferenceItem::class, TRUE),
        'definition' => $definition,
      ];
    }
    return $fields;
  }

  /**
   * Returns the fields of a bundle that the List element can sort on.
   *
   * Sortable fields are the filterable fields whose family supports ordering
   * and that store a single orderable column per item.
   *
   * @return array<string, array{label: string, family: ListElementFieldTypeFamily, has_target: bool, definition: FieldDefinitionInterface}>
   *   Keyed by field name.
   */
  public function getSortableFields(string $entity_type_id, string $bundle): array {
    return \array_filter(
      $this->getFilterableFields($entity_type_id, $bundle),
      static fn (array $field): bool => $field['family']->isSortable()
        && $field['definition']->getFieldStorageDefinition()->getCardinality() === 1,
    );
  }

  /**
   * Returns the fields of a bundle that the List element can iterate.
   *
   * A field source lists a field's values, so single-cardinality fields are
   * not offered: one value is not a list, and mapping the field straight to a
   * component prop already covers it.
   *
   * @return array<string, array{label: string, family: ListElementFieldTypeFamily, has_target: bool, definition: FieldDefinitionInterface}>
   *   Keyed by field name.
   */
  public function getMultiValueFields(string $entity_type_id, string $bundle): array {
    return \array_filter(
      $this->getFilterableFields($entity_type_id, $bundle),
      static fn (array $field): bool => $field['definition']->getFieldStorageDefinition()->getCardinality() !== 1,
    );
  }

  /**
   * Returns the entity query condition column for a field.
   *
   * For example `field_tags.target_id` or `title.value`.
   */
  public static function getQueryColumn(FieldDefinitionInterface $definition): string {
    $main_property = $definition->getFieldStorageDefinition()->getMainPropertyName();
    return $main_property === NULL
      ? $definition->getName()
      : \sprintf('%s.%s', $definition->getName(), $main_property);
  }

}
