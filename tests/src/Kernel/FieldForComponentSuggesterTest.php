<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Kernel;

use Drupal\Core\Entity\TypedData\EntityDataDefinition;
use Drupal\experience_builder\FieldForComponentSuggester;
use Drupal\experience_builder\Plugin\Adapter\AdapterInterface;
use Drupal\experience_builder\PropExpressions\StructuredData\StructuredDataPropExpressionInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\NodeType;

/**
 * @coversClass \Drupal\experience_builder\FieldForComponentSuggester
 * @group experience_builder
 */
class FieldForComponentSuggesterTest extends KernelTestBase {

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
   * @param array<string, array{'required': bool, 'types': array<string, string>, 'instances': array<string, string>, 'adapters': array<string, string>}> $expected
   *
   * @dataProvider provider
   */
  public function test(string $component_plugin_id, string $entity_type_id, string $bundle, array $expected): void {
    $suggestions = $this->container->get(FieldForComponentSuggester::class)
      ->suggest(
        $component_plugin_id,
        EntityDataDefinition::create($entity_type_id, $bundle)
      );

    // All expectations that are present must be correct.
    foreach (array_keys($expected) as $prop_name) {
      $this->assertSame(
        $expected[$prop_name],
        [
          'required' => $suggestions[$prop_name]['required'],
          'types' => array_map(fn (StructuredDataPropExpressionInterface $e): string => (string) $e, $suggestions[$prop_name]['types']),
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
      'node',
      'foo',
      [
        '⿲experience_builder:image␟image' => [
          'required' => TRUE,
          'types' => [
            'Image' => 'ℹ︎image␟{src↝entity␜␜entity:file␝uri␞0␟url,alt↠alt,width↠width,height↠height}',
          ],
          'instances' => [
            "This Foo's field_silly_image" => 'ℹ︎␜entity:node:foo␝field_silly_image␞␟{src↝entity␜␜entity:file␝uri␞␟value,alt↠alt,width↠width,height↠height}',
          ],
          'adapters' => [
            'Apply image style' => 'image_apply_style',
            'Make relative image URL absolute' => 'image_url_rel_to_abs',
          ],
        ],
      ],
    ];

    yield 'the "ALL PROPS" test component' => [
      'sdc_test_all_props:all-props',
      'node',
      'foo',
      [
        '⿲sdc_test_all_props:all-props␟test-string' => [
          'required' => FALSE,
          'types' => [
            'Text (plain, long)' => 'ℹ︎string_long␟value',
            'Text (plain)' => 'ℹ︎string␟value',
          ],
          'instances' => [
            "This Foo's field_silly_image" => 'ℹ︎␜entity:node:foo␝field_silly_image␞␟title',
            "This Foo's Revision log message" => 'ℹ︎␜entity:node:foo␝revision_log␞␟value',
            "This Foo's Title" => 'ℹ︎␜entity:node:foo␝title␞␟value',
          ],
          'adapters' => [],
        ],
        '⿲sdc_test_all_props:all-props␟test-REQUIRED-string' => [
          'required' => TRUE,
          'types' => [
            'Text (plain, long)' => 'ℹ︎string_long␟value',
            'Text (plain)' => 'ℹ︎string␟value',
          ],
          'instances' => [
            "This Foo's Title" => 'ℹ︎␜entity:node:foo␝title␞␟value',
          ],
          'adapters' => [],
        ],
        '⿲sdc_test_all_props:all-props␟test-string-enum' => [
          'required' => FALSE,
          'types' => [],
          'instances' => [],
          'adapters' => [],
        ],
        '⿲sdc_test_all_props:all-props␟test-string-format-date-time' => [
          'required' => FALSE,
          'types' => [
            'Date' => 'ℹ︎datetime␟value',
          ],
          'instances' => [
            "This Foo's field_event_duration" => 'ℹ︎␜entity:node:foo␝field_event_duration␞␟value',
          ],
          'adapters' => [],
        ],
        '⿲sdc_test_all_props:all-props␟test-string-format-date' => [
          'required' => FALSE,
          'types' => [
            'Date' => 'ℹ︎datetime␟value',
          ],
          'instances' => [
            "This Foo's field_event_duration" => 'ℹ︎␜entity:node:foo␝field_event_duration␞␟value',
          ],
          'adapters' => [],
        ],
        '⿲sdc_test_all_props:all-props␟test-string-format-time' => [
          'required' => FALSE,
          'types' => [],
          'instances' => [],
          'adapters' => [],
        ],
        '⿲sdc_test_all_props:all-props␟test-string-format-duration' => [
          'required' => FALSE,
          'types' => [],
          'instances' => [],
          'adapters' => [],
        ],
        '⿲sdc_test_all_props:all-props␟test-string-format-email' => [
          'required' => FALSE,
          'types' => [
            'Email' => 'ℹ︎email␟value',
          ],
          'instances' => [
            "This Foo's Revision user" => 'ℹ︎␜entity:node:foo␝revision_uid␞␟entity␜␜entity:user␝mail␞␟value',
            "This Foo's Authored by" => 'ℹ︎␜entity:node:foo␝uid␞␟entity␜␜entity:user␝mail␞␟value',
          ],
          'adapters' => [],
        ],
        '⿲sdc_test_all_props:all-props␟test-string-format-idn-email' => [
          'required' => FALSE,
          'types' => [
            'Email' => 'ℹ︎email␟value',
          ],
          'instances' => [
            "This Foo's Revision user" => 'ℹ︎␜entity:node:foo␝revision_uid␞␟entity␜␜entity:user␝mail␞␟value',
            "This Foo's Authored by" => 'ℹ︎␜entity:node:foo␝uid␞␟entity␜␜entity:user␝mail␞␟value',
          ],
          'adapters' => [],
        ],
        '⿲sdc_test_all_props:all-props␟test-string-format-hostname' => [
          'required' => FALSE,
          'types' => [],
          'instances' => [],
          'adapters' => [],
        ],
        '⿲sdc_test_all_props:all-props␟test-string-format-idn-hostname' => [
          'required' => FALSE,
          'types' => [],
          'instances' => [],
          'adapters' => [],
        ],
        '⿲sdc_test_all_props:all-props␟test-string-format-ipv4' => [
          'required' => FALSE,
          'types' => [],
          'instances' => [],
          'adapters' => [],
        ],
        '⿲sdc_test_all_props:all-props␟test-string-format-ipv6' => [
          'required' => FALSE,
          'types' => [],
          'instances' => [],
          'adapters' => [],
        ],
        '⿲sdc_test_all_props:all-props␟test-string-format-uuid' => [
          'required' => FALSE,
          'types' => [
            'File' => 'ℹ︎file␟entity␜␜entity:file␝uuid␞0␟value',
            'Image' => 'ℹ︎image␟entity␜␜entity:file␝uuid␞0␟value',
            'UUID' => 'ℹ︎uuid␟value',
          ],
          'instances' => [
            "This Foo's field_silly_image" => 'ℹ︎␜entity:node:foo␝field_silly_image␞␟entity␜␜entity:file␝uuid␞␟value',
            "This Foo's Revision user" => 'ℹ︎␜entity:node:foo␝revision_uid␞␟entity␜␜entity:user␝uuid␞␟value',
            "This Foo's Authored by" => 'ℹ︎␜entity:node:foo␝uid␞␟entity␜␜entity:user␝uuid␞␟value',
            "This Foo's UUID" => 'ℹ︎␜entity:node:foo␝uuid␞␟value',
          ],
          'adapters' => [],
        ],
        '⿲sdc_test_all_props:all-props␟test-string-format-uri' => [
          'required' => FALSE,
          'types' => [
            'File' => 'ℹ︎file␟entity␜␜entity:file␝uri␞0␟value',
            'Image' => 'ℹ︎image␟entity␜␜entity:file␝uri␞0␟value',
            'URI' => 'ℹ︎uri␟value',
          ],
          'instances' => [
            "This Foo's field_silly_image" => 'ℹ︎␜entity:node:foo␝field_silly_image␞␟entity␜␜entity:file␝uri␞␟value',
          ],
          'adapters' => [],
        ],
        '⿲sdc_test_all_props:all-props␟test-string-format-uri-image' => [
          'required' => FALSE,
          'types' => [
            'Image' => 'ℹ︎image␟entity␜␜entity:file␝uri␞0␟value',
          ],
          'instances' => [
            "This Foo's field_silly_image" => 'ℹ︎␜entity:node:foo␝field_silly_image␞␟entity␜␜entity:file␝uri␞␟value',
          ],
          'adapters' => [
            'Extract image URL' => 'image_extract_url',
          ],
        ],
        '⿲sdc_test_all_props:all-props␟test-string-format-uri-reference' => [
          'required' => FALSE,
          'types' => [
            'Path' => 'ℹ︎path␟alias',
          ],
          'instances' => [
            "This Foo's URL alias" => 'ℹ︎␜entity:node:foo␝path␞␟alias',
          ],
          'adapters' => [],
        ],
        '⿲sdc_test_all_props:all-props␟test-string-format-iri' => [
          'required' => FALSE,
          'types' => [
            'File' => 'ℹ︎file␟entity␜␜entity:file␝uri␞0␟value',
            'Image' => 'ℹ︎image␟entity␜␜entity:file␝uri␞0␟value',
            'URI' => 'ℹ︎uri␟value',
          ],
          'instances' => [
            "This Foo's field_silly_image" => 'ℹ︎␜entity:node:foo␝field_silly_image␞␟entity␜␜entity:file␝uri␞␟value',
          ],
          'adapters' => [],
        ],
        '⿲sdc_test_all_props:all-props␟test-string-format-iri-reference' => [
          'required' => FALSE,
          'types' => [
            'Path' => 'ℹ︎path␟alias',
          ],
          'instances' => [
            "This Foo's URL alias" => 'ℹ︎␜entity:node:foo␝path␞␟alias',
          ],
          'adapters' => [],
        ],
        '⿲sdc_test_all_props:all-props␟test-string-format-uri-template' => [
          'required' => FALSE,
          'types' => [],
          'instances' => [],
          'adapters' => [],
        ],
        '⿲sdc_test_all_props:all-props␟test-string-format-json-pointer' => [
          'required' => FALSE,
          'types' => [],
          'instances' => [],
          'adapters' => [],
        ],
        '⿲sdc_test_all_props:all-props␟test-string-format-relative-json-pointer' => [
          'required' => FALSE,
          'types' => [],
          'instances' => [],
          'adapters' => [],
        ],
        '⿲sdc_test_all_props:all-props␟test-string-format-regex' => [
          'required' => FALSE,
          'types' => [],
          'instances' => [],
          'adapters' => [],
        ],
        '⿲sdc_test_all_props:all-props␟test-integer' => [
          'required' => FALSE,
          'types' => [
            'File' => 'ℹ︎file␟entity␜␜entity:file␝uid␞0␟target_id',
            'Image' => 'ℹ︎image␟entity␜␜entity:file␝uid␞0␟target_id',
            'Last changed' => 'ℹ︎changed␟value',
            'Created' => 'ℹ︎created␟value',
            'Number (integer)' => 'ℹ︎integer␟value',
            'List (integer)' => 'ℹ︎list_integer␟value',
            'Timestamp' => 'ℹ︎timestamp␟value',
          ],
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
          'adapters' => [],
        ],
        '⿲sdc_test_all_props:all-props␟test-integer-range-minimum' => [
          'required' => FALSE,
          'types' => [],
          'instances' => [],
          'adapters' => [],
        ],
        '⿲sdc_test_all_props:all-props␟test-integer-range-minimum-maximum-timestamps' => [
          'required' => FALSE,
          'types' => [
            'Timestamp' => 'ℹ︎timestamp␟value',
          ],
          'instances' => [
            "This Foo's Revision user" => 'ℹ︎␜entity:node:foo␝revision_uid␞␟entity␜␜entity:user␝login␞␟value',
            "This Foo's Authored by" => 'ℹ︎␜entity:node:foo␝uid␞␟entity␜␜entity:user␝login␞␟value',
          ],
          'adapters' => [],
        ],
        '⿲sdc_test_all_props:all-props␟test-object-drupal-image' => [
          'required' => FALSE,
          'types' => [
            'Image' => 'ℹ︎image␟{src↝entity␜␜entity:file␝uri␞0␟url,alt↠alt,width↠width,height↠height}',
          ],
          'instances' => [
            "This Foo's field_silly_image" => 'ℹ︎␜entity:node:foo␝field_silly_image␞␟{src↝entity␜␜entity:file␝uri␞␟value,alt↠alt,width↠width,height↠height}',
          ],
          'adapters' => [
            'Apply image style' => 'image_apply_style',
            'Make relative image URL absolute' => 'image_url_rel_to_abs',
          ],
        ],
        '⿲sdc_test_all_props:all-props␟test-object-drupal-date-range' => [
          'required' => FALSE,
          'types' => [
            'Date range' => 'ℹ︎daterange␟{from↠end_value,to↠value}',
          ],
          'instances' => [
            "This Foo's field_event_duration" => 'ℹ︎␜entity:node:foo␝field_event_duration␞␟{from↠value,to↠end_value}',
          ],
          'adapters' => [],
        ],
      ],
    ];
  }

}
