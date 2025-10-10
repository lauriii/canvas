<?php

declare(strict_types=1);

use Drupal\canvas\CanvasConfigUpdater;
use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\ContentTemplate;
use Drupal\canvas\Entity\PageRegion;
use Drupal\canvas\Entity\Pattern;
use Drupal\Core\Config\Entity\ConfigEntityUpdater;
use Drupal\field\Entity\FieldConfig;

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
 * Update component dependencies after finding intermediate dependencies in patterns.
 */
function canvas_post_update_0002_intermediate_component_dependencies_in_patterns(array &$sandbox): void {
  $canvasConfigUpdater = \Drupal::service(CanvasConfigUpdater::class);
  \assert($canvasConfigUpdater instanceof CanvasConfigUpdater);
  $canvasConfigUpdater->setDeprecationsEnabled(FALSE);
  \Drupal::classResolver(ConfigEntityUpdater::class)
    ->update($sandbox, Pattern::ENTITY_TYPE_ID, static fn(Pattern $pattern): bool => $canvasConfigUpdater->needsIntermediateDependenciesComponentUpdate($pattern));
}

/**
 * Update component dependencies after finding intermediate dependencies in page regions.
 */
function canvas_post_update_0002_intermediate_component_dependencies_in_page_regions(array &$sandbox): void {
  $canvasConfigUpdater = \Drupal::service(CanvasConfigUpdater::class);
  \assert($canvasConfigUpdater instanceof CanvasConfigUpdater);
  $canvasConfigUpdater->setDeprecationsEnabled(FALSE);
  \Drupal::classResolver(ConfigEntityUpdater::class)
    ->update($sandbox, PageRegion::ENTITY_TYPE_ID, static fn(PageRegion $region): bool => $canvasConfigUpdater->needsIntermediateDependenciesComponentUpdate($region));
}

/**
 * Update component dependencies after finding intermediate dependencies in content templates.
 */
function canvas_post_update_0002_intermediate_component_dependencies_in_content_templates(array &$sandbox): void {
  $canvasConfigUpdater = \Drupal::service(CanvasConfigUpdater::class);
  \assert($canvasConfigUpdater instanceof CanvasConfigUpdater);
  $canvasConfigUpdater->setDeprecationsEnabled(FALSE);
  \Drupal::classResolver(ConfigEntityUpdater::class)
    ->update($sandbox, ContentTemplate::ENTITY_TYPE_ID, static fn(ContentTemplate $template): bool => $canvasConfigUpdater->needsIntermediateDependenciesComponentUpdate($template));
}

/**
 * Update component dependencies after finding intermediate dependencies in Canvas component tree instances' default values.
 */
function canvas_post_update_0002_intermediate_component_dependencies_in_field_config_component_trees(array &$sandbox): void {
  $canvasConfigUpdater = \Drupal::service(CanvasConfigUpdater::class);
  \assert($canvasConfigUpdater instanceof CanvasConfigUpdater);
  $canvasConfigUpdater->setDeprecationsEnabled(FALSE);
  \Drupal::classResolver(ConfigEntityUpdater::class)
    ->update($sandbox, 'field_config', static fn(FieldConfig $field): bool => $canvasConfigUpdater->needsIntermediateDependenciesComponentUpdate($field));
}
