<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel;

use Drupal\Core\Entity\TypedData\EntityDataDefinition;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\canvas\ShapeMatcher\FieldForComponentSuggester;
use Drupal\canvas\Plugin\Adapter\AdapterInterface;
use Drupal\canvas\PropExpressions\StructuredData\StructuredDataPropExpressionInterface;
use Drupal\Core\Plugin\Component;
use Drupal\Core\Theme\ComponentPluginManager;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\canvas\Traits\ContribStrictConfigSchemaTestTrait;

/**
 * @coversClass \Drupal\canvas\ShapeMatcher\FieldForComponentSuggester
 * @group canvas
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
    'canvas',
    // The dependent modules.
    'sdc',
    'media',
    // The module providing realistic test SDCs.
    'canvas_test_sdc',
    // The module providing the sample SDC to test all JSON schema types.
    'sdc_test_all_props',
    'canvas_test_sdc',
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
    'ckeditor5',
    'editor',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig('canvas');
    $this->installEntitySchema('node');
    $this->installEntitySchema('field_storage_config');
    $this->installEntitySchema('field_config');
    // Create a "Foo" node type.
    NodeType::create([
      'name' => 'Foo',
      'type' => 'foo',
    ])->save();
    // Create a "Silly image 🤡" field on the "Foo" node type.
    FieldStorageConfig::create([
      'entity_type' => 'node',
      'field_name' => 'field_silly_image',
      'type' => 'image',
      // This is the default, but being explicit is helpful in tests.
      'cardinality' => 1,
    ])->save();
    FieldConfig::create([
      'entity_type' => 'node',
      'field_name' => 'field_silly_image',
      'label' => 'Silly image 🤡',
      'bundle' => 'foo',
      'required' => TRUE,
    ])->save();
    FieldStorageConfig::create([
      'entity_type' => 'node',
      'field_name' => 'field_before_and_after',
      'type' => 'image',
      'cardinality' => 2,
    ])->save();
    FieldConfig::create([
      'entity_type' => 'node',
      'field_name' => 'field_before_and_after',
      'bundle' => 'foo',
      'required' => TRUE,
    ])->save();
    FieldStorageConfig::create([
      'entity_type' => 'node',
      'field_name' => 'field_screenshots',
      'type' => 'image',
      'cardinality' => FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED,
    ])->save();
    FieldConfig::create([
      'entity_type' => 'node',
      'field_name' => 'field_screenshots',
      'bundle' => 'foo',
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
    // Create a "wall of text" field on the "Foo" node type.
    FieldStorageConfig::create([
      'entity_type' => 'node',
      'field_name' => 'field_wall_of_text',
      'type' => 'text_long',
    ])->save();
    FieldConfig::create([
      'entity_type' => 'node',
      'field_name' => 'field_wall_of_text',
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
    $component = \Drupal::service(ComponentPluginManager::class)->find($component_plugin_id);
    assert($component instanceof Component);
    $suggestions = $this->container->get(FieldForComponentSuggester::class)
      ->suggest(
        $component_plugin_id,
        $component->metadata,
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
      'canvas_test_sdc:image',
      'entity:node:foo',
      [
        '⿲canvas_test_sdc:image␟image' => [
          'required' => TRUE,
          'instances' => [
            "Silly image 🤡" => 'ℹ︎␜entity:node:foo␝field_silly_image␞␟{src↠src_with_alternate_widths,alt↠alt,width↠width,height↠height}',
          ],
          'adapters' => [
            'Apply image style' => 'image_apply_style',
            'Make relative image URL absolute' => 'image_url_rel_to_abs',
          ],
        ],
      ],
    ];

    yield 'the image component — free of context' => [
      'canvas_test_sdc:image',
      NULL,
      [
        '⿲canvas_test_sdc:image␟image' => [
          'required' => TRUE,
          'instances' => [],
          'adapters' => [
            'Apply image style' => 'image_apply_style',
            'Make relative image URL absolute' => 'image_url_rel_to_abs',
          ],
        ],
      ],
    ];

    // 💡 Demonstrate it is possible to reuse an Canvas-defined prop shape, add a
    // new computed property to a field type, and match that, too. (This
    // particular computed property happens to be added by Canvas itself, but any
    // module can follow this pattern.)
    yield 'the image-srcset-candidate-template-uri component' => [
      'canvas_test_sdc:image-srcset-candidate-template-uri',
      'entity:node:foo',
      [
        '⿲canvas_test_sdc:image-srcset-candidate-template-uri␟image' => [
          'required' => TRUE,
          'instances' => [
            "Silly image 🤡" => 'ℹ︎␜entity:node:foo␝field_silly_image␞␟{src↠src_with_alternate_widths,alt↠alt,width↠width,height↠height}',
          ],
          'adapters' => [
            'Apply image style' => 'image_apply_style',
            'Make relative image URL absolute' => 'image_url_rel_to_abs',
          ],
        ],
        '⿲canvas_test_sdc:image-srcset-candidate-template-uri␟srcSetCandidateTemplate' => [
          'required' => FALSE,
          'instances' => [
            "Silly image 🤡 (only srcset_candidate_uri_template)" => 'ℹ︎␜entity:node:foo␝field_silly_image␞␟srcset_candidate_uri_template',
          ],
          'adapters' => [],
        ],
      ],
    ];

    yield 'the "ALL PROPS" test component' => [
      'sdc_test_all_props:all-props',
      'entity:node:foo',
      [
        '⿲sdc_test_all_props:all-props␟test_bool_default_false' => [
          'required' => FALSE,
          'instances' => [
            "Published" => 'ℹ︎␜entity:node:foo␝status␞␟value',
            "Authored by → User → default_langcode" => 'ℹ︎␜entity:node:foo␝uid␞␟entity␜␜entity:user␝default_langcode␞␟value',
            "Authored by → User → status" => 'ℹ︎␜entity:node:foo␝uid␞␟entity␜␜entity:user␝status␞␟value',
            "Promoted to front page" => 'ℹ︎␜entity:node:foo␝promote␞␟value',
            "Sticky at top of lists" => 'ℹ︎␜entity:node:foo␝sticky␞␟value',
            "Default translation" => 'ℹ︎␜entity:node:foo␝default_langcode␞␟value',
            "Silly image 🤡 → File → status" => 'ℹ︎␜entity:node:foo␝field_silly_image␞␟entity␜␜entity:file␝status␞␟value',
            "Default revision" => 'ℹ︎␜entity:node:foo␝revision_default␞␟value',
            "Revision user → User → default_langcode" => 'ℹ︎␜entity:node:foo␝revision_uid␞␟entity␜␜entity:user␝default_langcode␞␟value',
            "Revision user → User → status" => 'ℹ︎␜entity:node:foo␝revision_uid␞␟entity␜␜entity:user␝status␞␟value',
          ],
          'adapters' => [],
        ],
        '⿲sdc_test_all_props:all-props␟test_bool_default_true' => [
          'required' => FALSE,
          'instances' => [
            "Published" => 'ℹ︎␜entity:node:foo␝status␞␟value',
            "Authored by → User → default_langcode" => 'ℹ︎␜entity:node:foo␝uid␞␟entity␜␜entity:user␝default_langcode␞␟value',
            "Authored by → User → status" => 'ℹ︎␜entity:node:foo␝uid␞␟entity␜␜entity:user␝status␞␟value',
            "Promoted to front page" => 'ℹ︎␜entity:node:foo␝promote␞␟value',
            "Sticky at top of lists" => 'ℹ︎␜entity:node:foo␝sticky␞␟value',
            "Default translation" => 'ℹ︎␜entity:node:foo␝default_langcode␞␟value',
            "Silly image 🤡 → File → status" => 'ℹ︎␜entity:node:foo␝field_silly_image␞␟entity␜␜entity:file␝status␞␟value',
            "Default revision" => 'ℹ︎␜entity:node:foo␝revision_default␞␟value',
            "Revision user → User → default_langcode" => 'ℹ︎␜entity:node:foo␝revision_uid␞␟entity␜␜entity:user␝default_langcode␞␟value',
            "Revision user → User → status" => 'ℹ︎␜entity:node:foo␝revision_uid␞␟entity␜␜entity:user␝status␞␟value',
          ],
          'adapters' => [],
        ],
        '⿲sdc_test_all_props:all-props␟test_string' => [
          'required' => FALSE,
          'instances' => [
            "Revision log message" => 'ℹ︎␜entity:node:foo␝revision_log␞␟value',
            "Title" => 'ℹ︎␜entity:node:foo␝title␞␟value',
            "Silly image 🤡 (only alt)" => 'ℹ︎␜entity:node:foo␝field_silly_image␞␟alt',
            "Silly image 🤡 (only title)" => 'ℹ︎␜entity:node:foo␝field_silly_image␞␟title',
          ],
          'adapters' => [],
        ],
        '⿲sdc_test_all_props:all-props␟test_string_multiline' => [
          'required' => FALSE,
          'instances' => [
            "Revision log message" => 'ℹ︎␜entity:node:foo␝revision_log␞␟value',
          ],
          'adapters' => [],
        ],
        '⿲sdc_test_all_props:all-props␟test_REQUIRED_string' => [
          'required' => TRUE,
          'instances' => [
            "Title" => 'ℹ︎␜entity:node:foo␝title␞␟value',
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
            "field_event_duration (only end_value)" => 'ℹ︎␜entity:node:foo␝field_event_duration␞␟end_value',
            "field_event_duration" => 'ℹ︎␜entity:node:foo␝field_event_duration␞␟value',
          ],
          'adapters' => [],
        ],
        '⿲sdc_test_all_props:all-props␟test_string_format_date' => [
          'required' => FALSE,
          'instances' => [
            "field_event_duration (only end_value)" => 'ℹ︎␜entity:node:foo␝field_event_duration␞␟end_value',
            "field_event_duration" => 'ℹ︎␜entity:node:foo␝field_event_duration␞␟value',
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
            "Authored by → User → init" => 'ℹ︎␜entity:node:foo␝uid␞␟entity␜␜entity:user␝init␞␟value',
            "Authored by → User → mail" => 'ℹ︎␜entity:node:foo␝uid␞␟entity␜␜entity:user␝mail␞␟value',
            "Revision user → User → init" => 'ℹ︎␜entity:node:foo␝revision_uid␞␟entity␜␜entity:user␝init␞␟value',
            "Revision user → User → mail" => 'ℹ︎␜entity:node:foo␝revision_uid␞␟entity␜␜entity:user␝mail␞␟value',
          ],
          'adapters' => [],
        ],
        '⿲sdc_test_all_props:all-props␟test_string_format_idn_email' => [
          'required' => FALSE,
          'instances' => [
            "Authored by → User → init" => 'ℹ︎␜entity:node:foo␝uid␞␟entity␜␜entity:user␝init␞␟value',
            "Authored by → User → mail" => 'ℹ︎␜entity:node:foo␝uid␞␟entity␜␜entity:user␝mail␞␟value',
            "Revision user → User → init" => 'ℹ︎␜entity:node:foo␝revision_uid␞␟entity␜␜entity:user␝init␞␟value',
            "Revision user → User → mail" => 'ℹ︎␜entity:node:foo␝revision_uid␞␟entity␜␜entity:user␝mail␞␟value',
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
            "Authored by → User → uuid" => 'ℹ︎␜entity:node:foo␝uid␞␟entity␜␜entity:user␝uuid␞␟value',
            "Authored by (only target_uuid)" => 'ℹ︎␜entity:node:foo␝uid␞␟target_uuid',
            "Silly image 🤡 → File → uid" => 'ℹ︎␜entity:node:foo␝field_silly_image␞␟entity␜␜entity:file␝uid␞␟target_uuid',
            "Silly image 🤡 → File → uuid" => 'ℹ︎␜entity:node:foo␝field_silly_image␞␟entity␜␜entity:file␝uuid␞␟value',
            "Revision user → User → uuid" => 'ℹ︎␜entity:node:foo␝revision_uid␞␟entity␜␜entity:user␝uuid␞␟value',
            "Revision user (only target_uuid)" => 'ℹ︎␜entity:node:foo␝revision_uid␞␟target_uuid',
            "UUID" => 'ℹ︎␜entity:node:foo␝uuid␞␟value',
          ],
          'adapters' => [],
        ],
        '⿲sdc_test_all_props:all-props␟test_REQUIRED_string_format_uri' => [
          'required' => TRUE,
          'instances' => [
            "Silly image 🤡 → File → uri" => 'ℹ︎␜entity:node:foo␝field_silly_image␞␟entity␜␜entity:file␝uri␞␟value',
            "Silly image 🤡" => 'ℹ︎␜entity:node:foo␝field_silly_image␞␟src_with_alternate_widths',
          ],
          'adapters' => [],
        ],
        '⿲sdc_test_all_props:all-props␟test_string_format_uri' => [
          'required' => FALSE,
          'instances' => [
            "Silly image 🤡 → File → uri" => 'ℹ︎␜entity:node:foo␝field_silly_image␞␟entity␜␜entity:file␝uri␞␟value',
            "Silly image 🤡" => 'ℹ︎␜entity:node:foo␝field_silly_image␞␟src_with_alternate_widths',
          ],
          'adapters' => [],
        ],
        '⿲sdc_test_all_props:all-props␟test_string_format_uri_image' => [
          'required' => FALSE,
          'instances' => [
            "Silly image 🤡 → File → uri" => 'ℹ︎␜entity:node:foo␝field_silly_image␞␟entity␜␜entity:file␝uri␞␟url',
            "Silly image 🤡" => 'ℹ︎␜entity:node:foo␝field_silly_image␞␟src_with_alternate_widths',
          ],
          'adapters' => [
            'Extract image URL' => 'image_extract_url',
          ],
        ],
        '⿲sdc_test_all_props:all-props␟test_string_format_uri_image_using_ref' => [
          'required' => FALSE,
          'instances' => [
            "Silly image 🤡 → File → uri" => 'ℹ︎␜entity:node:foo␝field_silly_image␞␟entity␜␜entity:file␝uri␞␟url',
            "Silly image 🤡" => 'ℹ︎␜entity:node:foo␝field_silly_image␞␟src_with_alternate_widths',
          ],
          'adapters' => [
            'Extract image URL' => 'image_extract_url',
          ],
        ],
        '⿲sdc_test_all_props:all-props␟test_string_format_uri_reference' => [
          'required' => FALSE,
          'instances' => [
            "Silly image 🤡 → File → uri" => 'ℹ︎␜entity:node:foo␝field_silly_image␞␟entity␜␜entity:file␝uri␞␟value',
            "Silly image 🤡" => 'ℹ︎␜entity:node:foo␝field_silly_image␞␟src_with_alternate_widths',
          ],
          'adapters' => [],
        ],
        '⿲sdc_test_all_props:all-props␟test_string_format_iri' => [
          'required' => FALSE,
          'instances' => [
            "Silly image 🤡 → File → uri" => 'ℹ︎␜entity:node:foo␝field_silly_image␞␟entity␜␜entity:file␝uri␞␟value',
            "Silly image 🤡" => 'ℹ︎␜entity:node:foo␝field_silly_image␞␟src_with_alternate_widths',
          ],
          'adapters' => [],
        ],
        '⿲sdc_test_all_props:all-props␟test_string_format_iri_reference' => [
          'required' => FALSE,
          'instances' => [
            "Silly image 🤡 → File → uri" => 'ℹ︎␜entity:node:foo␝field_silly_image␞␟entity␜␜entity:file␝uri␞␟value',
            "Silly image 🤡" => 'ℹ︎␜entity:node:foo␝field_silly_image␞␟src_with_alternate_widths',
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
            "Authored by → User → access" => 'ℹ︎␜entity:node:foo␝uid␞␟entity␜␜entity:user␝access␞␟value',
            "Authored by → User → changed" => 'ℹ︎␜entity:node:foo␝uid␞␟entity␜␜entity:user␝changed␞␟value',
            "Authored by → User → created" => 'ℹ︎␜entity:node:foo␝uid␞␟entity␜␜entity:user␝created␞␟value',
            "Authored by → User → login" => 'ℹ︎␜entity:node:foo␝uid␞␟entity␜␜entity:user␝login␞␟value',
            "Authored on" => 'ℹ︎␜entity:node:foo␝created␞␟value',
            "Changed" => 'ℹ︎␜entity:node:foo␝changed␞␟value',
            "Silly image 🤡 → File → changed" => 'ℹ︎␜entity:node:foo␝field_silly_image␞␟entity␜␜entity:file␝changed␞␟value',
            "Silly image 🤡 → File → created" => 'ℹ︎␜entity:node:foo␝field_silly_image␞␟entity␜␜entity:file␝created␞␟value',
            "Silly image 🤡 → File → filesize" => 'ℹ︎␜entity:node:foo␝field_silly_image␞␟entity␜␜entity:file␝filesize␞␟value',
            "Silly image 🤡 (only height)" => 'ℹ︎␜entity:node:foo␝field_silly_image␞␟height',
            "Silly image 🤡 (only width)" => 'ℹ︎␜entity:node:foo␝field_silly_image␞␟width',
            "Revision create time" => 'ℹ︎␜entity:node:foo␝revision_timestamp␞␟value',
            "Revision user → User → access" => 'ℹ︎␜entity:node:foo␝revision_uid␞␟entity␜␜entity:user␝access␞␟value',
            "Revision user → User → changed" => 'ℹ︎␜entity:node:foo␝revision_uid␞␟entity␜␜entity:user␝changed␞␟value',
            "Revision user → User → created" => 'ℹ︎␜entity:node:foo␝revision_uid␞␟entity␜␜entity:user␝created␞␟value',
            "Revision user → User → login" => 'ℹ︎␜entity:node:foo␝revision_uid␞␟entity␜␜entity:user␝login␞␟value',
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
            "Authored by → User → access" => 'ℹ︎␜entity:node:foo␝uid␞␟entity␜␜entity:user␝access␞␟value',
            "Authored by → User → login" => 'ℹ︎␜entity:node:foo␝uid␞␟entity␜␜entity:user␝login␞␟value',
            "Revision user → User → access" => 'ℹ︎␜entity:node:foo␝revision_uid␞␟entity␜␜entity:user␝access␞␟value',
            "Revision user → User → login" => 'ℹ︎␜entity:node:foo␝revision_uid␞␟entity␜␜entity:user␝login␞␟value',
          ],
          'adapters' => [],
        ],
        '⿲sdc_test_all_props:all-props␟test_number' => [
          'required' => FALSE,
          'instances' => [
            "Authored by → User → access" => 'ℹ︎␜entity:node:foo␝uid␞␟entity␜␜entity:user␝access␞␟value',
            "Authored by → User → changed" => 'ℹ︎␜entity:node:foo␝uid␞␟entity␜␜entity:user␝changed␞␟value',
            "Authored by → User → created" => 'ℹ︎␜entity:node:foo␝uid␞␟entity␜␜entity:user␝created␞␟value',
            "Authored by → User → login" => 'ℹ︎␜entity:node:foo␝uid␞␟entity␜␜entity:user␝login␞␟value',
            "Authored on" => 'ℹ︎␜entity:node:foo␝created␞␟value',
            "Changed" => 'ℹ︎␜entity:node:foo␝changed␞␟value',
            "Silly image 🤡 → File → changed" => 'ℹ︎␜entity:node:foo␝field_silly_image␞␟entity␜␜entity:file␝changed␞␟value',
            "Silly image 🤡 → File → created" => 'ℹ︎␜entity:node:foo␝field_silly_image␞␟entity␜␜entity:file␝created␞␟value',
            "Silly image 🤡 → File → filesize" => 'ℹ︎␜entity:node:foo␝field_silly_image␞␟entity␜␜entity:file␝filesize␞␟value',
            "Silly image 🤡 (only height)" => 'ℹ︎␜entity:node:foo␝field_silly_image␞␟height',
            "Silly image 🤡 (only width)" => 'ℹ︎␜entity:node:foo␝field_silly_image␞␟width',
            "Revision create time" => 'ℹ︎␜entity:node:foo␝revision_timestamp␞␟value',
            "Revision user → User → access" => 'ℹ︎␜entity:node:foo␝revision_uid␞␟entity␜␜entity:user␝access␞␟value',
            "Revision user → User → changed" => 'ℹ︎␜entity:node:foo␝revision_uid␞␟entity␜␜entity:user␝changed␞␟value',
            "Revision user → User → created" => 'ℹ︎␜entity:node:foo␝revision_uid␞␟entity␜␜entity:user␝created␞␟value',
            "Revision user → User → login" => 'ℹ︎␜entity:node:foo␝revision_uid␞␟entity␜␜entity:user␝login␞␟value',
          ],
          'adapters' => [],
        ],
        '⿲sdc_test_all_props:all-props␟test_object_drupal_image' => [
          'required' => FALSE,
          'instances' => [
            "Silly image 🤡" => 'ℹ︎␜entity:node:foo␝field_silly_image␞␟{src↠src_with_alternate_widths,alt↠alt,width↠width,height↠height}',
          ],
          'adapters' => [
            'Apply image style' => 'image_apply_style',
            'Make relative image URL absolute' => 'image_url_rel_to_abs',
          ],
        ],
        '⿲sdc_test_all_props:all-props␟test_object_drupal_image_ARRAY' => [
          'required' => FALSE,
          'instances' => [
            "field_before_and_after" => 'ℹ︎␜entity:node:foo␝field_before_and_after␞␟{src↠src_with_alternate_widths,alt↠alt,width↠width,height↠height}',
          ],
          'adapters' => [],
        ],
        '⿲sdc_test_all_props:all-props␟test_object_drupal_video' => [
          'required' => FALSE,
          'instances' => [],
          'adapters' => [],
        ],
        '⿲sdc_test_all_props:all-props␟test_object_drupal_date_range' => [
          'required' => FALSE,
          'instances' => [
            "field_event_duration" => 'ℹ︎␜entity:node:foo␝field_event_duration␞␟{from↠value,to↠end_value}',
          ],
          'adapters' => [],
        ],
        '⿲sdc_test_all_props:all-props␟test_string_html_inline' => [
          'required' => FALSE,
          'instances' => [],
          'adapters' => [],
        ],
        '⿲sdc_test_all_props:all-props␟test_string_html_block' => [
          'required' => FALSE,
          'instances' => [
            "field_wall_of_text (only processed)" => 'ℹ︎␜entity:node:foo␝field_wall_of_text␞␟processed',
          ],
          'adapters' => [],
        ],
        '⿲sdc_test_all_props:all-props␟test_string_html' => [
          'required' => FALSE,
          'instances' => [
            "field_wall_of_text (only processed)" => 'ℹ︎␜entity:node:foo␝field_wall_of_text␞␟processed',
          ],
          'adapters' => [],
        ],
        '⿲sdc_test_all_props:all-props␟test_REQUIRED_string_html_inline' => [
          'required' => TRUE,
          'instances' => [],
          'adapters' => [],
        ],
        '⿲sdc_test_all_props:all-props␟test_REQUIRED_string_html_block' => [
          'required' => TRUE,
          'instances' => [
            "field_wall_of_text (only processed)" => 'ℹ︎␜entity:node:foo␝field_wall_of_text␞␟processed',
          ],
          'adapters' => [],
        ],
        '⿲sdc_test_all_props:all-props␟test_REQUIRED_string_html' => [
          'required' => TRUE,
          'instances' => [
            "field_wall_of_text (only processed)" => 'ℹ︎␜entity:node:foo␝field_wall_of_text␞␟processed',
          ],
          'adapters' => [],
        ],
        '⿲sdc_test_all_props:all-props␟test_array_integer' => [
          'required' => FALSE,
          'instances' => [
            "field_screenshots → File → changed" => 'ℹ︎␜entity:node:foo␝field_screenshots␞␟entity␜␜entity:file␝changed␞␟value',
            "field_screenshots → File → created" => 'ℹ︎␜entity:node:foo␝field_screenshots␞␟entity␜␜entity:file␝created␞␟value',
            "field_screenshots → File → filesize" => 'ℹ︎␜entity:node:foo␝field_screenshots␞␟entity␜␜entity:file␝filesize␞␟value',
            "field_screenshots (only height)" => 'ℹ︎␜entity:node:foo␝field_screenshots␞␟height',
            "field_screenshots (only width)" => 'ℹ︎␜entity:node:foo␝field_screenshots␞␟width',
          ],
          'adapters' => [],
        ],

        '⿲sdc_test_all_props:all-props␟test_array_integer_minItems' => [
          'required' => FALSE,
          'instances' => [],
          'adapters' => [],
        ],
        '⿲sdc_test_all_props:all-props␟test_array_integer_maxItems' => [
          'required' => FALSE,
          'instances' => [
            "field_before_and_after → File → changed" => 'ℹ︎␜entity:node:foo␝field_before_and_after␞␟entity␜␜entity:file␝changed␞␟value',
            "field_before_and_after → File → created" => 'ℹ︎␜entity:node:foo␝field_before_and_after␞␟entity␜␜entity:file␝created␞␟value',
            "field_before_and_after → File → filesize" => 'ℹ︎␜entity:node:foo␝field_before_and_after␞␟entity␜␜entity:file␝filesize␞␟value',
            "field_before_and_after (only height)" => 'ℹ︎␜entity:node:foo␝field_before_and_after␞␟height',
            "field_before_and_after (only width)" => 'ℹ︎␜entity:node:foo␝field_before_and_after␞␟width',
          ],
          'adapters' => [],
        ],
        '⿲sdc_test_all_props:all-props␟test_array_integer_minItemsMultiple' => [
          'required' => FALSE,
          'instances' => [],
          'adapters' => [],
        ],
        '⿲sdc_test_all_props:all-props␟test_array_integer_minMaxItems' => [
          'required' => FALSE,
          'instances' => [],
          'adapters' => [],
        ],
      ],
    ];
  }

}
