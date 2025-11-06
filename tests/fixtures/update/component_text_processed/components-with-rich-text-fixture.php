<?php

/**
 * @file
 * Adds Components with rich text props.
 *
 * @see generate-components-with-rich-text.php
 * @see \Drupal\Tests\canvas\Functional\Update\ComponentWithRichTextShouldUseProcessedUpdateTest
 */

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
    'name' => 'canvas.js_component.component_with_rich_text',
    // Because of the embedded js code, it's better to have this as a php
    // serialized string.
    'data' => "a:12:{s:4:\"uuid\";s:36:\"24eadc7c-434b-4fdc-aad3-45c134e68f13\";s:8:\"langcode\";s:2:\"en\";s:6:\"status\";b:1;s:12:\"dependencies\";a:0:{}s:11:\"machineName\";s:24:\"component_with_rich_text\";s:4:\"name\";s:24:\"Component with Rich Text\";s:8:\"required\";a:1:{i:0;s:4:\"text\";}s:5:\"props\";a:1:{s:4:\"text\";a:5:{s:5:\"title\";s:4:\"Text\";s:4:\"type\";s:6:\"string\";s:8:\"examples\";a:1:{i:0;s:18:\"This is an example\";}s:16:\"contentMediaType\";s:9:\"text/html\";s:20:\"x-formatting-context\";s:5:\"block\";}}s:5:\"slots\";a:0:{}s:2:\"js\";a:2:{s:8:\"original\";s:289:\"// See https://project.pages.drupalcode.org/canvas/ for documentation on how to build a code component\nimport FormattedText from '@/lib/FormattedText';\n\nconst Component = ({ text }) => {\n  return (\n    <FormattedText>\n      { text }\n    </FormattedText>\n  );\n};\n\nexport default Component;\n\";s:8:\"compiled\";s:340:\"// See https://project.pages.drupalcode.org/canvas/ for documentation on how to build a code component\nimport { jsx as _jsx } from \"react/jsx-runtime\";\nimport FormattedText from '@/lib/FormattedText';\nconst Component = ({ text })=>{\n    return /*#__PURE__*/ _jsx(FormattedText, {\n        children: text\n    });\n};\nexport default Component;\n\";}s:3:\"css\";a:2:{s:8:\"original\";s:0:\"\";s:8:\"compiled\";s:0:\"\";}s:16:\"dataDependencies\";a:0:{}}",
  ])
  // The banner SDC component didn't exist at the time, so let's create it here.
  ->values([
    'collection' => '',
    'name' => 'canvas.component.sdc.canvas_test_sdc.banner',
    'data' => 'a:12:{s:4:"uuid";s:36:"610ad2e0-920e-4ed2-bc2f-71e0a2705217";s:8:"langcode";s:2:"en";s:6:"status";b:1;s:12:"dependencies";a:2:{s:6:"config";a:4:{i:0;s:41:"field.field.media.image.field_media_image";i:1;s:31:"filter.format.canvas_html_block";i:2;s:37:"image.style.canvas_parametrized_width";i:3;s:16:"media.type.image";}s:6:"module";a:5:{i:0;s:15:"canvas_test_sdc";i:1;s:4:"file";i:2;s:5:"media";i:3;s:13:"media_library";i:4;s:4:"text";}}s:14:"active_version";s:16:"fbe4167cd14f85a1";s:20:"versioned_properties";a:1:{s:6:"active";a:2:{s:8:"settings";a:1:{s:22:"prop_field_definitions";a:3:{s:7:"heading";a:6:{s:10:"field_type";s:6:"string";s:22:"field_storage_settings";a:0:{}s:23:"field_instance_settings";a:0:{}s:12:"field_widget";s:16:"string_textfield";s:13:"default_value";a:1:{i:0;a:1:{s:5:"value";s:15:"My banner title";}}s:10:"expression";s:20:"ℹ︎string␟value";}s:5:"image";a:6:{s:10:"field_type";s:16:"entity_reference";s:22:"field_storage_settings";a:1:{s:11:"target_type";s:5:"media";}s:23:"field_instance_settings";a:2:{s:7:"handler";s:13:"default:media";s:16:"handler_settings";a:1:{s:14:"target_bundles";a:1:{s:5:"image";s:5:"image";}}}s:12:"field_widget";s:20:"media_library_widget";s:13:"default_value";a:0:{}s:10:"expression";s:322:"ℹ︎entity_reference␟{src↝entity␜␜entity:media:image␝field_media_image␞␟src_with_alternate_widths,alt↝entity␜␜entity:media:image␝field_media_image␞␟alt,width↝entity␜␜entity:media:image␝field_media_image␞␟width,height↝entity␜␜entity:media:image␝field_media_image␞␟height}";}s:4:"text";a:6:{s:10:"field_type";s:9:"text_long";s:22:"field_storage_settings";a:0:{}s:23:"field_instance_settings";a:1:{s:15:"allowed_formats";a:1:{i:0;s:17:"canvas_html_block";}}s:12:"field_widget";s:13:"text_textarea";s:13:"default_value";a:1:{i:0;a:1:{s:5:"value";s:166:"<p>In a curious work, published in <em>Paris</em> in 1863 by <strong>Delaville Dedreux</strong>, there is a suggestion for reaching the North Pole by an aerostat.</p>";}}s:10:"expression";s:23:"ℹ︎text_long␟value";}}}s:17:"fallback_metadata";a:1:{s:16:"slot_definitions";a:0:{}}}}s:5:"label";s:6:"Banner";s:2:"id";s:26:"sdc.canvas_test_sdc.banner";s:8:"provider";s:15:"canvas_test_sdc";s:6:"source";s:3:"sdc";s:15:"source_local_id";s:22:"canvas_test_sdc:banner";s:8:"category";s:5:"Other";}',
  ])
  ->values([
    'collection' => '',
    'name' => 'canvas.component.js.component_with_rich_text',
    // Because of the embedded js code, it's better to have this as a php
    // serialized string.
    'data' => 'a:12:{s:4:"uuid";s:36:"a565a66e-6c84-4dfa-9ec6-71f4a4463f09";s:8:"langcode";s:2:"en";s:6:"status";b:1;s:12:"dependencies";a:2:{s:6:"config";a:2:{i:0;s:44:"canvas.js_component.component_with_rich_text";i:1;s:31:"filter.format.canvas_html_block";}s:6:"module";a:1:{i:0;s:4:"text";}}s:14:"active_version";s:16:"467583e3f9bdfa95";s:20:"versioned_properties";a:1:{s:6:"active";a:2:{s:8:"settings";a:1:{s:22:"prop_field_definitions";a:1:{s:4:"text";a:6:{s:10:"field_type";s:9:"text_long";s:22:"field_storage_settings";a:0:{}s:23:"field_instance_settings";a:1:{s:15:"allowed_formats";a:1:{i:0;s:17:"canvas_html_block";}}s:12:"field_widget";s:13:"text_textarea";s:13:"default_value";a:1:{i:0;a:1:{s:5:"value";s:18:"This is an example";}}s:10:"expression";s:23:"ℹ︎text_long␟value";}}}s:17:"fallback_metadata";a:1:{s:16:"slot_definitions";a:0:{}}}}s:5:"label";s:24:"Component with Rich Text";s:2:"id";s:27:"js.component_with_rich_text";s:8:"provider";N;s:6:"source";s:2:"js";s:15:"source_local_id";s:24:"component_with_rich_text";s:8:"category";s:5:"@todo";}',
  ])
  ->execute();
