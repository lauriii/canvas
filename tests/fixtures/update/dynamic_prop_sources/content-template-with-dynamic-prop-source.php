<?php

/**
 * @file
 * Adds a content template with a DynamicPropSource-powered component instance.
 *
 * @see \Drupal\Tests\canvas\Functional\Update\ContentTemplateDynamicPropSourcesToEntityFieldPropSourcesUpdateTest
 */

// cspell:ignore centity dtitle

use Drupal\Core\Database\Database;

$connection = Database::getConnection();
$connection->insert('config')
  ->fields([
    'collection',
    'name',
    'data',
  ])
  ->values([
    'collection' => '',
    'name' => 'canvas.content_template.node.page.full',
    'data' => 'a:10:{s:4:"uuid";s:36:"59f2a134-8def-4da6-b2fd-e35c3a015d51";s:8:"langcode";s:2:"en";s:6:"status";b:1;s:12:"dependencies";a:2:{s:6:"config";a:3:{i:0;s:44:"canvas.component.sdc.canvas_test_sdc.heading";i:1;s:31:"core.entity_view_mode.node.full";i:2;s:14:"node.type.page";}s:6:"module";a:2:{i:0;s:4:"node";i:1;s:7:"options";}}s:2:"id";s:14:"node.page.full";s:22:"content_entity_type_id";s:4:"node";s:26:"content_entity_type_bundle";s:4:"page";s:29:"content_entity_type_view_mode";s:4:"full";s:14:"component_tree";a:1:{i:0;a:4:{s:4:"uuid";s:36:"ee301ace-62c0-4a16-9363-d29bfd9239df";s:12:"component_id";s:27:"sdc.canvas_test_sdc.heading";s:17:"component_version";s:16:"8c01a2bdb897a810";s:6:"inputs";s:144:"{"text":{"sourceType":"dynamic","expression":"\u2139\ufe0e\u241centity:node:page\u241dtitle\u241e\u241fvalue"},"style":"primary","element":"h1"}";}}s:13:"exposed_slots";a:0:{}}',
  ])
  ->execute();
