<?php

/**
 * @file
 * This script is NOT used in the tests.
 *
 * This script is expected to run with e.g. the alpha1 codebase, not
 * the current codebase.
 *
 * This script just generates the state we want to put in top of
 * a bare dump to help generating our different test cases.
 *
 * The actual test just runs a fixture script including SQL commands
 * on top of the bare dump.
 * This is included in the repo as a reference for others, plus in
 * case we need to regenerate some test scenarios for some reason.
 *
 * After we run this on alpha1, we need to export the dump, and find
 * the relevant inserts to these config entities, and put those in our
 * actual fixture script. See `tracking-required-fixture.php`.
 */

use Drupal\canvas\Entity\JavaScriptComponent;
use Drupal\canvas\Plugin\Canvas\ComponentSource\JsComponent;
use Drupal\Tests\canvas\Functional\Update\ComponentTrackingRequiredPropsUpdateTest;

$some_js = 'console.log("hey");';
foreach (ComponentTrackingRequiredPropsUpdateTest::TEST_CASES as $machine_name => $title) {
  $props = [
    'title' => [
      'type' => 'string',
      'title' => 'Title',
      'examples' => ['Title'],
    ],
  ];

  $js_component = JavaScriptComponent::create([
    'machineName' => $machine_name,
    'name' => $title,
    'status' => FALSE,
    'props' => $props,
    'required' => ['title'],
    'slots' => [],
    'js' => [
      'original' => $some_js,
      'compiled' => $some_js,
    ],
    'css' => [
      'original' => '',
      'compiled' => '',
    ],
    'dataDependencies' => [],
  ]);
  $js_component->save();

  $component = JsComponent::createConfigEntity($js_component);
  if (str_contains($machine_name, 'active_has_required__past_empty') || str_contains($machine_name, 'past_has_required')) {
    $settings = $component->getSettings();
    $settings['prop_field_definitions']['title']['required'] = TRUE;
    $component->setSettings($settings);
  }
  // We might be adding something unknown to config schema, so ensure we trust data
  // to skip validation.
  $component->trustData()->save();

  if (str_contains($machine_name, 'past_has')) {
    $props['title']['examples'] = ['Trigger a new version'];
    $js_component->setProps($props);
    $js_component->trustData()->save();

    $updated_component = JsComponent::updateConfigEntity($js_component, $component);
    \assert(count($updated_component->getVersions()) === 2);
    if (str_contains($machine_name, 'active_has_required__past_has')) {
      \assert($updated_component->isLoadedVersionActiveVersion());
      $settings = $updated_component->getSettings();
      $settings['prop_field_definitions']['title']['required'] = TRUE;
      $updated_component->setSettings($settings);
    }
    $updated_component->trustData()->save();
  }

}
