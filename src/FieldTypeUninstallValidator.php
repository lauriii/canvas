<?php

declare(strict_types=1);

namespace Drupal\experience_builder;

use Drupal\Component\Assertion\Inspector;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Extension\ModuleUninstallValidatorInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Field\FieldTypePluginManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\TypedData\TypedDataManagerInterface;
use Drupal\experience_builder\Audit\ComponentTreeDependencyRepository;
use Drupal\experience_builder\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\experience_builder\Plugin\Field\FieldType\ComponentTreeItemListInstantiatorTrait;
use Drupal\field\FieldConfigInterface;

/**
 * Prevents uninstallation of modules providing field types used by this module.
 */
final class FieldTypeUninstallValidator implements ModuleUninstallValidatorInterface {

  use StringTranslationTrait;
  use ComponentTreeItemListInstantiatorTrait;

  public function __construct(
    TranslationInterface $string_translation,
    private readonly FieldTypePluginManagerInterface $fieldTypePluginManager,
    private readonly EntityFieldManagerInterface $fieldManager,
    TypedDataManagerInterface $typedDataManager,
    private readonly ComponentTreeDependencyRepository $dependencyRepository,
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
        $this->checkContentEntityUses($field_type_definition),
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
      $component_tree = $this->createDanglingComponentTreeItemList();
      $component_tree->setValue($default);
      $default_inputs_deps = [];
      foreach ($component_tree as $item) {
        \assert($item instanceof ComponentTreeItem);
        $default_inputs_deps = NestedArray::mergeDeep($default_inputs_deps, $item->calculateFieldItemValueDependencies(TRUE));
      }
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
   *
   * @return array<\Drupal\Core\StringTranslation\TranslatableMarkup>
   */
  private function checkContentEntityUses(array $field_definition): array {
    return \array_map(static fn(array $dependent): TranslatableMarkup => new TranslatableMarkup('Provides a field type, %used_field, that is in use in the content of the following entities: %entity_type id=%entity_id revision=%revision_id',
    [
      '%used_field' => $field_definition['id'],
    ] + $dependent), $this->dependencyRepository->getPluginDependents('field_type:' . $field_definition['id']));
  }

}
