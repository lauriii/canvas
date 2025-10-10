<?php

/**
 * @file
 * Adds Components with multiple versions tracking/not tracking the "required" flag.
 *
 * @see generate-components-with-multiple-versions.php
 * @see \Drupal\Tests\canvas\Functional\Update\ComponentTrackingRequiredPropsUpdateTest
 */

// cspell:ignore hasnot

use Drupal\Core\Database\Database;

$connection = Database::getConnection();

// @todo Fix this for readability.

$connection->insert('config')
  ->fields([
    'collection',
    'name',
    'data',
  ])
  ->values([
    'collection' => '',
    'name' => 'canvas.js_component.case_a__active_hasnot_required__past_hasnot_required',
    'data' => 'a:12:{s:4:"uuid";s:36:"43897d5c-fc2d-434c-bec0-d6dfaea0772f";s:8:"langcode";s:2:"en";s:6:"status";b:0;s:12:"dependencies";a:0:{}s:11:"machineName";s:52:"case_a__active_hasnot_required__past_hasnot_required";s:4:"name";s:50:">1 version, active NOT required, past NOT required";s:5:"props";a:1:{s:5:"title";a:3:{s:4:"type";s:6:"string";s:5:"title";s:5:"Title";s:8:"examples";a:1:{i:0;s:21:"Trigger a new version";}}}s:8:"required";a:1:{i:0;s:5:"title";}s:5:"slots";a:0:{}s:2:"js";a:2:{s:8:"original";s:19:"console.log("hey");";s:8:"compiled";s:19:"console.log("hey");";}s:3:"css";a:2:{s:8:"original";s:0:"";s:8:"compiled";s:0:"";}s:16:"dataDependencies";a:0:{}}',
  ])
  ->values([
    'collection' => '',
    'name' => 'canvas.js_component.case_b__active_hasnot_required__past_empty',
    'data' => 'a:12:{s:4:"uuid";s:36:"83c9405b-1ead-401e-9e37-fb416154e980";s:8:"langcode";s:2:"en";s:6:"status";b:0;s:12:"dependencies";a:0:{}s:11:"machineName";s:42:"case_b__active_hasnot_required__past_empty";s:4:"name";s:30:"1 version, active NOT required";s:8:"required";a:1:{i:0;s:5:"title";}s:5:"props";a:1:{s:5:"title";a:3:{s:5:"title";s:5:"Title";s:4:"type";s:6:"string";s:8:"examples";a:1:{i:0;s:5:"Title";}}}s:5:"slots";a:0:{}s:2:"js";a:2:{s:8:"original";s:19:"console.log("hey");";s:8:"compiled";s:19:"console.log("hey");";}s:3:"css";a:2:{s:8:"original";s:0:"";s:8:"compiled";s:0:"";}s:16:"dataDependencies";a:0:{}}',
  ])
  ->values([
    'collection' => '',
    'name' => 'canvas.js_component.case_c__active_has_required__past_hasnot_required',
    'data' => 'a:12:{s:4:"uuid";s:36:"335cf645-7074-4cc7-b4ff-214f3d65eba2";s:8:"langcode";s:2:"en";s:6:"status";b:0;s:12:"dependencies";a:0:{}s:11:"machineName";s:49:"case_c__active_has_required__past_hasnot_required";s:4:"name";s:46:">1 version, active required, past NOT required";s:5:"props";a:1:{s:5:"title";a:3:{s:4:"type";s:6:"string";s:5:"title";s:5:"Title";s:8:"examples";a:1:{i:0;s:21:"Trigger a new version";}}}s:8:"required";a:1:{i:0;s:5:"title";}s:5:"slots";a:0:{}s:2:"js";a:2:{s:8:"original";s:19:"console.log("hey");";s:8:"compiled";s:19:"console.log("hey");";}s:3:"css";a:2:{s:8:"original";s:0:"";s:8:"compiled";s:0:"";}s:16:"dataDependencies";a:0:{}}',
  ])
  ->values([
    'collection' => '',
    'name' => 'canvas.js_component.case_d__active_has_required__past_empty',
    'data' => 'a:12:{s:4:"uuid";s:36:"c3666cc9-397c-4223-b4d1-6ea0d2397782";s:8:"langcode";s:2:"en";s:6:"status";b:0;s:12:"dependencies";a:0:{}s:11:"machineName";s:39:"case_d__active_has_required__past_empty";s:4:"name";s:26:"1 version, active required";s:8:"required";a:1:{i:0;s:5:"title";}s:5:"props";a:1:{s:5:"title";a:3:{s:5:"title";s:5:"Title";s:4:"type";s:6:"string";s:8:"examples";a:1:{i:0;s:5:"Title";}}}s:5:"slots";a:0:{}s:2:"js";a:2:{s:8:"original";s:19:"console.log("hey");";s:8:"compiled";s:19:"console.log("hey");";}s:3:"css";a:2:{s:8:"original";s:0:"";s:8:"compiled";s:0:"";}s:16:"dataDependencies";a:0:{}}',
  ])
  ->values([
    'collection' => '',
    'name' => 'canvas.js_component.case_e__active_has_required__past_has_required',
    'data' => 'a:12:{s:4:"uuid";s:36:"1f205ca5-f554-47eb-80bd-511e1859f9da";s:8:"langcode";s:2:"en";s:6:"status";b:0;s:12:"dependencies";a:0:{}s:11:"machineName";s:46:"case_e__active_has_required__past_has_required";s:4:"name";s:42:">1 version, active required, past required";s:5:"props";a:1:{s:5:"title";a:3:{s:4:"type";s:6:"string";s:5:"title";s:5:"Title";s:8:"examples";a:1:{i:0;s:21:"Trigger a new version";}}}s:8:"required";a:1:{i:0;s:5:"title";}s:5:"slots";a:0:{}s:2:"js";a:2:{s:8:"original";s:19:"console.log("hey");";s:8:"compiled";s:19:"console.log("hey");";}s:3:"css";a:2:{s:8:"original";s:0:"";s:8:"compiled";s:0:"";}s:16:"dataDependencies";a:0:{}}',
  ])
  ->values([
    'collection' => '',
    'name' => 'canvas.component.js.case_a__active_hasnot_required__past_hasnot_required',
    'data' => 'a:12:{s:4:"uuid";s:36:"7bbc168a-9ad3-4999-8ef4-dd355f6e213d";s:8:"langcode";s:2:"en";s:6:"status";b:0;s:12:"dependencies";a:1:{s:6:"config";a:1:{i:0;s:72:"canvas.js_component.case_a__active_hasnot_required__past_hasnot_required";}}s:5:"label";s:50:">1 version, active NOT required, past NOT required";s:2:"id";s:55:"js.case_a__active_hasnot_required__past_hasnot_required";s:6:"source";s:2:"js";s:15:"source_local_id";s:52:"case_a__active_hasnot_required__past_hasnot_required";s:8:"provider";N;s:8:"category";s:5:"@todo";s:14:"active_version";s:16:"3832b735acd1c5ad";s:20:"versioned_properties";a:2:{s:6:"active";a:2:{s:8:"settings";a:1:{s:22:"prop_field_definitions";a:1:{s:5:"title";a:6:{s:10:"field_type";s:6:"string";s:12:"field_widget";s:16:"string_textfield";s:10:"expression";s:20:"ℹ︎string␟value";s:13:"default_value";a:1:{i:0;a:1:{s:5:"value";s:21:"Trigger a new version";}}s:22:"field_storage_settings";a:0:{}s:23:"field_instance_settings";a:0:{}}}}s:17:"fallback_metadata";a:1:{s:16:"slot_definitions";a:0:{}}}s:16:"7929b726e293a593";a:2:{s:8:"settings";a:1:{s:22:"prop_field_definitions";a:1:{s:5:"title";a:6:{s:10:"field_type";s:6:"string";s:12:"field_widget";s:16:"string_textfield";s:10:"expression";s:20:"ℹ︎string␟value";s:13:"default_value";a:1:{i:0;a:1:{s:5:"value";s:5:"Title";}}s:22:"field_storage_settings";a:0:{}s:23:"field_instance_settings";a:0:{}}}}s:17:"fallback_metadata";a:1:{s:16:"slot_definitions";a:0:{}}}}}',
  ])
  ->values([
    'collection' => '',
    'name' => 'canvas.component.js.case_b__active_hasnot_required__past_empty',
    'data' => 'a:12:{s:4:"uuid";s:36:"fdbc6568-89b1-4c28-b94c-70ceb29cd93d";s:8:"langcode";s:2:"en";s:6:"status";b:0;s:12:"dependencies";a:1:{s:6:"config";a:1:{i:0;s:62:"canvas.js_component.case_b__active_hasnot_required__past_empty";}}s:5:"label";s:30:"1 version, active NOT required";s:2:"id";s:45:"js.case_b__active_hasnot_required__past_empty";s:6:"source";s:2:"js";s:15:"source_local_id";s:42:"case_b__active_hasnot_required__past_empty";s:8:"provider";N;s:8:"category";s:5:"@todo";s:14:"active_version";s:16:"7929b726e293a593";s:20:"versioned_properties";a:1:{s:6:"active";a:2:{s:8:"settings";a:1:{s:22:"prop_field_definitions";a:1:{s:5:"title";a:6:{s:10:"field_type";s:6:"string";s:12:"field_widget";s:16:"string_textfield";s:10:"expression";s:20:"ℹ︎string␟value";s:13:"default_value";a:1:{i:0;a:1:{s:5:"value";s:5:"Title";}}s:22:"field_storage_settings";a:0:{}s:23:"field_instance_settings";a:0:{}}}}s:17:"fallback_metadata";a:1:{s:16:"slot_definitions";a:0:{}}}}}',
  ])
  ->values([
    'collection' => '',
    'name' => 'canvas.component.js.case_c__active_has_required__past_hasnot_required',
    'data' => 'a:12:{s:4:"uuid";s:36:"5c74f982-ffa3-494c-af83-1211e24f0561";s:8:"langcode";s:2:"en";s:6:"status";b:0;s:12:"dependencies";a:1:{s:6:"config";a:1:{i:0;s:69:"canvas.js_component.case_c__active_has_required__past_hasnot_required";}}s:5:"label";s:46:">1 version, active required, past NOT required";s:2:"id";s:52:"js.case_c__active_has_required__past_hasnot_required";s:6:"source";s:2:"js";s:15:"source_local_id";s:49:"case_c__active_has_required__past_hasnot_required";s:8:"provider";N;s:8:"category";s:5:"@todo";s:14:"active_version";s:16:"3832b735acd1c5ad";s:20:"versioned_properties";a:2:{s:6:"active";a:2:{s:8:"settings";a:1:{s:22:"prop_field_definitions";a:1:{s:5:"title";a:7:{s:10:"field_type";s:6:"string";s:12:"field_widget";s:16:"string_textfield";s:10:"expression";s:20:"ℹ︎string␟value";s:13:"default_value";a:1:{i:0;a:1:{s:5:"value";s:21:"Trigger a new version";}}s:22:"field_storage_settings";a:0:{}s:23:"field_instance_settings";a:0:{}s:8:"required";b:1;}}}s:17:"fallback_metadata";a:1:{s:16:"slot_definitions";a:0:{}}}s:16:"7929b726e293a593";a:2:{s:8:"settings";a:1:{s:22:"prop_field_definitions";a:1:{s:5:"title";a:6:{s:10:"field_type";s:6:"string";s:12:"field_widget";s:16:"string_textfield";s:10:"expression";s:20:"ℹ︎string␟value";s:13:"default_value";a:1:{i:0;a:1:{s:5:"value";s:5:"Title";}}s:22:"field_storage_settings";a:0:{}s:23:"field_instance_settings";a:0:{}}}}s:17:"fallback_metadata";a:1:{s:16:"slot_definitions";a:0:{}}}}}',
  ])
  ->values([
    'collection' => '',
    'name' => 'canvas.component.js.case_d__active_has_required__past_empty',
    'data' => 'a:12:{s:4:"uuid";s:36:"b56bb8a2-154d-44db-a461-83587d9153d5";s:8:"langcode";s:2:"en";s:6:"status";b:0;s:12:"dependencies";a:1:{s:6:"config";a:1:{i:0;s:59:"canvas.js_component.case_d__active_has_required__past_empty";}}s:5:"label";s:26:"1 version, active required";s:2:"id";s:42:"js.case_d__active_has_required__past_empty";s:6:"source";s:2:"js";s:15:"source_local_id";s:39:"case_d__active_has_required__past_empty";s:8:"provider";N;s:8:"category";s:5:"@todo";s:14:"active_version";s:16:"7929b726e293a593";s:20:"versioned_properties";a:1:{s:6:"active";a:2:{s:8:"settings";a:1:{s:22:"prop_field_definitions";a:1:{s:5:"title";a:7:{s:10:"field_type";s:6:"string";s:12:"field_widget";s:16:"string_textfield";s:10:"expression";s:20:"ℹ︎string␟value";s:13:"default_value";a:1:{i:0;a:1:{s:5:"value";s:5:"Title";}}s:22:"field_storage_settings";a:0:{}s:23:"field_instance_settings";a:0:{}s:8:"required";b:1;}}}s:17:"fallback_metadata";a:1:{s:16:"slot_definitions";a:0:{}}}}}',
  ])
  ->values([
    'collection' => '',
    'name' => 'canvas.component.js.case_e__active_has_required__past_has_required',
    'data' => 'a:12:{s:4:"uuid";s:36:"e2c89305-4010-4a65-bb4d-c93692983679";s:8:"langcode";s:2:"en";s:6:"status";b:0;s:12:"dependencies";a:1:{s:6:"config";a:1:{i:0;s:66:"canvas.js_component.case_e__active_has_required__past_has_required";}}s:5:"label";s:42:">1 version, active required, past required";s:2:"id";s:49:"js.case_e__active_has_required__past_has_required";s:6:"source";s:2:"js";s:15:"source_local_id";s:46:"case_e__active_has_required__past_has_required";s:8:"provider";N;s:8:"category";s:5:"@todo";s:14:"active_version";s:16:"3832b735acd1c5ad";s:20:"versioned_properties";a:2:{s:6:"active";a:2:{s:8:"settings";a:1:{s:22:"prop_field_definitions";a:1:{s:5:"title";a:7:{s:10:"field_type";s:6:"string";s:12:"field_widget";s:16:"string_textfield";s:10:"expression";s:20:"ℹ︎string␟value";s:13:"default_value";a:1:{i:0;a:1:{s:5:"value";s:21:"Trigger a new version";}}s:22:"field_storage_settings";a:0:{}s:23:"field_instance_settings";a:0:{}s:8:"required";b:1;}}}s:17:"fallback_metadata";a:1:{s:16:"slot_definitions";a:0:{}}}s:16:"7929b726e293a593";a:2:{s:8:"settings";a:1:{s:22:"prop_field_definitions";a:1:{s:5:"title";a:7:{s:10:"field_type";s:6:"string";s:12:"field_widget";s:16:"string_textfield";s:10:"expression";s:20:"ℹ︎string␟value";s:13:"default_value";a:1:{i:0;a:1:{s:5:"value";s:5:"Title";}}s:22:"field_storage_settings";a:0:{}s:23:"field_instance_settings";a:0:{}s:8:"required";b:1;}}}s:17:"fallback_metadata";a:1:{s:16:"slot_definitions";a:0:{}}}}}',
  ])
  ->execute();
