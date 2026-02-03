<?php

declare(strict_types=1);

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\CanvasConfigUpdater;
use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\ContentTemplate;
use Drupal\canvas\Entity\PageRegion;
use Drupal\canvas\Entity\Pattern;
use Drupal\Core\Config\Entity\ConfigEntityUpdater;
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
 * @see \Drupal\canvas\Plugin\Canvas\ComponentSource\GeneratedFieldExplicitInputUxComponentSourceBase::getComponentInputsForMetadata()
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
      if (is_object($value) && isset($value->data)) {
        $data = $value->data;
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
