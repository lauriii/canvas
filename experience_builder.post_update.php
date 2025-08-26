<?php

use Drupal\Core\Config\Entity\ConfigEntityUpdater;
use Drupal\experience_builder\Entity\ContentTemplate;
use Drupal\experience_builder\Entity\JavaScriptComponent;
use Drupal\experience_builder\Entity\PageRegion;
use Drupal\experience_builder\Entity\Pattern;
use Drupal\experience_builder\ExperienceBuilderConfigUpdater;
use Drupal\field\Entity\FieldConfig;

/**
 * @file
 * Post update functions for Experience Builder.
 */

/**
 * Add `dataDependencies` key to JavaScriptComponent entities.
 */
function experience_builder_post_update_javascript_component_data_dependencies(array &$sandbox): void {
  $xbConfigUpdater = \Drupal::service(ExperienceBuilderConfigUpdater::class);
  assert($xbConfigUpdater instanceof ExperienceBuilderConfigUpdater);
  $xbConfigUpdater->setDeprecationsEnabled(FALSE);
  \Drupal::classResolver(ConfigEntityUpdater::class)
    ->update($sandbox, JavaScriptComponent::ENTITY_TYPE_ID, function (JavaScriptComponent $javaScriptComponent) use ($xbConfigUpdater): bool {
      return $xbConfigUpdater->needsDataDependenciesUpdate($javaScriptComponent);
    });
}

/**
 * Collapse component inputs for pattern entities.
 */
function experience_builder_post_update_collapse_pattern_component_inputs(array &$sandbox): void {
  $xbConfigUpdater = \Drupal::service(ExperienceBuilderConfigUpdater::class);
  \assert($xbConfigUpdater instanceof ExperienceBuilderConfigUpdater);
  $xbConfigUpdater->setDeprecationsEnabled(FALSE);
  \Drupal::classResolver(ConfigEntityUpdater::class)
    ->update($sandbox, Pattern::ENTITY_TYPE_ID, static fn(Pattern $pattern): bool => $xbConfigUpdater->needsComponentInputsCollapsed($pattern));
}

/**
 * Collapse component inputs for page region entities.
 */
function experience_builder_post_update_collapse_page_region_component_inputs(array &$sandbox): void {
  $xbConfigUpdater = \Drupal::service(ExperienceBuilderConfigUpdater::class);
  \assert($xbConfigUpdater instanceof ExperienceBuilderConfigUpdater);
  $xbConfigUpdater->setDeprecationsEnabled(FALSE);
  \Drupal::classResolver(ConfigEntityUpdater::class)
    ->update($sandbox, PageRegion::ENTITY_TYPE_ID, static fn(PageRegion $region): bool => $xbConfigUpdater->needsComponentInputsCollapsed($region));
}

/**
 * Collapse component inputs for content template entities.
 */
function experience_builder_post_update_collapse_content_template_component_inputs(array &$sandbox): void {
  $xbConfigUpdater = \Drupal::service(ExperienceBuilderConfigUpdater::class);
  \assert($xbConfigUpdater instanceof ExperienceBuilderConfigUpdater);
  $xbConfigUpdater->setDeprecationsEnabled(FALSE);
  \Drupal::classResolver(ConfigEntityUpdater::class)
    ->update($sandbox, ContentTemplate::ENTITY_TYPE_ID, static fn(ContentTemplate $template): bool => $xbConfigUpdater->needsComponentInputsCollapsed($template));
}

/**
 * Collapse component inputs for field config entities.
 */
function experience_builder_post_update_collapse_field_config_component_inputs(array &$sandbox): void {
  $xbConfigUpdater = \Drupal::service(ExperienceBuilderConfigUpdater::class);
  \assert($xbConfigUpdater instanceof ExperienceBuilderConfigUpdater);
  $xbConfigUpdater->setDeprecationsEnabled(FALSE);
  \Drupal::classResolver(ConfigEntityUpdater::class)
    ->update($sandbox, 'field_config', static fn(FieldConfig $field): bool => $xbConfigUpdater->needsComponentInputsCollapsed($field));
}

/**
 * Rebuild the container to drop the SDC validator constraint class alter.
 */
function experience_builder_post_update_remove_sdc_validator_constraint_class(): void {
  // Empty update to trigger container rebuild.
}
