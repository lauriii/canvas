<?php

declare(strict_types=1);

namespace Drupal\experience_builder;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Sql\SqlEntityStorageInterface;
use Drupal\Core\Extension\ModuleUninstallValidatorInterface;
use Drupal\Core\Field\FieldTypePluginManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\experience_builder\PropExpressions\StructuredData\FieldTypePropExpression;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;

/**
 * Prevents uninstallation of modules providing field types used by this module.
 */
final class FieldTypeUninstallValidator implements ModuleUninstallValidatorInterface {

  use StringTranslationTrait;

  public function __construct(
    TranslationInterface $string_translation,
    private readonly FieldTypePluginManagerInterface $fieldTypePluginManager,
    private readonly EntityFieldManagerInterface $fieldManager,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly Connection $database,
  ) {
    $this->stringTranslation = $string_translation;
  }

  /**
   * {@inheritdoc}
   */
  public function validate($module) {
    $field_type_definitions = $this->getFieldTypeDefinitionsByProvider($module);
    if (empty($field_type_definitions)) {
      // If this module does not provide any field types there is nothing to
      // validate.
      return [];
    }
    $component_field_storages = $this->getExperienceBuilderFieldStorages();
    if (empty($component_field_storages)) {
      // If there are no Experience Builder fields there is nothing to validate.
      return [];
    }
    $reasons = [];
    foreach ($field_type_definitions as $field_type_definition) {
      $reasons = array_merge(
        $reasons,
        $this->checkContentEntityUses($field_type_definition, $component_field_storages),
        $this->checkDefaultValueUses($field_type_definition)
      );
    }
    // @phpstan-ignore-next-line
    return $reasons;
  }

  /**
   * Returns all field type definitions provided by the specified provider.
   *
   * @param string $provider
   *   A potential provider of field types.
   *
   * @return array<string, mixed>
   *   The field type definitions for the specified provider.
   */
  private function getFieldTypeDefinitionsByProvider(string $provider): array {
    return array_filter($this->fieldTypePluginManager->getDefinitions(), fn ($definition) => $definition['provider'] === $provider);
  }

  /**
   * Gets all Experience Builder field instances.
   *
   * @return array<\Drupal\field\FieldConfigInterface>
   *   An array of field storage definitions that match the provided field type.
   */
  private function getExperienceBuilderFieldInstances(): array {
    $fields_matching_type = [];
    $field_map = $this->fieldManager->getFieldMapByFieldType('component_tree');
    foreach ($field_map as $entity_type_id => $fields) {
      foreach ($fields as $field_name => $field_info) {
        foreach ($field_info['bundles'] as $bundle_name) {
          // @todo Refactor to use FieldConfig::loadMultiple().
          if ($matching_field = FieldConfig::loadByName($entity_type_id, $bundle_name, $field_name)) {
            $fields_matching_type[] = $matching_field;
          }
        }
      }
    }
    return $fields_matching_type;
  }

  /**
   * Gets all Experience Builder field storage instances.
   *
   * @return array<\Drupal\field\FieldStorageConfigInterface>
   */
  private function getExperienceBuilderFieldStorages(): array {
    $fields_matching_type = [];
    $field_map = $this->fieldManager->getFieldMapByFieldType('component_tree');
    foreach ($field_map as $entity_type_id => $fields) {
      /** @var string $field_name */
      foreach (array_keys($fields) as $field_name) {
        // @todo Refactor to use FieldStorageConfig::loadMultiple().
        if ($matching_field = FieldStorageConfig::loadByName($entity_type_id, $field_name)) {
          $fields_matching_type[] = $matching_field;
        }
      }
    }
    return $fields_matching_type;
  }

  /**
   * Checks if a field type is in use in the default value of any XB fields.
   *
   * @param array<mixed> $field_type_definition
   *
   * @return array<\Drupal\Core\StringTranslation\TranslatableMarkup>
   */
  private function checkDefaultValueUses(array $field_type_definition): array {
    $field_type_prop_expression_prefix = $this->getPropExpressionPrefixForFieldType($field_type_definition);

    $fields_using_provided_field = [];
    foreach ($this->getExperienceBuilderFieldInstances() as $component_field) {
      // @todo Should we handle default value callbacks?
      $default = $component_field->getDefaultValueLiteral();
      if (empty($default)) {
        continue;
      }
      // @todo Refactor to use \Drupal\experience_builder\Plugin\DataType\ComponentPropsValues directly.
      // @todo Refactor to use \Drupal\experience_builder\PropSource\PropSourceBase too, perhaps.
      $default_props = $default[0]['props'];
      foreach (json_decode($default_props, TRUE) as $default_prop_values) {
        foreach ($default_prop_values as $default_prop_value) {
          if (isset($default_prop_value['expression']) && is_string($default_prop_value['expression']) && str_starts_with($default_prop_value['expression'], $field_type_prop_expression_prefix)) {
            $fields_using_provided_field[] = $component_field->getName();
          }
        }
      }
    }
    return $fields_using_provided_field ?
      [$this->t('Provides a field type, %used_field, that is in use in the default value of the following fields: %components', ['%used_field' => $field_type_definition['id'], '%components' => implode(', ', $fields_using_provided_field)])] :
      [];
  }

  /**
   * Gets the prop expression prefix that matches all expressions for this type.
   *
   * @param array<mixed> $field_type_definition
   *
   * @return string
   *
   * @see \Drupal\experience_builder\PropExpressions\StructuredData\FieldTypePropExpression
   * @see \Drupal\experience_builder\PropExpressions\StructuredData\FieldTypeObjectPropsExpression
   * @see \Drupal\experience_builder\PropExpressions\StructuredData\ReferenceFieldTypePropExpression
   */
  private static function getPropExpressionPrefixForFieldType(array $field_type_definition): string {
    return (string) (new FieldTypePropExpression($field_type_definition['id'], ''));
  }

  /**
   * Checks if a field is used in any XB fields on content entities.
   *
   * @param array<mixed> $field_definition
   * @param array<\Drupal\field\FieldStorageConfigInterface> $component_field_storages
   *
   * @return array<\Drupal\Core\StringTranslation\TranslatableMarkup>
   */
  private function checkContentEntityUses(array $field_definition, array $component_field_storages): array {
    $reasons = [];
    $field_expression = self::getPropExpressionPrefixForFieldType($field_definition);

    foreach ($component_field_storages as $component_field_storage) {
      $entity_type_id = $component_field_storage->getTargetEntityTypeId();
      $entity_storage = $this->entityTypeManager
        ->getStorage($entity_type_id);
      if (!$entity_storage instanceof SqlEntityStorageInterface) {
        throw new \LogicException('@todo not yet supported!');
      }
      /** @var \Drupal\Core\Entity\Sql\DefaultTableMapping $table_mapping */
      $table_mapping = $entity_storage
        ->getTableMapping();
      // Check whether the field has dedicated storage.
      if ($table_mapping->requiresDedicatedTableStorage($component_field_storage)) {
        $base_table = $table_mapping->getDedicatedDataTableName($component_field_storage);
        $revision_table = $table_mapping->getDedicatedRevisionTableName($component_field_storage);
      }
      else {
        $base_table = $table_mapping->getBaseTable();
        $revision_table = $table_mapping->getRevisionTable();
      }
      $table = $revision_table ?? $base_table;
      $column_name = $table_mapping->getFieldColumnName($component_field_storage,
        'props');
      $select = $this->database->select($table);
      $select->fields($table, ['entity_id', 'revision_id']);

      // @todo The "$.*.*.expression" wildcard key matching works in Mariadb but not Sqlite.
      //   We need figure out a way to make this work in Sqlite and PGSQL in https://drupal.org/i/3452756.
      $select->where("JSON_EXTRACT($column_name, '$.*.*.expression') LIKE '%$field_expression%'");
      // @todo Determine how a site user would be able to find all entities that use a field.
      /** @var object $row */
      if ($row = $select->execute()?->fetchObject()) {
        // @todo These messages should be more user friendly.
        $reasons[] = $this->t('Provides a field type, %used_field, that is in use in the content of the following entities: %entity_type id=%entity_id revision=%revision_id',
          [
            '%used_field' => $field_definition['id'],
            '%entity_type' => $entity_type_id,
            // @phpstan-ignore-next-line
            '%entity_id' => $row->entity_id,
            // @phpstan-ignore-next-line
            '%revision_id' => $row->revision_id,
          ]);
      }
    }
    return $reasons;
  }

}
