<?php

declare(strict_types=1);

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\CanvasConfigUpdater;
use Drupal\canvas\ContentTranslation\ComponentTreeFieldSymmetricalTranslationSynchronizer;
use Drupal\canvas\Entity\BrandKit;
use Drupal\canvas\Entity\Comment;
use Drupal\canvas\Entity\CommentThread;
use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\ContentTemplate;
use Drupal\canvas\Entity\Folder;
use Drupal\canvas\Entity\PageRegion;
use Drupal\canvas\Entity\Pattern;
use Drupal\canvas\Entity\StagedLanguageConfigOverride;
use Drupal\canvas\Plugin\Canvas\ComponentSource\BlockComponent;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\Component\Serialization\Json;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Config\Entity\ConfigEntityUpdater;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityDefinitionUpdateManagerInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\RevisionableStorageInterface;
use Drupal\Core\Site\Settings;
use Drupal\Core\TempStore\SharedTempStoreFactory;
use Drupal\field\Entity\FieldConfig;
use Drupal\image\Entity\ImageStyle;

/**
 * Track that props have the required flag in component config entities.
 */
function canvas_post_update_0001_track_props_have_required_flag_in_components(array &$sandbox): void {
  $canvasConfigUpdater = \Drupal::service(CanvasConfigUpdater::class);
  \assert($canvasConfigUpdater instanceof CanvasConfigUpdater);
  $canvasConfigUpdater->setDeprecationsEnabled(FALSE);
  \Drupal::classResolver(ConfigEntityUpdater::class)
    ->update($sandbox, Component::ENTITY_TYPE_ID, static fn(Component $component): bool => $canvasConfigUpdater->needsTrackingPropsRequiredFlag($component));
}

/**
 * @phpcs:ignore Drupal.Files.LineLength.TooLong
 * Update component dependencies after finding intermediate dependencies in patterns.
 * @phpcs:enable
 */
function canvas_post_update_0002_intermediate_component_dependencies_in_patterns(array &$sandbox): void {
  $canvasConfigUpdater = \Drupal::service(CanvasConfigUpdater::class);
  \assert($canvasConfigUpdater instanceof CanvasConfigUpdater);
  $canvasConfigUpdater->setDeprecationsEnabled(FALSE);
  \Drupal::classResolver(ConfigEntityUpdater::class)
    ->update($sandbox, Pattern::ENTITY_TYPE_ID, static fn(Pattern $pattern): bool => $canvasConfigUpdater->needsIntermediateDependenciesComponentUpdate($pattern));
}

/**
 * @phpcs:ignore Drupal.Files.LineLength.TooLong
 * Update component dependencies after finding intermediate dependencies in page regions.
 * @phpcs:enable
 */
function canvas_post_update_0002_intermediate_component_dependencies_in_page_regions(array &$sandbox): void {
  $canvasConfigUpdater = \Drupal::service(CanvasConfigUpdater::class);
  \assert($canvasConfigUpdater instanceof CanvasConfigUpdater);
  $canvasConfigUpdater->setDeprecationsEnabled(FALSE);
  \Drupal::classResolver(ConfigEntityUpdater::class)
    ->update($sandbox, PageRegion::ENTITY_TYPE_ID, static fn(PageRegion $region): bool => $canvasConfigUpdater->needsIntermediateDependenciesComponentUpdate($region));
}

/**
 * @phpcs:ignore Drupal.Files.LineLength.TooLong
 * Update component dependencies after finding intermediate dependencies in content templates.
 * @phpcs:enable
 */
function canvas_post_update_0002_intermediate_component_dependencies_in_content_templates(array &$sandbox): void {
  $canvasConfigUpdater = \Drupal::service(CanvasConfigUpdater::class);
  \assert($canvasConfigUpdater instanceof CanvasConfigUpdater);
  $canvasConfigUpdater->setDeprecationsEnabled(FALSE);
  \Drupal::classResolver(ConfigEntityUpdater::class)
    ->update($sandbox, ContentTemplate::ENTITY_TYPE_ID, static fn(ContentTemplate $template): bool => $canvasConfigUpdater->needsIntermediateDependenciesComponentUpdate($template));
}

/**
 * @phpcs:ignore Drupal.Files.LineLength.TooLong
 * Update component dependencies after finding intermediate dependencies in Canvas component tree instances' default values.
 * @phpcs:enable
 */
function canvas_post_update_0002_intermediate_component_dependencies_in_field_config_component_trees(array &$sandbox): void {
  $canvasConfigUpdater = \Drupal::service(CanvasConfigUpdater::class);
  \assert($canvasConfigUpdater instanceof CanvasConfigUpdater);
  $canvasConfigUpdater->setDeprecationsEnabled(FALSE);
  \Drupal::classResolver(ConfigEntityUpdater::class)
    ->update($sandbox, 'field_config', static fn(FieldConfig $field): bool => $canvasConfigUpdater->needsIntermediateDependenciesComponentUpdate($field));
}

/**
 * Rebuild the container after service rename.
 *
 * @see https://www.drupal.org/node/2960601
 * @see \Drupal\canvas\ShapeMatcher\PropSourceSuggester
 */
function canvas_post_update_0003_rename_service(): void {
  // Empty update to trigger container rebuild.
}

/**
 * Collapse component inputs for pattern entities.
 */
function canvas_post_update_0004_collapse_pattern_component_inputs(array &$sandbox): void {
  $canvasConfigUpdater = \Drupal::service(CanvasConfigUpdater::class);
  \assert($canvasConfigUpdater instanceof CanvasConfigUpdater);
  $canvasConfigUpdater->setDeprecationsEnabled(FALSE);
  \Drupal::classResolver(ConfigEntityUpdater::class)
    ->update($sandbox, Pattern::ENTITY_TYPE_ID, static fn(Pattern $pattern): bool => $canvasConfigUpdater->needsComponentInputsCollapsed($pattern));
}

/**
 * Collapse component inputs for page region entities.
 */
function canvas_post_update_0004_collapse_page_region_component_inputs(array &$sandbox): void {
  $canvasConfigUpdater = \Drupal::service(CanvasConfigUpdater::class);
  \assert($canvasConfigUpdater instanceof CanvasConfigUpdater);
  $canvasConfigUpdater->setDeprecationsEnabled(FALSE);
  \Drupal::classResolver(ConfigEntityUpdater::class)
    ->update($sandbox, PageRegion::ENTITY_TYPE_ID, static fn(PageRegion $region): bool => $canvasConfigUpdater->needsComponentInputsCollapsed($region));
}

/**
 * Collapse component inputs for content template entities.
 */
function canvas_post_update_0004_collapse_content_template_component_inputs(array &$sandbox): void {
  $canvasConfigUpdater = \Drupal::service(CanvasConfigUpdater::class);
  \assert($canvasConfigUpdater instanceof CanvasConfigUpdater);
  $canvasConfigUpdater->setDeprecationsEnabled(FALSE);
  \Drupal::classResolver(ConfigEntityUpdater::class)
    ->update($sandbox, ContentTemplate::ENTITY_TYPE_ID, static fn(ContentTemplate $template): bool => $canvasConfigUpdater->needsComponentInputsCollapsed($template));
}

/**
 * Collapse component inputs for field config entities.
 */
function canvas_post_update_0004_collapse_field_config_component_inputs(array &$sandbox): void {
  $canvasConfigUpdater = \Drupal::service(CanvasConfigUpdater::class);
  \assert($canvasConfigUpdater instanceof CanvasConfigUpdater);
  $canvasConfigUpdater->setDeprecationsEnabled(FALSE);
  \Drupal::classResolver(ConfigEntityUpdater::class)
    ->update($sandbox, 'field_config', static fn(FieldConfig $field): bool => $canvasConfigUpdater->needsComponentInputsCollapsed($field));
}

/**
 * Update component entities using text `value` to use `processed` instead.
 */
function canvas_post_update_0005_use_processed_for_text_props_in_components(array &$sandbox): void {
  $canvasConfigUpdater = \Drupal::service(CanvasConfigUpdater::class);
  \assert($canvasConfigUpdater instanceof CanvasConfigUpdater);
  $canvasConfigUpdater->setDeprecationsEnabled(FALSE);
  \Drupal::classResolver(ConfigEntityUpdater::class)
    ->update($sandbox, Component::ENTITY_TYPE_ID, static fn(Component $component): bool => $canvasConfigUpdater->needsUpdatingPropFieldDefinitionsUsingTextValue($component));
}

/**
 * Rebuilds the container after service gained a new argument.
 *
 * @see https://www.drupal.org/node/2960601
 * @see \Drupal\canvas\ShapeMatcher\JsonSchemaFieldInstanceMatcher
 */
function canvas_post_update_0006_add_service_argument(): void {
  // Empty update to trigger container rebuild.
}

/**
 * Ensures the right order of props in Component config entities.
 *
 * @see https://www.drupal.org/node/2960601
 * @see \Drupal\canvas\ShapeMatcher\JsonSchemaFieldInstanceMatcher
 */
function canvas_post_update_0007_respect_prop_ordering(array &$sandbox): void {
  $canvasConfigUpdater = \Drupal::service(CanvasConfigUpdater::class);
  \assert($canvasConfigUpdater instanceof CanvasConfigUpdater);
  $canvasConfigUpdater->setDeprecationsEnabled(FALSE);
  \Drupal::classResolver(ConfigEntityUpdater::class)
    ->update($sandbox, Component::ENTITY_TYPE_ID, static fn(Component $component): bool => $canvasConfigUpdater->needsPropReordering($component));
}

/**
 * Retrigger SDC component discovery.
 *
 * Two reasons:
 * 1. added support for well-known prop shape matching even if not referencing
 *    the well-known prop shape in the JSON schema for the SDC prop
 * 2. using a dot in a `meta:enum` key is no longer forbidden for SDCs
 *
 * @see https://www.drupal.org/node/2960601
 * @see \Drupal\canvas\Plugin\Canvas\ComponentSource\JsonSchemaPropsComponentSourceBase::getComponentInputsForMetadata()
 * @see \Drupal\canvas\PropShape\PropShape::standardize()
 * @see \Drupal\canvas\ComponentMetadataRequirementsChecker)
 */
function canvas_post_update_0008_rediscover_sdcs(): void {
  // Empty update to trigger cache wipe, which will re-trigger SDC discovery.
}

/**
 * Remove "category" property from existing instances of Component entities.
 */
function canvas_post_update_0009_unset_category_property_on_components(array &$sandbox): void {
  $canvasConfigUpdater = \Drupal::service(CanvasConfigUpdater::class);
  \assert($canvasConfigUpdater instanceof CanvasConfigUpdater);
  $canvasConfigUpdater->setDeprecationsEnabled(FALSE);
  \Drupal::classResolver(ConfigEntityUpdater::class)
    ->update($sandbox, Component::ENTITY_TYPE_ID, static fn(Component $component): bool => $canvasConfigUpdater->unsetComponentCategoryProperty($component));
}

/**
 * Migrate auto-save data from tempstore to key-value store.
 */
function canvas_post_update_0010_migrate_auto_save(): void {
  $keyvalue_factory = \Drupal::service('keyvalue');
  $tempstore_factory = \Drupal::service(SharedTempStoreFactory::class);

  $collections = [
    AutoSaveManager::AUTO_SAVE_STORE,
    AutoSaveManager::FORM_VIOLATIONS_STORE,
    AutoSaveManager::COMPONENT_INSTANCE_FORM_VIOLATIONS_STORE,
  ];

  foreach ($collections as $collection) {
    $tempstore = $tempstore_factory->get($collection);
    $keyvalue_store = $keyvalue_factory->get($collection);

    // SharedTempStore doesn't expose getAll() publicly. Use reflection to
    // access the protected $storage property which has the getAll() method.
    // The underlying key-value expirable storage has getAll() but it's not
    // part of the SharedTempStore public API.
    $reflection = new \ReflectionObject($tempstore);
    $storage_property = $reflection->getProperty('storage');
    $storage_property->setAccessible(TRUE);
    $tempstore_storage = $storage_property->getValue($tempstore);

    foreach ($tempstore_storage->getAll() as $key => $value) {
      if (\is_object($value) && isset($value->data)) {
        $data = $value->data;
        \assert(\property_exists($value, 'owner'));
        \assert(\property_exists($value, 'updated'));
        if ($collection === AutoSaveManager::AUTO_SAVE_STORE && isset($value->owner, $value->updated)) {
          $data['owner'] = (int) ($value->owner ?? 0);
          $data['updated'] = (int) ($value->updated ?? 0);
        }
        $keyvalue_store->set($key, $data);
      }
    }
  }
}

/**
 * Updates multi-bundle reference prop expressions to the improved format.
 *
 * (Also updates single-bundle reference prop expressions that are repeated in
 * every bundle of a multi-bundle reference prop, to keep things consistent.)
 *
 * @see https://www.drupal.org/node/3563451
 * @see \Drupal\canvas\CanvasConfigUpdater::expressionUsesDeprecatedReference()
 * @see \Drupal\canvas\Hook\ShapeMatchingHooks::mediaLibraryStorablePropShapeAlter()
 */
function canvas_post_update_0011_multi_bundle_reference_prop_expressions(array &$sandbox): void {
  $canvasConfigUpdater = \Drupal::service(CanvasConfigUpdater::class);
  \assert($canvasConfigUpdater instanceof CanvasConfigUpdater);
  $canvasConfigUpdater->setDeprecationsEnabled(FALSE);
  \Drupal::classResolver(ConfigEntityUpdater::class)
    ->update($sandbox, Component::ENTITY_TYPE_ID, static fn(Component $component): bool => $canvasConfigUpdater->needsMultiBundleReferencePropExpressionUpdate($component));
}

/**
 * Updates Canvas-provided image style to use AVIF with WebP fallback.
 */
function canvas_post_update_0012_canvas_image_style_avif(array &$sandbox): void {
  $image_style = ImageStyle::load('canvas_parametrized_width');
  $effect_id = '249b8926-f421-4d60-8453-fb5d9265c731';
  if (!$image_style) {
    return;
  }
  $effects = $image_style->getEffects();
  $effects_data = $image_style->get('effects');
  if ($effects->has($effect_id) && $effects->get($effect_id)->getPluginId() === 'image_convert') {
    $effects_data[$effect_id]['id'] = 'image_convert_avif';
    $image_style->set('effects', $effects_data);
    $image_style->save();
  }
}

/**
 * Updates content templates' DynamicPropSources to EntityFieldPropSources.
 */
function canvas_post_update_0013_update_dynamic_prop_sources_to_entity_field_prop_sources(array &$sandbox): void {
  $canvasConfigUpdater = \Drupal::service(CanvasConfigUpdater::class);
  \assert($canvasConfigUpdater instanceof CanvasConfigUpdater);
  $canvasConfigUpdater->setDeprecationsEnabled(FALSE);
  // Loading and re-saving automatically triggers a just-in-time update path.
  // @see \Drupal\canvas\PropSource\PropSource::parse()
  \Drupal::classResolver(ConfigEntityUpdater::class)
    // We might not need to update every single ContentTemplate, because
    // entity-field prop source presence is allowed, but not enforced via config
    // schema. But the chances a content template won't have an entity-field
    // prop source is quite low and irrelevant.
    ->update($sandbox, ContentTemplate::ENTITY_TYPE_ID, static fn(ContentTemplate $template): bool => TRUE);

}

/**
 * Creates the global brand kit config entity for updated sites.
 */
function canvas_post_update_0014_create_global_brand_kit(): void {
  $entity_definition_update_manager = \Drupal::service(EntityDefinitionUpdateManagerInterface::class);
  \assert($entity_definition_update_manager instanceof EntityDefinitionUpdateManagerInterface);
  $change_list = $entity_definition_update_manager->getChangeList();
  if (($change_list[BrandKit::ENTITY_TYPE_ID]['entity_type'] ?? NULL) === EntityDefinitionUpdateManagerInterface::DEFINITION_CREATED) {
    $entity_definition_update_manager->installEntityType(\Drupal::entityTypeManager()->getDefinition(BrandKit::ENTITY_TYPE_ID));
  }

  if (BrandKit::load(BrandKit::GLOBAL_ID) instanceof BrandKit) {
    return;
  }

  $brand_kit = BrandKit::create([
    'id' => BrandKit::GLOBAL_ID,
    'label' => 'Global brand kit',
    'dependencies' => [
      'enforced' => [
        'module' => ['canvas'],
      ],
    ],
  ]);
  $brand_kit->save();
}

/**
 * Remove incorrect dependency from config.
 */
function canvas_post_update_0015_remove_wrong_image_style_dependency_in_field_configs(array &$sandbox): void {
  \Drupal::classResolver(ConfigEntityUpdater::class)
    ->update($sandbox, 'field_config', static function (FieldConfig $field): bool {
      $dependencies = $field->getDependencies();
      return \in_array('image.style.canvas_parametrized_width', $dependencies['config'] ?? [], TRUE);
    });
}

/**
 * Pattern config entities' component trees' inputs must be arrays.
 */
function canvas_post_update_0016_pattern_component_inputs_must_be_arrays(array &$sandbox): void {
  $canvasConfigUpdater = \Drupal::service(CanvasConfigUpdater::class);
  \assert($canvasConfigUpdater instanceof CanvasConfigUpdater);
  $canvasConfigUpdater->setDeprecationsEnabled(FALSE);
  \Drupal::classResolver(ConfigEntityUpdater::class)
    ->update($sandbox, Pattern::ENTITY_TYPE_ID, static fn(Pattern $pattern): bool => $canvasConfigUpdater->needsConfigEntityWithComponentTreeInputsAsArrays($pattern));
}

/**
 * Page Region config entities' component trees' inputs must be arrays.
 */
function canvas_post_update_0016_page_region_component_inputs_must_be_arrays(array &$sandbox): void {
  $canvasConfigUpdater = \Drupal::service(CanvasConfigUpdater::class);
  \assert($canvasConfigUpdater instanceof CanvasConfigUpdater);
  $canvasConfigUpdater->setDeprecationsEnabled(FALSE);
  \Drupal::classResolver(ConfigEntityUpdater::class)
    ->update($sandbox, PageRegion::ENTITY_TYPE_ID, static fn(PageRegion $region): bool => $canvasConfigUpdater->needsConfigEntityWithComponentTreeInputsAsArrays($region));
}

/**
 * Content Template config entities' component trees' inputs must be arrays.
 */
function canvas_post_update_0016_content_template_component_inputs_must_be_arrays(array &$sandbox): void {
  $canvasConfigUpdater = \Drupal::service(CanvasConfigUpdater::class);
  \assert($canvasConfigUpdater instanceof CanvasConfigUpdater);
  $canvasConfigUpdater->setDeprecationsEnabled(FALSE);
  \Drupal::classResolver(ConfigEntityUpdater::class)
    ->update($sandbox, ContentTemplate::ENTITY_TYPE_ID, static fn(ContentTemplate $template): bool => $canvasConfigUpdater->needsConfigEntityWithComponentTreeInputsAsArrays($template));
}

/**
 * Component tree fields' default values' inputs must be arrays.
 */
function canvas_post_update_0016_component_tree_field_default_value_inputs(array &$sandbox): void {
  $canvasConfigUpdater = \Drupal::service(CanvasConfigUpdater::class);
  \assert($canvasConfigUpdater instanceof CanvasConfigUpdater);
  $canvasConfigUpdater->setDeprecationsEnabled(FALSE);
  \Drupal::classResolver(ConfigEntityUpdater::class)
    ->update($sandbox, 'field_config', static fn(FieldConfig $field): bool => $canvasConfigUpdater->needsConfigEntityWithComponentTreeInputsAsArrays($field));
}

/**
 * Pattern component trees must use UUID sequence keys.
 */
function canvas_post_update_0017_pattern_component_tree_sequence_keys(array &$sandbox): void {
  $canvasConfigUpdater = \Drupal::service(CanvasConfigUpdater::class);
  \assert($canvasConfigUpdater instanceof CanvasConfigUpdater);
  $canvasConfigUpdater->setDeprecationsEnabled(FALSE);
  \Drupal::classResolver(ConfigEntityUpdater::class)
    ->update($sandbox, Pattern::ENTITY_TYPE_ID, static fn(Pattern $pattern): bool => $canvasConfigUpdater->needsConfigEntityWithComponentTreeSequenceKeysUpdate($pattern));
}

/**
 * Page region component trees must use UUID sequence keys.
 */
function canvas_post_update_0017_page_region_component_tree_sequence_keys(array &$sandbox): void {
  $canvasConfigUpdater = \Drupal::service(CanvasConfigUpdater::class);
  \assert($canvasConfigUpdater instanceof CanvasConfigUpdater);
  $canvasConfigUpdater->setDeprecationsEnabled(FALSE);
  \Drupal::classResolver(ConfigEntityUpdater::class)
    ->update($sandbox, PageRegion::ENTITY_TYPE_ID, static fn(PageRegion $region): bool => $canvasConfigUpdater->needsConfigEntityWithComponentTreeSequenceKeysUpdate($region));
}

/**
 * Content template component trees must use UUID sequence keys.
 */
function canvas_post_update_0017_content_template_component_tree_sequence_keys(array &$sandbox): void {
  $canvasConfigUpdater = \Drupal::service(CanvasConfigUpdater::class);
  \assert($canvasConfigUpdater instanceof CanvasConfigUpdater);
  $canvasConfigUpdater->setDeprecationsEnabled(FALSE);
  \Drupal::classResolver(ConfigEntityUpdater::class)
    ->update($sandbox, ContentTemplate::ENTITY_TYPE_ID, static fn(ContentTemplate $template): bool => $canvasConfigUpdater->needsConfigEntityWithComponentTreeSequenceKeysUpdate($template));
}

/**
 * Update Folder config entities to declare their items as config dependencies.
 */
function canvas_post_update_0018_folder_component_dependencies(array &$sandbox): void {
  \Drupal::classResolver(ConfigEntityUpdater::class)
    ->update($sandbox, Folder::ENTITY_TYPE_ID, static fn(Folder $folder): bool => !empty($folder->get('items')));
}

/**
 * Recompute version hashes of components with a `list_float` prop default.
 */
function canvas_post_update_0019_recompute_list_float_component_version_hashes(array &$sandbox): void {
  $canvasConfigUpdater = \Drupal::service(CanvasConfigUpdater::class);
  \assert($canvasConfigUpdater instanceof CanvasConfigUpdater);
  $canvasConfigUpdater->setDeprecationsEnabled(FALSE);
  \Drupal::classResolver(ConfigEntityUpdater::class)
    ->update($sandbox, Component::ENTITY_TYPE_ID, static fn(Component $component): bool => $canvasConfigUpdater->updateListFloatComponentVersionHash($component));
}

/**
 * Installs the StagedLanguageConfigOverride config entity type.
 */
function canvas_post_update_0020_install_staged_language_config_override_entity_type(array &$sandbox): void {
  $entity_definition_update_manager = \Drupal::service(EntityDefinitionUpdateManagerInterface::class);
  \assert($entity_definition_update_manager instanceof EntityDefinitionUpdateManagerInterface);
  $change_list = $entity_definition_update_manager->getChangeList();
  if (($change_list[StagedLanguageConfigOverride::ENTITY_TYPE_ID]['entity_type'] ?? NULL) === EntityDefinitionUpdateManagerInterface::DEFINITION_CREATED) {
    $entity_definition_update_manager->installEntityType(\Drupal::entityTypeManager()->getDefinition(StagedLanguageConfigOverride::ENTITY_TYPE_ID));
  }
}

/**
 * Store each SDC/code component prop's `derived_schema_metadata`.
 */
function canvas_post_update_0021_store_prop_derived_schema_metadata(array &$sandbox): void {
  $canvasConfigUpdater = \Drupal::service(CanvasConfigUpdater::class);
  \assert($canvasConfigUpdater instanceof CanvasConfigUpdater);
  $canvasConfigUpdater->setDeprecationsEnabled(FALSE);
  \Drupal::classResolver(ConfigEntityUpdater::class)
    ->update($sandbox, Component::ENTITY_TYPE_ID, static fn(Component $component): bool => CanvasConfigUpdater::needsPropDerivedSchemaMetadata($component));
}

/**
 * Enforce symmetrical translation of the Canvas Page `components` base field.
 */
function canvas_post_update_0022_enforce_symmetrical_canvas_page_components_translation(): void {
  // Case 1. Sites without content_translation need no update: the override
  // gets created when content_translation gets installed.
  // @see \Drupal\canvas\Hook\ContentTranslationHooks::modulesInstalled()
  // @see \Drupal\Tests\canvas\Functional\Update\SymmetricalCanvasPageComponentsTranslationUpdateTest::testWithoutContentTranslation
  if (!\Drupal::moduleHandler()->moduleExists('content_translation')) {
    return;
  }
  // Case 2. Sites that used canvas_dev_translation and had invalid config are
  // forced into the only valid config.
  // @see \Drupal\Tests\canvas\Functional\Update\SymmetricalCanvasPageComponentsTranslationUpdateTest::testExistingOverrideWithUnsupportedSettings
  // Case 3. Sites that used canvas_dev_translation and had the right config:
  // an effective no-op.
  // @see \Drupal\Tests\canvas\Functional\Update\SymmetricalCanvasPageComponentsTranslationUpdateTest::testExistingOverrideWithValidSettings
  // Case 4. Sites with content_translation but no base field override yet get
  // it created.
  // @see \Drupal\Tests\canvas\Functional\Update\SymmetricalCanvasPageComponentsTranslationUpdateTest::testMissingOverride
  ComponentTreeFieldSymmetricalTranslationSynchronizer::ensureSymmetricalCanvasPageComponents();
}

/**
 * Convert legacy boolean block `label_display` inputs to strings in content.
 *
 * Core 11.3 (#3547808) made `block.settings` `label_display` a string enum
 * ('0' | 'visible'); data written under 11.2 could hold a boolean, which now
 * fails validation. Rewrite every block component instance whose
 * `label_display` input is a boolean, across all revisions and translations.
 * Config-entity trees and auto-save snapshots are covered by the sibling 0024
 * and 0025 updates.
 *
 * @see \Drupal\canvas\CanvasConfigUpdater::coerceBlockLabelDisplay()
 */
function canvas_post_update_0023_block_label_display_boolean_to_string(array &$sandbox): void {
  $entity_type_manager = \Drupal::entityTypeManager();

  // Build the work list once: every revision (or entity) id of every content
  // entity holding a component_tree field. Every revision must be fixed — the
  // data-health audit validates default, past and forward revisions separately.
  if (!isset($sandbox['items'])) {
    $entity_field_manager = \Drupal::service(EntityFieldManagerInterface::class);
    \assert($entity_field_manager instanceof EntityFieldManagerInterface);
    $sandbox['items'] = [];
    $sandbox['fields'] = [];
    foreach ($entity_field_manager->getFieldMapByFieldType(ComponentTreeItem::PLUGIN_ID) as $entity_type_id => $fields) {
      $sandbox['fields'][$entity_type_id] = \array_keys($fields);
      $query = $entity_type_manager->getStorage($entity_type_id)->getQuery()->accessCheck(FALSE);
      if ($entity_type_manager->getDefinition($entity_type_id)->isRevisionable()) {
        $query->allRevisions();
      }
      // allRevisions() keys the result by revision id, otherwise by entity id.
      foreach (\array_keys($query->execute()) as $id) {
        $sandbox['items'][] = [$entity_type_id, $id];
      }
    }
    $sandbox['total'] = \count($sandbox['items']);
    $sandbox['progress'] = 0;
  }

  $batch = \array_slice($sandbox['items'], $sandbox['progress'], (int) Settings::get('entity_update_batch_size', 50));
  foreach ($batch as [$entity_type_id, $id]) {
    $storage = $entity_type_manager->getStorage($entity_type_id);
    $revisionable = $entity_type_manager->getDefinition($entity_type_id)->isRevisionable();
    if ($revisionable) {
      \assert($storage instanceof RevisionableStorageInterface);
      $entity = $storage->loadRevision($id);
    }
    else {
      $entity = $storage->load($id);
    }
    if (!$entity instanceof ContentEntityInterface) {
      continue;
    }
    $changed = FALSE;
    // Inputs can differ per translation (asymmetric fields), so visit each.
    foreach ($entity->getTranslationLanguages() as $langcode => $language) {
      $translation = $entity->getTranslation($langcode);
      foreach ($sandbox['fields'][$entity_type_id] as $field_name) {
        \assert(\is_string($field_name));
        if (!$translation->hasField($field_name)) {
          continue;
        }
        foreach ($translation->get($field_name) as $item) {
          \assert($item instanceof ComponentTreeItem);
          $changed = CanvasConfigUpdater::coerceBlockLabelDisplay($item) || $changed;
        }
      }
    }
    if ($changed) {
      // Rewrite the revision in place; do not spawn a new one.
      if ($revisionable) {
        $entity->setNewRevision(FALSE);
      }
      $entity->save();
    }
  }

  $sandbox['progress'] += \count($batch);
  $sandbox['#finished'] = $sandbox['total'] == 0 ? 1 : ($sandbox['progress'] / $sandbox['total']);
}

/**
 * Cast boolean block `label_display` inputs to strings in Pattern trees.
 */
function canvas_post_update_0024_pattern_block_label_display_to_string(array &$sandbox): void {
  \Drupal::classResolver(ConfigEntityUpdater::class)
    ->update($sandbox, Pattern::ENTITY_TYPE_ID, static fn(Pattern $pattern): bool => CanvasConfigUpdater::needsBlockLabelDisplayCast($pattern));
}

/**
 * Cast boolean block `label_display` inputs to strings in Page Region trees.
 */
function canvas_post_update_0024_page_region_block_label_display_to_string(array &$sandbox): void {
  \Drupal::classResolver(ConfigEntityUpdater::class)
    ->update($sandbox, PageRegion::ENTITY_TYPE_ID, static fn(PageRegion $region): bool => CanvasConfigUpdater::needsBlockLabelDisplayCast($region));
}

/**
 * Cast boolean block `label_display` inputs to strings in Content Templates.
 */
function canvas_post_update_0024_content_template_block_label_display_to_string(array &$sandbox): void {
  \Drupal::classResolver(ConfigEntityUpdater::class)
    ->update($sandbox, ContentTemplate::ENTITY_TYPE_ID, static fn(ContentTemplate $template): bool => CanvasConfigUpdater::needsBlockLabelDisplayCast($template));
}

/**
 * Cast boolean block `label_display` inputs to strings in field default values.
 */
function canvas_post_update_0024_component_tree_field_default_value_block_label_display_to_string(array &$sandbox): void {
  \Drupal::classResolver(ConfigEntityUpdater::class)
    ->update($sandbox, 'field_config', static fn(FieldConfig $field): bool => CanvasConfigUpdater::needsBlockLabelDisplayCast($field));
}

/**
 * Cast boolean block `label_display` inputs to strings in auto-save snapshots.
 *
 * Auto-save drafts are stored as raw arrays in the `canvas.auto_save`
 * key-value collection and are never validated on load, so a boolean
 * `label_display` written under 11.2 would survive publishing and only fail
 * then. Rewrite every stored block instance whose `label_display` is bool.
 *
 * @todo Batch via $sandbox for very large auto-save stores.
 * @todo Verify the two stored `inputs` shapes (JSON string for content, array
 *   for config) against real 11.2 auto-save data, and whether the data-health
 *   audit re-hashes snapshots (if so, refresh the entry hash here).
 */
function canvas_post_update_0025_auto_save_block_label_display_to_string(array &$sandbox): void {
  $store = \Drupal::keyValue(AutoSaveManager::AUTO_SAVE_STORE);
  $changed_keys = [];
  foreach ($store->getAll() as $key => $entry) {
    if (!\is_array($entry) || !\array_key_exists('data', $entry) || !\is_array($entry['data'])) {
      continue;
    }
    if (_canvas_coerce_block_label_display_in_raw($entry['data'])) {
      $store->set($key, $entry);
      $changed_keys[] = $key;
    }
  }
  if ($changed_keys !== []) {
    \Drupal::service(CacheTagsInvalidatorInterface::class)->invalidateTags([AutoSaveManager::CACHE_TAG]);
  }
}

/**
 * Recursively coerces block `label_display` inputs in a raw auto-save array.
 *
 * A component instance is any associative array carrying both a `component_id`
 * (a "block.*" plugin ID) and an `inputs` member. `inputs` is a JSON string for
 * content-entity component-tree items and a decoded array for config-entity
 * trees; both are handled. Mutates $data by reference; returns TRUE if changed.
 */
function _canvas_coerce_block_label_display_in_raw(array &$data): bool {
  $changed = FALSE;
  if (
    isset($data['component_id']) && \is_string($data['component_id'])
    && \str_starts_with($data['component_id'], BlockComponent::SOURCE_PLUGIN_ID . '.')
    && \array_key_exists('inputs', $data)
  ) {
    $inputs = $data['inputs'];
    $was_string = \is_string($inputs);
    if ($was_string) {
      $inputs = Json::decode($inputs);
    }
    if (\is_array($inputs) && \array_key_exists('label_display', $inputs) && \is_bool($inputs['label_display'])) {
      $inputs['label_display'] = $inputs['label_display'] ? 'visible' : '0';
      $data['inputs'] = $was_string ? Json::encode($inputs) : $inputs;
      $changed = TRUE;
    }
  }
  foreach ($data as &$value) {
    if (\is_array($value)) {
      $changed = _canvas_coerce_block_label_display_in_raw($value) || $changed;
    }
  }
  return $changed;
}

/**
 * Installs the comment thread and comment entity types.
 */
function canvas_post_update_0026_install_comment_entity_types(): void {
  $entity_definition_update_manager = \Drupal::service(EntityDefinitionUpdateManagerInterface::class);
  \assert($entity_definition_update_manager instanceof EntityDefinitionUpdateManagerInterface);
  $change_list = $entity_definition_update_manager->getChangeList();
  foreach ([CommentThread::ENTITY_TYPE_ID, Comment::ENTITY_TYPE_ID] as $entity_type_id) {
    if (($change_list[$entity_type_id]['entity_type'] ?? NULL) === EntityDefinitionUpdateManagerInterface::DEFINITION_CREATED) {
      $entity_definition_update_manager->installEntityType(\Drupal::entityTypeManager()->getDefinition($entity_type_id));
    }
  }
}

/**
 * Records where in a component a comment was left.
 */
function canvas_post_update_0027_comment_thread_offsets(): void {
  $entity_definition_update_manager = \Drupal::service(EntityDefinitionUpdateManagerInterface::class);
  \assert($entity_definition_update_manager instanceof EntityDefinitionUpdateManagerInterface);
  $entity_field_manager = \Drupal::service(EntityFieldManagerInterface::class);
  \assert($entity_field_manager instanceof EntityFieldManagerInterface);
  $fields = $entity_field_manager->getBaseFieldDefinitions(CommentThread::ENTITY_TYPE_ID);
  foreach (['offset_x', 'offset_y'] as $field_name) {
    // Threads that predate this stay unset, and their pins keep sitting at the
    // anchored component's top-left corner.
    if ($entity_definition_update_manager->getFieldStorageDefinition($field_name, CommentThread::ENTITY_TYPE_ID) === NULL) {
      $entity_definition_update_manager->installFieldStorageDefinition(
        $field_name,
        CommentThread::ENTITY_TYPE_ID,
        'canvas',
        $fields[$field_name],
      );
    }
  }
}
