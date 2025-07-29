<?php

use Drupal\Core\Config\Entity\ConfigEntityUpdater;
use Drupal\experience_builder\Entity\JavaScriptComponent;
use Drupal\experience_builder\ExperienceBuilderConfigUpdater;

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
