<?php

/**
 * @file
 * Adds several entities with un-collapsed inputs.
 *
 * This is generated manually from the existing test before the rename.
 * See https://git.drupalcode.org/project/canvas/-/tree/9048ab846694553391145e1a4d4af7cbfe429758/tests/fixtures/update
 *
 * @see \Drupal\Tests\canvas\Functional\Update\CollapseComponentInputsUpdateTest
 */

use Drupal\Core\Database\Database;

$connection = Database::getConnection();

$connection->update('config')
  ->condition('name', 'field.field.node.article.field_canvas_demo')
  ->fields([
    'data' => 'a:17:{s:4:"uuid";s:36:"d983e893-9e02-4db9-8fb4-8c9a271f3054";s:8:"langcode";s:2:"en";s:6:"status";b:1;s:12:"dependencies";a:2:{s:6:"config";a:3:{i:0;s:43:"canvas.component.sdc.canvas_test_sdc.my-cta";i:1;s:36:"field.storage.node.field_canvas_demo";i:2;s:17:"node.type.article";}s:6:"module";a:2:{i:0;s:6:"canvas";i:1;s:4:"link";}}s:5:"_core";a:1:{s:19:"default_config_hash";s:43:"-OgfEyN2P1MjRZNf_euCKjkeDNDGlfhGcj1bRo4t4NY";}s:2:"id";s:30:"node.article.field_canvas_demo";s:10:"field_name";s:17:"field_canvas_demo";s:11:"entity_type";s:4:"node";s:6:"bundle";s:7:"article";s:5:"label";s:20:"🪄 Canvas Demo ✨";s:11:"description";s:0:"";s:8:"required";b:0;s:12:"translatable";b:0;s:13:"default_value";a:1:{i:0;a:4:{s:4:"uuid";s:36:"c28c3443-174c-4a83-a07a-8a071b133371";s:12:"component_id";s:26:"sdc.canvas_test_sdc.my-cta";s:17:"component_version";s:16:"53ed322c96bee384";s:6:"inputs";a:2:{s:4:"text";a:3:{s:10:"sourceType";s:24:"static:field_item:string";s:5:"value";s:19:"Step outside myself";s:10:"expression";s:20:"ℹ︎string␟value";}s:4:"href";a:4:{s:10:"sourceType";s:22:"static:field_item:link";s:5:"value";a:2:{s:3:"uri";s:19:"https://drupal.org/";s:7:"options";a:0:{}}s:10:"expression";s:16:"ℹ︎link␟url";s:18:"sourceTypeSettings";a:1:{s:8:"instance";a:1:{s:5:"title";i:0;}}}}}}s:22:"default_value_callback";s:0:"";s:8:"settings";a:0:{}s:10:"field_type";s:14:"component_tree";}',
  ])
  ->execute();

$connection->insert('config')
  ->fields([
    'collection',
    'name',
    'data',
  ])
  ->values([
    'collection' => '',
    'name' => 'canvas.page_region.stark.sidebar_first',
    'data' => 'a:8:{s:4:"uuid";s:36:"54053fb6-4e36-4603-93b9-d67ff7d38a5a";s:8:"langcode";s:2:"en";s:6:"status";b:1;s:12:"dependencies";a:3:{s:6:"config";a:1:{i:0;s:43:"canvas.component.sdc.canvas_test_sdc.my-cta";}s:6:"module";a:1:{i:0;s:4:"link";}s:5:"theme";a:1:{i:0;s:5:"stark";}}s:2:"id";s:19:"stark.sidebar_first";s:6:"region";s:13:"sidebar_first";s:5:"theme";s:5:"stark";s:14:"component_tree";a:1:{i:0;a:4:{s:4:"uuid";s:36:"c28c3443-174c-4a83-a07a-8a071b133371";s:12:"component_id";s:26:"sdc.canvas_test_sdc.my-cta";s:17:"component_version";s:16:"53ed322c96bee384";s:6:"inputs";a:2:{s:4:"text";a:3:{s:10:"sourceType";s:24:"static:field_item:string";s:5:"value";s:19:"Step outside myself";s:10:"expression";s:20:"ℹ︎string␟value";}s:4:"href";a:4:{s:10:"sourceType";s:22:"static:field_item:link";s:5:"value";a:2:{s:3:"uri";s:19:"https://drupal.org/";s:7:"options";a:0:{}}s:10:"expression";s:16:"ℹ︎link␟url";s:18:"sourceTypeSettings";a:1:{s:8:"instance";a:1:{s:5:"title";i:0;}}}}}}}',
  ])
  ->values([
    'collection' => '',
    'name' => 'core.entity_view_mode.node.reverse',
    'data' => 'a:9:{s:4:"uuid";s:36:"eec27e32-a2e4-4e3f-9049-d5283c647785";s:8:"langcode";s:2:"en";s:6:"status";b:1;s:12:"dependencies";a:1:{s:6:"module";a:1:{i:0;s:6:"canvas";}}s:2:"id";s:12:"node.reverse";s:5:"label";s:7:"Reverse";s:11:"description";N;s:16:"targetEntityType";s:4:"node";s:5:"cache";b:1;}',
  ])
  ->values([
    'collection' => '',
    'name' => 'canvas.content_template.node.article.reverse',
    'data' => 'a:10:{s:4:"uuid";s:36:"039bc7da-1610-4696-9b56-3796dc9df114";s:8:"langcode";s:2:"en";s:6:"status";b:1;s:12:"dependencies";a:2:{s:6:"config";a:3:{i:0;s:37:"core.entity_view_mode.article.reverse";i:1;s:43:"canvas.component.sdc.canvas_test_sdc.my-cta";i:2;s:51:"canvas.component.sdc.canvas_test_sdc.props-no-slots";}s:6:"module";a:1:{i:0;s:4:"link";}}s:2:"id";s:20:"node.article.reverse";s:22:"content_entity_type_id";s:4:"node";s:26:"content_entity_type_bundle";s:7:"article";s:29:"content_entity_type_view_mode";s:7:"reverse";s:14:"component_tree";a:2:{i:0;a:4:{s:4:"uuid";s:36:"c28c3443-174c-4a83-a07a-8a071b133371";s:12:"component_id";s:26:"sdc.canvas_test_sdc.my-cta";s:17:"component_version";s:16:"53ed322c96bee384";s:6:"inputs";a:2:{s:4:"text";a:3:{s:10:"sourceType";s:24:"static:field_item:string";s:5:"value";s:19:"Step outside myself";s:10:"expression";s:20:"ℹ︎string␟value";}s:4:"href";a:4:{s:10:"sourceType";s:22:"static:field_item:link";s:5:"value";a:2:{s:3:"uri";s:19:"https://drupal.org/";s:7:"options";a:0:{}}s:10:"expression";s:16:"ℹ︎link␟url";s:18:"sourceTypeSettings";a:1:{s:8:"instance";a:1:{s:5:"title";i:0;}}}}}i:1;a:4:{s:4:"uuid";s:36:"5f71027b-d9d3-4f3d-8990-a6502c0ba676";s:12:"component_id";s:34:"sdc.canvas_test_sdc.props-no-slots";s:17:"component_version";s:16:"95f4f1d5ee47663b";s:6:"inputs";a:1:{s:7:"heading";a:2:{s:10:"sourceType";s:7:"dynamic";s:10:"expression";s:58:"ℹ︎␜entity:canvas_page:canvas_page␝title␞␟value";}}}}s:13:"exposed_slots";a:0:{}}',
  ])
  ->values([
    'collection' => '',
    'name' => 'canvas.pattern.test_pattern',
    'data' => 'a:7:{s:4:"uuid";s:36:"ac2ba613-8e8d-41da-beee-29804a30e587";s:8:"langcode";s:2:"en";s:6:"status";b:1;s:12:"dependencies";a:2:{s:6:"config";a:1:{i:0;s:43:"canvas.component.sdc.canvas_test_sdc.my-cta";}s:6:"module";a:1:{i:0;s:4:"link";}}s:2:"id";s:12:"test_pattern";s:5:"label";s:12:"Test Pattern";s:14:"component_tree";a:1:{i:0;a:4:{s:4:"uuid";s:36:"c28c3443-174c-4a83-a07a-8a071b133371";s:12:"component_id";s:26:"sdc.canvas_test_sdc.my-cta";s:17:"component_version";s:16:"53ed322c96bee384";s:6:"inputs";a:2:{s:4:"text";a:3:{s:10:"sourceType";s:24:"static:field_item:string";s:5:"value";s:19:"Step outside myself";s:10:"expression";s:20:"ℹ︎string␟value";}s:4:"href";a:4:{s:10:"sourceType";s:22:"static:field_item:link";s:5:"value";a:2:{s:3:"uri";s:19:"https://drupal.org/";s:7:"options";a:0:{}}s:10:"expression";s:16:"ℹ︎link␟url";s:18:"sourceTypeSettings";a:1:{s:8:"instance";a:1:{s:5:"title";i:0;}}}}}}}',
  ])
  ->execute();
