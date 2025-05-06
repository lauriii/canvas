<?php

declare(strict_types=1);

namespace Drupal\experience_builder;

use Drupal\Component\Assertion\Inspector;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Sql\SqlEntityStorageInterface;
use Drupal\Core\Extension\ModuleUninstallValidatorInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Field\FieldTypePluginManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\TypedData\TypedDataManagerInterface;
use Drupal\experience_builder\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\experience_builder\Plugin\Field\FieldType\ComponentTreeItemInstantiatorTrait;
use Drupal\field\FieldConfigInterface;

/**
 * Prevents uninstallation of modules providing field types used by this module.
 */
final class FieldTypeUninstallValidator implements ModuleUninstallValidatorInterface {

  use StringTranslationTrait;
  use ComponentTreeItemInstantiatorTrait;

  public function __construct(
    TranslationInterface $string_translation,
    private readonly FieldTypePluginManagerInterface $fieldTypePluginManager,
    private readonly EntityFieldManagerInterface $fieldManager,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly Connection $database,
    TypedDataManagerInterface $typedDataManager,
  ) {
    $this->stringTranslation = $string_translation;
    $this->setTypedDataManager($typedDataManager);
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
    // @todo Is this considering editing the default value when there are
    //   content or config translations? Verify and add tests in
    //   https://www.drupal.org/i/3522199.
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
   * @return array<int, \Drupal\field\FieldConfigInterface|\Drupal\Core\Field\BaseFieldDefinition>
   *   An array of field storage definitions that match the provided field type.
   */
  private function getExperienceBuilderFieldInstances(): array {
    $fields_matching_type = [];
    $field_map = $this->fieldManager->getFieldMapByFieldType(ComponentTreeItem::PLUGIN_ID);
    foreach ($field_map as $entity_type_id => $fields) {
      foreach ($fields as $field_name => $field_info) {
        foreach ($field_info['bundles'] as $bundle_name) {
          $field_definitions = $this->fieldManager->getFieldDefinitions($entity_type_id, $bundle_name);
          $fields_matching_type[] = $field_definitions[$field_name];
        }
      }
    }
    assert(Inspector::assertAllObjects($fields_matching_type, FieldConfigInterface::class, BaseFieldDefinition::class));
    return $fields_matching_type;
  }

  /**
   * Gets all Experience Builder field storage instances.
   *
   * @return array<int, \Drupal\Core\Field\FieldStorageDefinitionInterface>
   */
  private function getExperienceBuilderFieldStorages(): array {
    $fields_matching_type = [];
    $field_map = $this->fieldManager->getFieldMapByFieldType(ComponentTreeItem::PLUGIN_ID);
    foreach ($field_map as $entity_type_id => $fields) {
      $field_storages = $this->fieldManager->getFieldStorageDefinitions($entity_type_id);
      /** @var string $field_name */
      foreach (array_keys($fields) as $field_name) {
        if (isset($field_storages[$field_name])) {
          $fields_matching_type[] = $field_storages[$field_name];
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
    $fields_using_provided_field = [];
    foreach ($this->getExperienceBuilderFieldInstances() as $component_field) {
      // @todo Should we handle default value callbacks?
      $default = $component_field->getDefaultValueLiteral();
      if (empty($default)) {
        continue;
      }
      $component_tree = $this->createDanglingComponentTree();
      assert($component_tree instanceof ComponentTreeItem);
      $component_tree->setValue($default[0]);
      $default_inputs_deps = $component_tree->calculateFieldItemValueDependencies(TRUE);
      if (in_array('field_type:' . $field_type_definition['id'], $default_inputs_deps['plugin'], TRUE)) {
        $fields_using_provided_field[] = $component_field->getName();
      }
    }
    return $fields_using_provided_field ?
      [$this->t('Provides a field type, %used_field, that is in use in the default value of the following fields: %components', ['%used_field' => $field_type_definition['id'], '%components' => implode(', ', $fields_using_provided_field)])] :
      [];
  }

  /**
   * Checks if a field is used in any XB fields on content entities.
   *
   * @param array<mixed> $field_definition
   * @param array<\Drupal\Core\Field\FieldStorageDefinitionInterface> $component_field_storages
   *
   * @return array<\Drupal\Core\StringTranslation\TranslatableMarkup>
   */
  private function checkContentEntityUses(array $field_definition, array $component_field_storages): array {
    $reasons = [];

    foreach ($component_field_storages as $component_field_storage) {
      $entity_type_id = $component_field_storage->getTargetEntityTypeId();
      $entity_storage = $this->entityTypeManager
        ->getStorage($entity_type_id);
      $id_key = $this->entityTypeManager->getDefinition($entity_type_id)->getKey('id');
      $revision_key = $this->entityTypeManager->getDefinition($entity_type_id)->getKey('revision');
      $field_name = $component_field_storage->getName();

      // @todo Decide if having a SqlEntityStorageInterface storage should be
      //   a required XB characteristic. See https://www.drupal.org/i/3498525.
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
        $id_key = 'entity_id';
        $revision_key = 'revision_id';
      }
      else {
        $base_table = $table_mapping->getDataTable();
        $revision_table = $table_mapping->getRevisionDataTable();
      }
      $table = $revision_table ?? $base_table;
      $column_name = $table_mapping->getFieldColumnName($component_field_storage, 'deps_plugin');
      if ($component_field_storage instanceof BaseFieldDefinition) {
        $column_names = $table_mapping->getColumnNames($field_name);
        $column_name = $column_names['deps_plugin'];
      }
      assert(\is_string($table));
      $select = $this->database->select($table);
      $select->fields($table, [$id_key, $revision_key]);

      // @todo Potentially optimize this in https://www.drupal.org/i/3521202.
      $select->where("$column_name LIKE '%field_type:{$field_definition['id']} %'");

      // @todo Determine how a site user would be able to find all entities that use a field.
      /** @var object $row */
      if ($row = $select->execute()?->fetchObject()) {
        // @todo These messages should be more user friendly.
        $reasons[] = $this->t('Provides a field type, %used_field, that is in use in the content of the following entities: %entity_type id=%entity_id revision=%revision_id',
          [
            '%used_field' => $field_definition['id'],
            '%entity_type' => $entity_type_id,
            '%entity_id' => $row->{$id_key},
            '%revision_id' => $row->{$revision_key},
          ]);
      }
    }
    return $reasons;
  }

}
