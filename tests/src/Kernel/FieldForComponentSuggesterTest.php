<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Kernel;

use Drupal\Core\Entity\TypedData\EntityDataDefinition;
use Drupal\experience_builder\ShapeMatcher\FieldForComponentSuggester;
use Drupal\experience_builder\Plugin\Adapter\AdapterInterface;
use Drupal\experience_builder\PropExpressions\StructuredData\StructuredDataPropExpressionInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\experience_builder\Traits\ContribStrictConfigSchemaTestTrait;

/**
 * @coversClass \Drupal\experience_builder\ShapeMatcher\FieldForComponentSuggester
 * @group experience_builder
 */
class FieldForComponentSuggesterTest extends KernelTestBase {

  use ContribStrictConfigSchemaTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    // The two only modules Drupal truly requires.
    'system',
    'user',
    // The module being tested.
    'experience_builder',
    // The dependent modules.
    'sdc',
    // The module providing the sample SDC to test all JSON schema types.
    'sdc_test_all_props',
    // All other core modules providing field types.
    'comment',
    'datetime',
    'datetime_range',
    'file',
    'image',
    'link',
    'options',
    'path',
    'telephone',
    'text',
    // Create sample configurable fields on the `node` entity type.
    'node',
    'field',
    // Modules that field type-providing modules depend on.
    'filter',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('node');
    $this->installEntitySchema('field_storage_config');
    $this->installEntitySchema('field_config');
    // Create a "Foo" node type.
    NodeType::create([
      'name' => 'Foo',
      'type' => 'foo',
    ])->save();
    // Create a "silly image" field on the "Foo" node type.
    FieldStorageConfig::create([
      'entity_type' => 'node',
      'field_name' => 'field_silly_image',
      'type' => 'image',
    ])->save();
    FieldConfig::create([
      'entity_type' => 'node',
      'field_name' => 'field_silly_image',
      'bundle' => 'foo',
      'required' => TRUE,
    ])->save();
    // Create a "event duration" field on the "Foo" node type.
    FieldStorageConfig::create([
      'entity_type' => 'node',
      'field_name' => 'field_event_duration',
      'type' => 'daterange',
    ])->save();
    FieldConfig::create([
      'entity_type' => 'node',
      'field_name' => 'field_event_duration',
      'bundle' => 'foo',
      'required' => TRUE,
    ])->save();
  }

  /**
   * @param array<string, array{'required': bool, 'instances': array<string, string>, 'adapters': array<string, string>}> $expected
   *
   * @dataProvider provider
   */
  public function test(string $component_plugin_id, ?string $data_type_context, array $expected): void {
    $suggestions = $this->container->get(FieldForComponentSuggester::class)
      ->suggest(
        $component_plugin_id,
        $data_type_context ? EntityDataDefinition::createFromDataType($data_type_context) : NULL,
      );

    // All expectations that are present must be correct.
    foreach (array_keys($expected) as $prop_name) {
      $this->assertSame(
        $expected[$prop_name],
        [
          'required' => $suggestions[$prop_name]['required'],
          'instances' => array_map(fn (StructuredDataPropExpressionInterface $e): string => (string) $e, $suggestions[$prop_name]['instances']),
          'adapters' => array_map(fn (AdapterInterface $a): string => $a->getPluginId(), $suggestions[$prop_name]['adapters']),
        ],
        "Unexpected prop source suggestion for $prop_name"
      );
    }

    // Finally, the set of expectations must be complete.
    $this->assertSame(array_keys($expected), array_keys($suggestions));
  }

  public static function provider(): \Generator {
    yield 'the image component' => [
      'experience_builder:image',
      'entity:node:foo',
      [
        '⿲experience_builder:image␟image' => [
          'required' => TRUE,
          'instances' => [
            "This Foo's field_silly_image" => 'ℹ︎␜entity:node:foo␝field_silly_image␞␟{src↝entity␜␜entity:file␝uri␞␟url,alt↠alt,width↠width,height↠height}',
          ],
          'adapters' => [
            'Apply image style' => 'image_apply_style',
            'Make relative image URL absolute' => 'image_url_rel_to_abs',
          ],
        ],
      ],
    ];

    yield 'the image component — free of context' => [
      'experience_builder:image',
      NULL,
      [
        '⿲experience_builder:image␟image' => [
          'required' => TRUE,
          'instances' => [],
          'adapters' => [
            'Apply image style' => 'image_apply_style',
            'Make relative image URL absolute' => 'image_url_rel_to_abs',
          ],
        ],
      ],
    ];

    yield 'the "ALL PROPS" test component' => [
      'sdc_test_all_props:all-props',
      'entity:node:foo',
      [
        '⿲sdc_test_all_props:all-props␟test_bool' => [
          'required' => FALSE,
          'instances' => [
            "This Foo's Default translation" => 'ℹ︎␜entity:node:foo␝default_langcode␞␟value',
            "This Foo's field_silly_image" => 'ℹ︎␜entity:node:foo␝field_silly_image␞␟entity␜␜entity:file␝status␞␟value',
            "This Foo's Promoted to front page" => 'ℹ︎␜entity:node:foo␝promote␞␟value',
            "This Foo's Default revision" => 'ℹ︎␜entity:node:foo␝revision_default␞␟value',
            "This Foo's Revision translation affected" => 'ℹ︎␜entity:node:foo␝revision_translation_affected␞␟value',
            "This Foo's Revision user" => 'ℹ︎␜entity:node:foo␝revision_uid␞␟entity␜␜entity:user␝status␞␟value',
            "This Foo's Published" => 'ℹ︎␜entity:node:foo␝status␞␟value',
            "This Foo's Sticky at top of lists" => 'ℹ︎␜entity:node:foo␝sticky␞␟value',
            "This Foo's Authored by" => 'ℹ︎␜entity:node:foo␝uid␞␟entity␜␜entity:user␝status␞␟value',
          ],
          'adapters' => [],
        ],
        '⿲sdc_test_all_props:all-props␟test_string' => [
          'required' => FALSE,
          'instances' => [
            "This Foo's field_silly_image" => 'ℹ︎␜entity:node:foo␝field_silly_image␞␟title',
            "This Foo's Revision log message" => 'ℹ︎␜entity:node:foo␝revision_log␞␟value',
            "This Foo's Title" => 'ℹ︎␜entity:node:foo␝title␞␟value',
          ],
          'adapters' => [],
        ],
        '⿲sdc_test_all_props:all-props␟test_string_multiline' => [
          'required' => FALSE,
          'instances' => [
            "This Foo's Revision log message" => 'ℹ︎␜entity:node:foo␝revision_log␞␟value',
          ],
          'adapters' => [],
        ],
        '⿲sdc_test_all_props:all-props␟test_REQUIRED_string' => [
          'required' => TRUE,
          'instances' => [
            "This Foo's Title" => 'ℹ︎␜entity:node:foo␝title␞␟value',
          ],
          'adapters' => [],
        ],
        '⿲sdc_test_all_props:all-props␟test_string_enum' => [
          'required' => FALSE,
          'instances' => [],
          'adapters' => [],
        ],
        '⿲sdc_test_all_props:all-props␟test_integer_enum' => [
          'required' => FALSE,
          'instances' => [],
          'adapters' => [],
        ],
        '⿲sdc_test_all_props:all-props␟test_string_format_date_time' => [
          'required' => FALSE,
          'instances' => [
            "This Foo's field_event_duration" => 'ℹ︎␜entity:node:foo␝field_event_duration␞␟value',
          ],
          'adapters' => [],
        ],
        '⿲sdc_test_all_props:all-props␟test_string_format_date' => [
          'required' => FALSE,
          'instances' => [
            "This Foo's field_event_duration" => 'ℹ︎␜entity:node:foo␝field_event_duration␞␟value',
          ],
          'adapters' => [
            'UNIX timestamp to date' => 'unix_to_date',
          ],
        ],
        '⿲sdc_test_all_props:all-props␟test_string_format_time' => [
          'required' => FALSE,
          'instances' => [],
          'adapters' => [],
        ],
        '⿲sdc_test_all_props:all-props␟test_string_format_duration' => [
          'required' => FALSE,
          'instances' => [],
          'adapters' => [],
        ],
        '⿲sdc_test_all_props:all-props␟test_string_format_email' => [
          'required' => FALSE,
          'instances' => [
            "This Foo's Revision user" => 'ℹ︎␜entity:node:foo␝revision_uid␞␟entity␜␜entity:user␝mail␞␟value',
            "This Foo's Authored by" => 'ℹ︎␜entity:node:foo␝uid␞␟entity␜␜entity:user␝mail␞␟value',
          ],
          'adapters' => [],
        ],
        '⿲sdc_test_all_props:all-props␟test_string_format_idn_email' => [
          'required' => FALSE,
          'instances' => [
            "This Foo's Revision user" => 'ℹ︎␜entity:node:foo␝revision_uid␞␟entity␜␜entity:user␝mail␞␟value',
            "This Foo's Authored by" => 'ℹ︎␜entity:node:foo␝uid␞␟entity␜␜entity:user␝mail␞␟value',
          ],
          'adapters' => [],
        ],
        '⿲sdc_test_all_props:all-props␟test_string_format_hostname' => [
          'required' => FALSE,
          'instances' => [],
          'adapters' => [],
        ],
        '⿲sdc_test_all_props:all-props␟test_string_format_idn_hostname' => [
          'required' => FALSE,
          'instances' => [],
          'adapters' => [],
        ],
        '⿲sdc_test_all_props:all-props␟test_string_format_ipv4' => [
          'required' => FALSE,
          'instances' => [],
          'adapters' => [],
        ],
        '⿲sdc_test_all_props:all-props␟test_string_format_ipv6' => [
          'required' => FALSE,
          'instances' => [],
          'adapters' => [],
        ],
        '⿲sdc_test_all_props:all-props␟test_string_format_uuid' => [
          'required' => FALSE,
          'instances' => [
            "This Foo's field_silly_image" => 'ℹ︎␜entity:node:foo␝field_silly_image␞␟entity␜␜entity:file␝uuid␞␟value',
            "This Foo's Revision user" => 'ℹ︎␜entity:node:foo␝revision_uid␞␟entity␜␜entity:user␝uuid␞␟value',
            "This Foo's Authored by" => 'ℹ︎␜entity:node:foo␝uid␞␟entity␜␜entity:user␝uuid␞␟value',
            "This Foo's UUID" => 'ℹ︎␜entity:node:foo␝uuid␞␟value',
          ],
          'adapters' => [],
        ],
        '⿲sdc_test_all_props:all-props␟test_string_format_uri' => [
          'required' => FALSE,
          'instances' => [
            "This Foo's field_silly_image" => 'ℹ︎␜entity:node:foo␝field_silly_image␞␟entity␜␜entity:file␝uri␞␟value',
          ],
          'adapters' => [],
        ],
        '⿲sdc_test_all_props:all-props␟test_string_format_uri_image' => [
          'required' => FALSE,
          'instances' => [
            "This Foo's field_silly_image" => 'ℹ︎␜entity:node:foo␝field_silly_image␞␟entity␜␜entity:file␝uri␞␟url',
          ],
          'adapters' => [
            'Extract image URL' => 'image_extract_url',
          ],
        ],
        '⿲sdc_test_all_props:all-props␟test_string_format_uri_reference' => [
          'required' => FALSE,
          'instances' => [
            "This Foo's field_silly_image" => 'ℹ︎␜entity:node:foo␝field_silly_image␞␟entity␜␜entity:file␝uri␞␟value',
          ],
          'adapters' => [],
        ],
        '⿲sdc_test_all_props:all-props␟test_string_format_iri' => [
          'required' => FALSE,
          'instances' => [
            "This Foo's field_silly_image" => 'ℹ︎␜entity:node:foo␝field_silly_image␞␟entity␜␜entity:file␝uri␞␟value',
          ],
          'adapters' => [],
        ],
        '⿲sdc_test_all_props:all-props␟test_string_format_iri_reference' => [
          'required' => FALSE,
          'instances' => [
            "This Foo's field_silly_image" => 'ℹ︎␜entity:node:foo␝field_silly_image␞␟entity␜␜entity:file␝uri␞␟value',
          ],
          'adapters' => [],
        ],
        '⿲sdc_test_all_props:all-props␟test_string_format_uri_template' => [
          'required' => FALSE,
          'instances' => [],
          'adapters' => [],
        ],
        '⿲sdc_test_all_props:all-props␟test_string_format_json_pointer' => [
          'required' => FALSE,
          'instances' => [],
          'adapters' => [],
        ],
        '⿲sdc_test_all_props:all-props␟test_string_format_relative_json_pointer' => [
          'required' => FALSE,
          'instances' => [],
          'adapters' => [],
        ],
        '⿲sdc_test_all_props:all-props␟test_string_format_regex' => [
          'required' => FALSE,
          'instances' => [],
          'adapters' => [],
        ],
        '⿲sdc_test_all_props:all-props␟test_integer' => [
          'required' => FALSE,
          'instances' => [
            "This Foo's Changed" => 'ℹ︎␜entity:node:foo␝changed␞␟value',
            "This Foo's Authored on" => 'ℹ︎␜entity:node:foo␝created␞␟value',
            "This Foo's field_silly_image" => 'ℹ︎␜entity:node:foo␝field_silly_image␞␟width',
            "This Foo's ID" => 'ℹ︎␜entity:node:foo␝nid␞␟value',
            "This Foo's URL alias" => 'ℹ︎␜entity:node:foo␝path␞␟pid',
            "This Foo's Revision create time" => 'ℹ︎␜entity:node:foo␝revision_timestamp␞␟value',
            "This Foo's Revision user" => 'ℹ︎␜entity:node:foo␝revision_uid␞␟target_id',
            "This Foo's Authored by" => 'ℹ︎␜entity:node:foo␝uid␞␟target_id',
            "This Foo's Revision ID" => 'ℹ︎␜entity:node:foo␝vid␞␟value',
          ],
          'adapters' => [
            'Count days' => 'day_count',
          ],
        ],
        '⿲sdc_test_all_props:all-props␟test_integer_range_minimum' => [
          'required' => FALSE,
          'instances' => [],
          'adapters' => [],
        ],
        '⿲sdc_test_all_props:all-props␟test_integer_range_minimum_maximum_timestamps' => [
          'required' => FALSE,
          'instances' => [
            "This Foo's Revision user" => 'ℹ︎␜entity:node:foo␝revision_uid␞␟entity␜␜entity:user␝login␞␟value',
            "This Foo's Authored by" => 'ℹ︎␜entity:node:foo␝uid␞␟entity␜␜entity:user␝login␞␟value',
          ],
          'adapters' => [],
        ],
        '⿲sdc_test_all_props:all-props␟test_object_drupal_image' => [
          'required' => FALSE,
          'instances' => [
            "This Foo's field_silly_image" => 'ℹ︎␜entity:node:foo␝field_silly_image␞␟{src↝entity␜␜entity:file␝uri␞␟url,alt↠alt,width↠width,height↠height}',
          ],
          'adapters' => [
            'Apply image style' => 'image_apply_style',
            'Make relative image URL absolute' => 'image_url_rel_to_abs',
          ],
        ],
        '⿲sdc_test_all_props:all-props␟test_object_drupal_date_range' => [
          'required' => FALSE,
          'instances' => [
            "This Foo's field_event_duration" => 'ℹ︎␜entity:node:foo␝field_event_duration␞␟{from↠value,to↠end_value}',
          ],
          'adapters' => [],
        ],
      ],
    ];
  }

}
