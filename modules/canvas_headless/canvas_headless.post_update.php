<?php

declare(strict_types=1);

use Drupal\canvas\Entity\JavaScriptComponent;
use Drupal\canvas\Plugin\Canvas\ComponentSource\JsComponent;

/**
 * @file
 * Post-update hooks for Drupal Canvas Headless.
 */

/**
 * Migrates legacy headless frontend config to include component ownership.
 */
function canvas_headless_post_update_0001_assign_existing_external_components(): void {
  $settings = \Drupal::configFactory()->getEditable('canvas_headless.settings');
  $frontends = $settings->get('frontends');
  if (!\is_array($frontends) || $frontends === []) {
    return;
  }

  $component_ids = [];
  foreach (JavaScriptComponent::loadMultiple() as $component) {
    if (!$component instanceof JavaScriptComponent || !$component->isExternal()) {
      continue;
    }
    // Legacy sites have no per-frontend ownership record yet, so preserve the
    // pre-upgrade behavior by treating all existing external components as
    // visible from every legacy frontend until the next sync narrows ownership.
    $component_ids[] = JsComponent::SOURCE_PLUGIN_ID . '.' . $component->id();
  }
  $component_ids = array_values(array_unique($component_ids));

  $updated = FALSE;
  foreach ($frontends as &$frontend) {
    // Frontends that already have a component list were created or synchronized
    // under the new ownership model, so their explicit ownership must win.
    if (!\is_array($frontend) || array_key_exists('components', $frontend)) {
      continue;
    }
    $frontend['components'] = $component_ids;
    $updated = TRUE;
  }
  unset($frontend);

  if ($updated) {
    $settings->set('frontends', $frontends)->save();
  }
}

/**
 * Adds the publish webhook settings with their disabled defaults.
 */
function canvas_headless_post_update_0002_add_publish_webhook_settings(): void {
  $settings = \Drupal::configFactory()->getEditable('canvas_headless.settings');
  if ($settings->get('publish_webhook') === NULL) {
    $settings->set('publish_webhook', ['url' => ''])->save();
  }
}
