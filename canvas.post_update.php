<?php

declare(strict_types=1);

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\CanvasConfigUpdater;
use Drupal\canvas\ContentTranslation\ComponentTreeFieldSymmetricalTranslationSynchronizer;
use Drupal\canvas\Entity\BrandKit;
use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\ContentTemplate;
use Drupal\canvas\Entity\Folder;
use Drupal\canvas\Entity\PageRegion;
use Drupal\canvas\Entity\PageVariant;
use Drupal\canvas\Entity\Pattern;
use Drupal\canvas\Entity\StagedLanguageConfigOverride;
use Drupal\canvas\Plugin\Canvas\ComponentSource\Marker;
use Drupal\canvas\Plugin\DisplayVariant\CanvasPageVariant;
use Drupal\Core\Config\Entity\ConfigEntityUpdater;
use Drupal\Core\Entity\EntityDefinitionUpdateManagerInterface;
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
  $tempstore_factory = \Drupal::service('tempstore.shared');

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
  $entity_definition_update_manager = \Drupal::service('entity.definition_update_manager');
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
  $entity_definition_update_manager = \Drupal::service('entity.definition_update_manager');
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
 * Install page variants: entity type, selection field, marker, and settings.
 */
function canvas_post_update_0023_install_page_variants(): void {
  $update_manager = \Drupal::service('entity.definition_update_manager');
  \assert($update_manager instanceof EntityDefinitionUpdateManagerInterface);
  $entity_type_manager = \Drupal::entityTypeManager();

  // 1. Install the page_variant config entity type.
  if ($update_manager->getEntityType(PageVariant::ENTITY_TYPE_ID) === NULL) {
    $update_manager->installEntityType($entity_type_manager->getDefinition(PageVariant::ENTITY_TYPE_ID));
  }

  // 2. Install the page_variant selection field on canvas_page.
  if ($update_manager->getFieldStorageDefinition('page_variant', 'canvas_page') === NULL) {
    $storage_definitions = \Drupal::service('entity_field.manager')->getFieldStorageDefinitions('canvas_page');
    if (isset($storage_definitions['page_variant'])) {
      $update_manager->installFieldStorageDefinition('page_variant', 'canvas_page', 'canvas', $storage_definitions['page_variant']);
    }
  }

  // 3. Create the "Page content" marker component (shipped in config/install,
  // which existing sites do not import).
  // @see config/install/canvas.component.marker.page_content.yml
  if (Component::load(Marker::PAGE_CONTENT_COMPONENT_ID) === NULL) {
    Component::create([
      'id' => Marker::PAGE_CONTENT_COMPONENT_ID,
      'label' => 'Page content',
      'provider' => 'canvas',
      'source' => Marker::SOURCE_PLUGIN_ID,
      'source_local_id' => Marker::PAGE_CONTENT_LOCAL_ID,
      'active_version' => '3b12c0b99a6caecc',
      'versioned_properties' => [
        'active' => [
          'settings' => [],
          'fallback_metadata' => ['slot_definitions' => []],
        ],
      ],
      'dependencies' => ['enforced' => ['module' => ['canvas']]],
    ])->save();
  }

  // 4. Create the settings object holding the default page variant.
  $settings = \Drupal::configFactory()->getEditable('canvas.settings');
  if ($settings->isNew()) {
    $settings->set('default_page_variant', NULL)->save();
  }
}

/**
 * Convert each theme's page regions into one page variant.
 */
function canvas_post_update_0024_migrate_page_regions_to_variants(): void {
  $all_regions = PageRegion::loadMultiple();
  if (!$all_regions) {
    // Sites that never used page regions need no migration.
    return;
  }

  // The theme page template component reproduces the theme's original markup.
  if (!\Drupal::moduleHandler()->moduleExists('canvas_page_template_component')) {
    \Drupal::service('module_installer')->install(['canvas_page_template_component']);
  }

  $uuid = \Drupal::service('uuid');
  $default_theme = \Drupal::config('system.theme')->get('default');
  $marker = Component::load(Marker::PAGE_CONTENT_COMPONENT_ID);
  \assert($marker instanceof Component);

  // Group the regions by theme.
  $by_theme = [];
  foreach ($all_regions as $region) {
    \assert($region instanceof PageRegion);
    $by_theme[$region->get('theme')][$region->get('region')] = $region;
  }

  foreach ($by_theme as $theme => $regions) {
    $template_id = 'theme_page_template.' . $theme;
    $template = Component::load($template_id);
    if (!$template instanceof Component) {
      // The submodule does not ship this theme; a manual variant is needed.
      continue;
    }

    // The variant tree: the theme page template with the marker in its content
    // slot, plus each region's components in the matching slot.
    $template_uuid = $uuid->generate();
    $tree = [
      [
        'uuid' => $template_uuid,
        'component_id' => $template_id,
        'component_version' => $template->getActiveVersion(),
        'inputs' => [],
      ],
      [
        'uuid' => $uuid->generate(),
        'component_id' => Marker::PAGE_CONTENT_COMPONENT_ID,
        'component_version' => $marker->getActiveVersion(),
        'parent_uuid' => $template_uuid,
        'slot' => CanvasPageVariant::MAIN_CONTENT_REGION,
        'inputs' => [],
      ],
    ];
    foreach ($regions as $region_name => $region) {
      if ($region_name === CanvasPageVariant::MAIN_CONTENT_REGION) {
        continue;
      }
      foreach ($region->getComponentTree()->getValue() as $item) {
        if (empty($item['parent_uuid'])) {
          // Re-parent this region's root components into the template slot.
          $item['parent_uuid'] = $template_uuid;
          $item['slot'] = $region_name;
        }
        $tree[] = $item;
      }
    }

    $variant_id = 'theme_' . \preg_replace('/[^a-z0-9_]/', '_', (string) $theme);
    if (!PageVariant::load($variant_id)) {
      PageVariant::create([
        'id' => $variant_id,
        'label' => \ucfirst((string) $theme) . ' theme',
        'component_tree' => $tree,
      ])->save();
    }

    if ($theme === $default_theme) {
      \Drupal::configFactory()->getEditable('canvas.settings')
        ->set(PageVariant::DEFAULT_SETTING, $variant_id)
        ->save();
    }
  }
}
