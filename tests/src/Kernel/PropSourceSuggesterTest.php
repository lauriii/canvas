<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel;

use Drupal\canvas\PropSource\DynamicPropSource;
use Drupal\canvas\PropSource\HostEntityUrlPropSource;
use Drupal\canvas\PropSource\PropSource;
use Drupal\Core\Entity\TypedData\EntityDataDefinition;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\canvas\ShapeMatcher\PropSourceSuggester;
use Drupal\canvas\Plugin\Adapter\AdapterInterface;
use Drupal\Core\Plugin\Component;
use Drupal\Core\Theme\ComponentPluginManager;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\link\LinkItemInterface;
use Drupal\node\Entity\NodeType;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\Tests\canvas\Traits\ContribStrictConfigSchemaTestTrait;
use Drupal\Tests\field\Traits\EntityReferenceFieldCreationTrait;

/**
 * @coversClass \Drupal\canvas\ShapeMatcher\PropSourceSuggester
 * @group canvas
 *
 * @phpstan-import-type HostEntityUrlPropSourceArray from \Drupal\canvas\PropSource\PropSourceBase
 */
class PropSourceSuggesterTest extends KernelTestBase {

  use ContribStrictConfigSchemaTestTrait;
  use EntityReferenceFieldCreationTrait;

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
    'taxonomy',
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
    $this->installEntitySchema('user');
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
    // Create a "check it out" field on the "Foo" node type.
    FieldStorageConfig::create([
      'entity_type' => 'node',
      'field_name' => 'field_check_it_out',
      'type' => 'link',
    ])->save();
    FieldConfig::create([
      'entity_type' => 'node',
      'field_name' => 'field_check_it_out',
      'label' => 'Check it out!',
      'bundle' => 'foo',
      'required' => TRUE,
      'settings' => [
        'title' => DRUPAL_OPTIONAL,
        'link_type' => LinkItemInterface::LINK_GENERIC,
      ],
    ])->save();
    $this->installEntitySchema('taxonomy_term');
    $this->createEntityReferenceField('node', 'foo', 'field_tags', 'Tags', 'taxonomy_term', cardinality: FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED);

    // Optional, single-cardinality user profile picture field.
    // @see core/profiles/standard/config/install/field.storage.user.user_picture.yml
    FieldStorageConfig::create([
      'entity_type' => 'user',
      'field_name' => 'user_picture',
      'type' => 'image',
      'translatable' => FALSE,
      'cardinality' => 1,
    ])->save();
    // @see core/profiles/standard/config/install/field.field.user.user.user_picture.yml
    FieldConfig::create([
      'label' => 'Picture',
      'description' => '',
      'field_name' => 'user_picture',
      'entity_type' => 'user',
      'bundle' => 'user',
      'required' => FALSE,
    ])->save();

    // Optional, multi-bundle reference field.
    Vocabulary::create(['name' => 'Vocab 1', 'vid' => 'vocab_1'])->save();
    Vocabulary::create(['name' => 'Vocab 2', 'vid' => 'vocab_2'])->save();
    FieldStorageConfig::create([
      'field_name' => 'some_text',
      'type' => 'text',
      'entity_type' => 'taxonomy_term',
      'cardinality' => 1,
    ])->save();
    FieldConfig::create([
      'field_name' => 'some_text',
      'entity_type' => 'taxonomy_term',
      'bundle' => 'vocab_2',
      'label' => 'Some text field',
    ])->save();
    $this->createEntityReferenceField(
      'node',
      'foo',
      'primary_topic',
      'Primary topic',
      'taxonomy_term',
      'default',
      ['target_bundles' => ['vocab_1', 'vocab_2']],
      cardinality: 1,
    );
  }

  /**
   * @param array<string, array{'required': bool, 'instances': array<string, string>, 'adapters': array<string, string>, 'host_entity_urls': array<string, HostEntityUrlPropSourceArray>}> $expected
   *
   * @dataProvider provider
   */
  public function test(string $component_plugin_id, string $data_type_context, array $expected): void {
    $component = \Drupal::service(ComponentPluginManager::class)->find($component_plugin_id);
    \assert($component instanceof Component);
    $suggestions = $this->container->get(PropSourceSuggester::class)
      ->suggest(
        $component_plugin_id,
        $component->metadata,
        EntityDataDefinition::createFromDataType($data_type_context),
      );

    // All expectations that are present must be correct.
    foreach (array_keys($expected) as $prop_name) {
      $this->assertSame(
        $expected[$prop_name],
        [
          'required' => $suggestions[$prop_name]['required'],
          'instances' => array_map(fn (DynamicPropSource $s): array => $s->toArray(), $suggestions[$prop_name]['instances']),
          'adapters' => array_map(fn (AdapterInterface $a): string => $a->getPluginId(), $suggestions[$prop_name]['adapters']),
          'host_entity_urls' => array_map(fn (HostEntityUrlPropSource $s): array => $s->toArray(), $suggestions[$prop_name]['host_entity_urls']),
        ],
        "Unexpected prop source suggestion for $prop_name"
      );
    }

    // Finally, the set of expectations must be complete.
    $this->assertSame(array_keys($expected), array_keys($suggestions));
  }

  public static function provider(): \Generator {
    yield 'a component with a required "image" object-shaped prop' => [
      'canvas_test_sdc:image',
      'entity:node:foo',
      [
        '⿲canvas_test_sdc:image␟image' => [
          'required' => TRUE,
          'instances' => [
            "Silly image 🤡" => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝field_silly_image␞␟{src↠src_with_alternate_widths,alt↠alt,width↠width,height↠height}',
            ],
          ],
          'adapters' => [
            'Apply image style' => 'image_apply_style',
            'Make relative image URL absolute' => 'image_url_rel_to_abs',
          ],
          'host_entity_urls' => [],
        ],
      ],
    ];

    yield 'a component with an optional "image" object-shaped-prop' => [
      'canvas_test_sdc:image-optional-with-example',
      'entity:node:foo',
      [
        '⿲canvas_test_sdc:image-optional-with-example␟image' => [
          'required' => FALSE,
          'instances' => [
            'Authored by → User → Picture' => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝uid␞␟entity␜␜entity:user␝user_picture␞␟{src↠src_with_alternate_widths,alt↠alt,width↠width,height↠height}',
            ],
            'Silly image 🤡' => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝field_silly_image␞␟{src↠src_with_alternate_widths,alt↠alt,width↠width,height↠height}',
            ],
            'Primary topic → Taxonomy term → Revision user' => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝primary_topic␞␟entity␜␜entity:taxonomy_term␝revision_user␞␟{src↝entity␜␜entity:user␝user_picture␞␟src_with_alternate_widths,alt↝entity␜␜entity:user␝name␞␟value,width↝entity␜␜entity:user␝user_picture␞␟width,height↝entity␜␜entity:user␝user_picture␞␟height}',
            ],
            'Revision user → User → Picture' => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝revision_uid␞␟entity␜␜entity:user␝user_picture␞␟{src↠src_with_alternate_widths,alt↠alt,width↠width,height↠height}',
            ],
          ],
          'adapters' => [
            'Apply image style' => 'image_apply_style',
            'Make relative image URL absolute' => 'image_url_rel_to_abs',
          ],
          'host_entity_urls' => [],
        ],
      ],
    ];

    // 💡 Demonstrate it is possible to reuse a Canvas-defined prop shape, add a
    // new computed property to a field type, and match that, too. (This
    // particular computed property happens to be added by Canvas itself, but
    // any module can follow this pattern.)
    yield 'the image-srcset-candidate-template-uri component' => [
      'canvas_test_sdc:image-srcset-candidate-template-uri',
      'entity:node:foo',
      [
        '⿲canvas_test_sdc:image-srcset-candidate-template-uri␟image' => [
          'required' => TRUE,
          'instances' => [
            "Silly image 🤡" => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝field_silly_image␞␟{src↠src_with_alternate_widths,alt↠alt,width↠width,height↠height}',
            ],
          ],
          'adapters' => [
            'Apply image style' => 'image_apply_style',
            'Make relative image URL absolute' => 'image_url_rel_to_abs',
          ],
          'host_entity_urls' => [],
        ],
        '⿲canvas_test_sdc:image-srcset-candidate-template-uri␟srcSetCandidateTemplate' => [
          'required' => FALSE,
          'instances' => [
            'Authored by → User → Picture → srcset template' => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝uid␞␟entity␜␜entity:user␝user_picture␞␟srcset_candidate_uri_template',
            ],
            'Silly image 🤡 → srcset template' => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝field_silly_image␞␟srcset_candidate_uri_template',
            ],
            'Revision user → User → Picture → srcset template' => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝revision_uid␞␟entity␜␜entity:user␝user_picture␞␟srcset_candidate_uri_template',
            ],
          ],
          'adapters' => [],
          'host_entity_urls' => [],
        ],
      ],
    ];

    yield 'the tags component' => [
      'canvas_test_sdc:tags',
      'entity:node:foo',
      [
        '⿲canvas_test_sdc:tags␟tags' => [
          'required' => FALSE,
          'instances' => [
            'field_screenshots → Alternative text' => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝field_screenshots␞␟alt',
            ],
            'field_screenshots → Title' => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝field_screenshots␞␟title',
            ],
            'Tags → Taxonomy term → Name' => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝field_tags␞␟entity␜␜entity:taxonomy_term␝name␞␟value',
            ],
          ],
          'adapters' => [],
          'host_entity_urls' => [],
        ],
      ],
    ];

    yield 'a component with a `type: string, format: date`-shaped prop' => [
      'canvas_test_sdc:date',
      'entity:node:foo',
      [
        '⿲canvas_test_sdc:date␟date' => [
          'required' => FALSE,
          'instances' => [
            'Authored on' => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node␝created␞␟value',
              'adapter' => 'unix_to_date',
            ],
            'field_event_duration → End date value' => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝field_event_duration␞␟end_value',
            ],
            'field_event_duration' => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝field_event_duration␞␟value',
            ],
            'Changed' => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node␝changed␞␟value',
              'adapter' => 'unix_to_date',
            ],
          ],
          'adapters' => [
            'UNIX timestamp to date' => 'unix_to_date',
          ],
          'host_entity_urls' => [],
        ],
        '⿲canvas_test_sdc:date␟caption' => [
          'required' => FALSE,
          'instances' => [
            'Title' => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝title␞␟value',
            ],
            'Authored by → User → Name' => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝uid␞␟entity␜␜entity:user␝name␞␟value',
            ],
            'Authored by → User → Picture → Alternative text' => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝uid␞␟entity␜␜entity:user␝user_picture␞␟alt',
            ],
            'Authored by → User → Picture → Title' => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝uid␞␟entity␜␜entity:user␝user_picture␞␟title',
            ],
            'Check it out! → Link text' => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝field_check_it_out␞␟title',
            ],
            'Silly image 🤡 → Alternative text' => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝field_silly_image␞␟alt',
            ],
            'Silly image 🤡 → Title' => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝field_silly_image␞␟title',
            ],
            'Primary topic → Taxonomy term → Name' => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝primary_topic␞␟entity␜␜entity:taxonomy_term␝name␞␟value',
            ],
            'Revision user → User → Name' => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝revision_uid␞␟entity␜␜entity:user␝name␞␟value',
            ],
            'Revision user → User → Picture → Alternative text' => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝revision_uid␞␟entity␜␜entity:user␝user_picture␞␟alt',
            ],
            'Revision user → User → Picture → Title' => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝revision_uid␞␟entity␜␜entity:user␝user_picture␞␟title',
            ],
          ],
          'adapters' => [],
          'host_entity_urls' => [],
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
            "Authored by → User → User status" => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝uid␞␟entity␜␜entity:user␝status␞␟value',
            ],
            "Published" => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝status␞␟value',
            ],
            "Silly image 🤡 → Status" => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝field_silly_image␞␟entity␜␜entity:file␝status␞␟value',
            ],
            'Primary topic → Taxonomy term → Published' => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝primary_topic␞␟entity␜␜entity:taxonomy_term␝status␞␟value',
            ],
            "Revision user → User → User status" => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝revision_uid␞␟entity␜␜entity:user␝status␞␟value',
            ],
          ],
          'adapters' => [],
          'host_entity_urls' => [],
        ],
        '⿲sdc_test_all_props:all-props␟test_bool_default_true' => [
          'required' => FALSE,
          'instances' => [
            "Authored by → User → User status" => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝uid␞␟entity␜␜entity:user␝status␞␟value',
            ],
            "Published" => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝status␞␟value',
            ],
            "Silly image 🤡 → Status" => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝field_silly_image␞␟entity␜␜entity:file␝status␞␟value',
            ],
            'Primary topic → Taxonomy term → Published' => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝primary_topic␞␟entity␜␜entity:taxonomy_term␝status␞␟value',
            ],
            "Revision user → User → User status" => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝revision_uid␞␟entity␜␜entity:user␝status␞␟value',
            ],
          ],
          'adapters' => [],
          'host_entity_urls' => [],
        ],
        '⿲sdc_test_all_props:all-props␟test_string' => [
          'required' => FALSE,
          'instances' => [
            "Title" => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝title␞␟value',
            ],
            'Authored by → User → Name' => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝uid␞␟entity␜␜entity:user␝name␞␟value',
            ],
            'Authored by → User → Picture → Alternative text' => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝uid␞␟entity␜␜entity:user␝user_picture␞␟alt',
            ],
            'Authored by → User → Picture → Title' => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝uid␞␟entity␜␜entity:user␝user_picture␞␟title',
            ],
            'Check it out! → Link text' => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝field_check_it_out␞␟title',
            ],
            "Silly image 🤡 → Alternative text" => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝field_silly_image␞␟alt',
            ],
            "Silly image 🤡 → Title" => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝field_silly_image␞␟title',
            ],
            'Primary topic → Taxonomy term → Name' => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝primary_topic␞␟entity␜␜entity:taxonomy_term␝name␞␟value',
            ],
            'Revision user → User → Name' => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝revision_uid␞␟entity␜␜entity:user␝name␞␟value',
            ],
            'Revision user → User → Picture → Alternative text' => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝revision_uid␞␟entity␜␜entity:user␝user_picture␞␟alt',
            ],
            'Revision user → User → Picture → Title' => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝revision_uid␞␟entity␜␜entity:user␝user_picture␞␟title',
            ],
          ],
          'adapters' => [],
          'host_entity_urls' => [],
        ],
        '⿲sdc_test_all_props:all-props␟test_string_multiline' => [
          'required' => FALSE,
          'instances' => [],
          'adapters' => [],
          'host_entity_urls' => [],
        ],
        '⿲sdc_test_all_props:all-props␟test_REQUIRED_string' => [
          'required' => TRUE,
          'instances' => [
            "Title" => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝title␞␟value',
            ],
          ],
          'adapters' => [],
          'host_entity_urls' => [],
        ],
        '⿲sdc_test_all_props:all-props␟test_string_enum' => [
          'required' => FALSE,
          'instances' => [],
          'adapters' => [],
          'host_entity_urls' => [],
        ],
        '⿲sdc_test_all_props:all-props␟test_integer_enum' => [
          'required' => FALSE,
          'instances' => [],
          'adapters' => [],
          'host_entity_urls' => [],
        ],
        '⿲sdc_test_all_props:all-props␟test_string_format_date_time' => [
          'required' => FALSE,
          'instances' => [
            "field_event_duration → End date value" => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝field_event_duration␞␟end_value',
            ],
            "field_event_duration" => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝field_event_duration␞␟value',
            ],
          ],
          'adapters' => [],
          'host_entity_urls' => [],
        ],
        '⿲sdc_test_all_props:all-props␟test_string_format_date' => [
          'required' => FALSE,
          'instances' => [
            'Authored on' => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node␝created␞␟value',
              'adapter' => 'unix_to_date',
            ],
            "field_event_duration → End date value" => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝field_event_duration␞␟end_value',
            ],
            "field_event_duration" => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝field_event_duration␞␟value',
            ],
            'Changed' => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node␝changed␞␟value',
              'adapter' => 'unix_to_date',
            ],
          ],
          'adapters' => [
            'UNIX timestamp to date' => 'unix_to_date',
          ],
          'host_entity_urls' => [],
        ],
        '⿲sdc_test_all_props:all-props␟test_string_format_time' => [
          'required' => FALSE,
          'instances' => [],
          'adapters' => [],
          'host_entity_urls' => [],
        ],
        '⿲sdc_test_all_props:all-props␟test_string_format_duration' => [
          'required' => FALSE,
          'instances' => [],
          'adapters' => [],
          'host_entity_urls' => [],
        ],
        '⿲sdc_test_all_props:all-props␟test_string_format_email' => [
          'required' => FALSE,
          'instances' => [
            "Authored by → User → Initial email" => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝uid␞␟entity␜␜entity:user␝init␞␟value',
            ],
            "Authored by → User → Email" => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝uid␞␟entity␜␜entity:user␝mail␞␟value',
            ],
            "Revision user → User → Initial email" => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝revision_uid␞␟entity␜␜entity:user␝init␞␟value',
            ],
            "Revision user → User → Email" => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝revision_uid␞␟entity␜␜entity:user␝mail␞␟value',
            ],
          ],
          'adapters' => [],
          'host_entity_urls' => [],
        ],
        '⿲sdc_test_all_props:all-props␟test_string_format_idn_email' => [
          'required' => FALSE,
          'instances' => [
            "Authored by → User → Initial email" => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝uid␞␟entity␜␜entity:user␝init␞␟value',
            ],
            "Authored by → User → Email" => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝uid␞␟entity␜␜entity:user␝mail␞␟value',
            ],
            "Revision user → User → Initial email" => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝revision_uid␞␟entity␜␜entity:user␝init␞␟value',
            ],
            "Revision user → User → Email" => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝revision_uid␞␟entity␜␜entity:user␝mail␞␟value',
            ],
          ],
          'adapters' => [],
          'host_entity_urls' => [],
        ],
        '⿲sdc_test_all_props:all-props␟test_string_format_hostname' => [
          'required' => FALSE,
          'instances' => [],
          'adapters' => [],
          'host_entity_urls' => [],
        ],
        '⿲sdc_test_all_props:all-props␟test_string_format_idn_hostname' => [
          'required' => FALSE,
          'instances' => [],
          'adapters' => [],
          'host_entity_urls' => [],
        ],
        '⿲sdc_test_all_props:all-props␟test_string_format_ipv4' => [
          'required' => FALSE,
          'instances' => [],
          'adapters' => [],
          'host_entity_urls' => [],
        ],
        '⿲sdc_test_all_props:all-props␟test_string_format_ipv6' => [
          'required' => FALSE,
          'instances' => [],
          'adapters' => [],
          'host_entity_urls' => [],
        ],
        '⿲sdc_test_all_props:all-props␟test_string_format_uuid' => [
          'required' => FALSE,
          'instances' => [
            "Authored by → User → UUID" => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝uid␞␟entity␜␜entity:user␝uuid␞␟value',
            ],
            "Authored by → Target UUID" => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝uid␞␟target_uuid',
            ],
            "Silly image 🤡 → UUID" => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝field_silly_image␞␟entity␜␜entity:file␝uuid␞␟value',
            ],
            'Primary topic → Taxonomy term → Revision user → Target UUID' => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝primary_topic␞␟entity␜␜entity:taxonomy_term␝revision_user␞␟target_uuid',
            ],
            'Primary topic → Taxonomy term → UUID' => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝primary_topic␞␟entity␜␜entity:taxonomy_term␝uuid␞␟value',
            ],
            'Primary topic → Target UUID' => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝primary_topic␞␟target_uuid',
            ],
            "Revision user → User → UUID" => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝revision_uid␞␟entity␜␜entity:user␝uuid␞␟value',
            ],
            "Revision user → Target UUID" => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝revision_uid␞␟target_uuid',
            ],
            "UUID" => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝uuid␞␟value',
            ],
          ],
          'adapters' => [],
          'host_entity_urls' => [],
        ],
        '⿲sdc_test_all_props:all-props␟test_REQUIRED_string_format_uri' => [
          'required' => TRUE,
          'instances' => [
            "Silly image 🤡 → URI" => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝field_silly_image␞␟entity␜␜entity:file␝uri␞␟value',
            ],
          ],
          'adapters' => [],
          'host_entity_urls' => [
            'Absolute URL' => [
              'sourceType' => PropSource::HostEntityUrl->value,
              'absolute' => TRUE,
            ],
          ],
        ],
        '⿲sdc_test_all_props:all-props␟test_REQUIRED_string_format_uri_reference_web_links' => [
          'required' => TRUE,
          'instances' => [
            'Check it out! → Resolved URL' => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝field_check_it_out␞␟url',
            ],
            "Silly image 🤡 → URI → Root-relative file URL" => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝field_silly_image␞␟entity␜␜entity:file␝uri␞␟url',
            ],
            "Silly image 🤡" => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝field_silly_image␞␟src_with_alternate_widths',
            ],
          ],
          'adapters' => [],
          'host_entity_urls' => [
            'Relative URL' => [
              'sourceType' => PropSource::HostEntityUrl->value,
              'absolute' => FALSE,
            ],
          ],
        ],
        '⿲sdc_test_all_props:all-props␟test_string_format_uri' => [
          'required' => FALSE,
          'instances' => [
            'Authored by → User → Picture → URI' => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝uid␞␟entity␜␜entity:user␝user_picture␞␟entity␜␜entity:file␝uri␞␟value',
            ],
            "Silly image 🤡 → URI" => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝field_silly_image␞␟entity␜␜entity:file␝uri␞␟value',
            ],
            'Revision user → User → Picture → URI' => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝revision_uid␞␟entity␜␜entity:user␝user_picture␞␟entity␜␜entity:file␝uri␞␟value',
            ],
          ],
          'adapters' => [],
          'host_entity_urls' => [
            'Absolute URL' => [
              'sourceType' => PropSource::HostEntityUrl->value,
              'absolute' => TRUE,
            ],
          ],
        ],
        '⿲sdc_test_all_props:all-props␟test_string_format_uri_image' => [
          'required' => FALSE,
          'instances' => [
            'Authored by → User → Picture → URI → Root-relative file URL' => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝uid␞␟entity␜␜entity:user␝user_picture␞␟entity␜␜entity:file␝uri␞␟url',
            ],
            'Authored by → User → Picture' => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝uid␞␟entity␜␜entity:user␝user_picture␞␟src_with_alternate_widths',
            ],
            "Silly image 🤡 → URI → Root-relative file URL" => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝field_silly_image␞␟entity␜␜entity:file␝uri␞␟url',
            ],
            "Silly image 🤡" => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝field_silly_image␞␟src_with_alternate_widths',
            ],
            'Primary topic → Taxonomy term → Revision user → User → Picture' => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝primary_topic␞␟entity␜␜entity:taxonomy_term␝revision_user␞␟entity␜␜entity:user␝user_picture␞␟src_with_alternate_widths',
            ],
            'Revision user → User → Picture → URI → Root-relative file URL' => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝revision_uid␞␟entity␜␜entity:user␝user_picture␞␟entity␜␜entity:file␝uri␞␟url',
            ],
            'Revision user → User → Picture' => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝revision_uid␞␟entity␜␜entity:user␝user_picture␞␟src_with_alternate_widths',
            ],
          ],
          'adapters' => [
            'Extract image URL' => 'image_extract_url',
          ],
          'host_entity_urls' => [],
        ],
        '⿲sdc_test_all_props:all-props␟test_string_format_uri_image_using_ref' => [
          'required' => FALSE,
          'instances' => [
            'Authored by → User → Picture → URI → Root-relative file URL' => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝uid␞␟entity␜␜entity:user␝user_picture␞␟entity␜␜entity:file␝uri␞␟url',
            ],
            'Authored by → User → Picture' => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝uid␞␟entity␜␜entity:user␝user_picture␞␟src_with_alternate_widths',
            ],
            "Silly image 🤡 → URI → Root-relative file URL" => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝field_silly_image␞␟entity␜␜entity:file␝uri␞␟url',
            ],
            "Silly image 🤡" => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝field_silly_image␞␟src_with_alternate_widths',
            ],
            'Primary topic → Taxonomy term → Revision user → User → Picture' => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝primary_topic␞␟entity␜␜entity:taxonomy_term␝revision_user␞␟entity␜␜entity:user␝user_picture␞␟src_with_alternate_widths',
            ],
            'Revision user → User → Picture → URI → Root-relative file URL' => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝revision_uid␞␟entity␜␜entity:user␝user_picture␞␟entity␜␜entity:file␝uri␞␟url',
            ],
            'Revision user → User → Picture' => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝revision_uid␞␟entity␜␜entity:user␝user_picture␞␟src_with_alternate_widths',
            ],
          ],
          'adapters' => [
            'Extract image URL' => 'image_extract_url',
          ],
          'host_entity_urls' => [],
        ],
        '⿲sdc_test_all_props:all-props␟test_string_format_uri_reference' => [
          'required' => FALSE,
          'instances' => [
            'Authored by → User → Picture → URI → Root-relative file URL' => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝uid␞␟entity␜␜entity:user␝user_picture␞␟entity␜␜entity:file␝uri␞␟url',
            ],
            'Authored by → User → Picture → URI' => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝uid␞␟entity␜␜entity:user␝user_picture␞␟entity␜␜entity:file␝uri␞␟value',
            ],
            'Authored by → User → Picture' => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝uid␞␟entity␜␜entity:user␝user_picture␞␟src_with_alternate_widths',
            ],
            'Authored by → URL' => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝uid␞␟url',
            ],
            'Check it out!' => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝field_check_it_out␞␟uri',
            ],
            'Check it out! → Resolved URL' => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝field_check_it_out␞␟url',
            ],
            'Silly image 🤡 → URI → Root-relative file URL' => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝field_silly_image␞␟entity␜␜entity:file␝uri␞␟url',
            ],
            'Silly image 🤡 → URI' => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝field_silly_image␞␟entity␜␜entity:file␝uri␞␟value',
            ],
            "Silly image 🤡" => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝field_silly_image␞␟src_with_alternate_widths',
            ],
            'Primary topic → Taxonomy term → Revision user → User → Picture' => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝primary_topic␞␟entity␜␜entity:taxonomy_term␝revision_user␞␟entity␜␜entity:user␝user_picture␞␟src_with_alternate_widths',
            ],
            'Primary topic → Taxonomy term → Revision user → URL' => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝primary_topic␞␟entity␜␜entity:taxonomy_term␝revision_user␞␟url',
            ],
            'Primary topic → URL' => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝primary_topic␞␟url',
            ],
            'Revision user → User → Picture → URI → Root-relative file URL' => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝revision_uid␞␟entity␜␜entity:user␝user_picture␞␟entity␜␜entity:file␝uri␞␟url',
            ],
            'Revision user → User → Picture → URI' => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝revision_uid␞␟entity␜␜entity:user␝user_picture␞␟entity␜␜entity:file␝uri␞␟value',
            ],
            'Revision user → User → Picture' => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝revision_uid␞␟entity␜␜entity:user␝user_picture␞␟src_with_alternate_widths',
            ],
            'Revision user → URL' => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝revision_uid␞␟url',
            ],
          ],
          'adapters' => [],
          'host_entity_urls' => [
            'Relative URL' => [
              'sourceType' => PropSource::HostEntityUrl->value,
              'absolute' => FALSE,
            ],
          ],
        ],
        '⿲sdc_test_all_props:all-props␟test_string_format_iri' => [
          'required' => FALSE,
          'instances' => [
            'Authored by → User → Picture → URI' => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝uid␞␟entity␜␜entity:user␝user_picture␞␟entity␜␜entity:file␝uri␞␟value',
            ],
            'Silly image 🤡 → URI' => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝field_silly_image␞␟entity␜␜entity:file␝uri␞␟value',
            ],
            'Revision user → User → Picture → URI' => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝revision_uid␞␟entity␜␜entity:user␝user_picture␞␟entity␜␜entity:file␝uri␞␟value',
            ],
          ],
          'adapters' => [],
          'host_entity_urls' => [
            'Absolute URL' => [
              'sourceType' => PropSource::HostEntityUrl->value,
              'absolute' => TRUE,
            ],
          ],
        ],
        '⿲sdc_test_all_props:all-props␟test_string_format_iri_reference' => [
          'required' => FALSE,
          'instances' => [
            'Authored by → User → Picture → URI → Root-relative file URL' => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝uid␞␟entity␜␜entity:user␝user_picture␞␟entity␜␜entity:file␝uri␞␟url',
            ],
            'Authored by → User → Picture → URI' => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝uid␞␟entity␜␜entity:user␝user_picture␞␟entity␜␜entity:file␝uri␞␟value',
            ],
            'Authored by → User → Picture' => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝uid␞␟entity␜␜entity:user␝user_picture␞␟src_with_alternate_widths',
            ],
            'Authored by → URL' => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝uid␞␟url',
            ],
            'Check it out!' => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝field_check_it_out␞␟uri',
            ],
            'Check it out! → Resolved URL' => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝field_check_it_out␞␟url',
            ],
            'Silly image 🤡 → URI → Root-relative file URL' => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝field_silly_image␞␟entity␜␜entity:file␝uri␞␟url',
            ],
            'Silly image 🤡 → URI' => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝field_silly_image␞␟entity␜␜entity:file␝uri␞␟value',
            ],
            "Silly image 🤡" => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝field_silly_image␞␟src_with_alternate_widths',
            ],
            'Primary topic → Taxonomy term → Revision user → User → Picture' => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝primary_topic␞␟entity␜␜entity:taxonomy_term␝revision_user␞␟entity␜␜entity:user␝user_picture␞␟src_with_alternate_widths',
            ],
            'Primary topic → Taxonomy term → Revision user → URL' => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝primary_topic␞␟entity␜␜entity:taxonomy_term␝revision_user␞␟url',
            ],
            'Primary topic → URL' => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝primary_topic␞␟url',
            ],
            'Revision user → User → Picture → URI → Root-relative file URL' => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝revision_uid␞␟entity␜␜entity:user␝user_picture␞␟entity␜␜entity:file␝uri␞␟url',
            ],
            'Revision user → User → Picture → URI' => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝revision_uid␞␟entity␜␜entity:user␝user_picture␞␟entity␜␜entity:file␝uri␞␟value',
            ],
            'Revision user → User → Picture' => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝revision_uid␞␟entity␜␜entity:user␝user_picture␞␟src_with_alternate_widths',
            ],
            'Revision user → URL' => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝revision_uid␞␟url',
            ],
          ],
          'adapters' => [],
          'host_entity_urls' => [
            'Relative URL' => [
              'sourceType' => PropSource::HostEntityUrl->value,
              'absolute' => FALSE,
            ],
          ],
        ],
        '⿲sdc_test_all_props:all-props␟test_string_format_uri_template' => [
          'required' => FALSE,
          'instances' => [],
          'adapters' => [],
          'host_entity_urls' => [],
        ],
        '⿲sdc_test_all_props:all-props␟test_string_format_json_pointer' => [
          'required' => FALSE,
          'instances' => [],
          'adapters' => [],
          'host_entity_urls' => [],
        ],
        '⿲sdc_test_all_props:all-props␟test_string_format_relative_json_pointer' => [
          'required' => FALSE,
          'instances' => [],
          'adapters' => [],
          'host_entity_urls' => [],
        ],
        '⿲sdc_test_all_props:all-props␟test_string_format_regex' => [
          'required' => FALSE,
          'instances' => [],
          'adapters' => [],
          'host_entity_urls' => [],
        ],
        '⿲sdc_test_all_props:all-props␟test_integer' => [
          'required' => FALSE,
          'instances' => [
            'Authored by → User → Picture → Height' => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝uid␞␟entity␜␜entity:user␝user_picture␞␟height',
            ],
            'Authored by → User → Picture → Width' => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝uid␞␟entity␜␜entity:user␝user_picture␞␟width',
            ],
            "Silly image 🤡 → File size" => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝field_silly_image␞␟entity␜␜entity:file␝filesize␞␟value',
            ],
            "Silly image 🤡 → Height" => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝field_silly_image␞␟height',
            ],
            "Silly image 🤡 → Width" => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝field_silly_image␞␟width',
            ],
            'Primary topic → Taxonomy term → Weight' => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝primary_topic␞␟entity␜␜entity:taxonomy_term␝weight␞␟value',
            ],
            'Revision user → User → Picture → Height' => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝revision_uid␞␟entity␜␜entity:user␝user_picture␞␟height',
            ],
            'Revision user → User → Picture → Width' => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝revision_uid␞␟entity␜␜entity:user␝user_picture␞␟width',
            ],
          ],
          'adapters' => [
            'Count days' => 'day_count',
          ],
          'host_entity_urls' => [],
        ],
        '⿲sdc_test_all_props:all-props␟test_integer_range_minimum' => [
          'required' => FALSE,
          'instances' => [],
          'adapters' => [],
          'host_entity_urls' => [],
        ],
        '⿲sdc_test_all_props:all-props␟test_integer_range_minimum_maximum_timestamps' => [
          'required' => FALSE,
          'instances' => [
            "Authored by → User → Last access" => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝uid␞␟entity␜␜entity:user␝access␞␟value',
            ],
            "Authored by → User → Changed" => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝uid␞␟entity␜␜entity:user␝changed␞␟value',
            ],
            "Authored by → User → Created" => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝uid␞␟entity␜␜entity:user␝created␞␟value',
            ],
            "Authored by → User → Last login" => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝uid␞␟entity␜␜entity:user␝login␞␟value',
            ],
            'Authored on' => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝created␞␟value',
            ],
            'Changed' => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝changed␞␟value',
            ],
            "Silly image 🤡 → Changed" => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝field_silly_image␞␟entity␜␜entity:file␝changed␞␟value',
            ],
            "Silly image 🤡 → Created" => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝field_silly_image␞␟entity␜␜entity:file␝created␞␟value',
            ],
            'Primary topic → Taxonomy term → Changed' => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝primary_topic␞␟entity␜␜entity:taxonomy_term␝changed␞␟value',
            ],
            'Primary topic → Taxonomy term → Revision create time' => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝primary_topic␞␟entity␜␜entity:taxonomy_term␝revision_created␞␟value',
            ],
            "Revision create time" => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝revision_timestamp␞␟value',
            ],
            "Revision user → User → Last access" => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝revision_uid␞␟entity␜␜entity:user␝access␞␟value',
            ],
            "Revision user → User → Changed" => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝revision_uid␞␟entity␜␜entity:user␝changed␞␟value',
            ],
            "Revision user → User → Created" => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝revision_uid␞␟entity␜␜entity:user␝created␞␟value',
            ],
            "Revision user → User → Last login" => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝revision_uid␞␟entity␜␜entity:user␝login␞␟value',
            ],
          ],
          'adapters' => [],
          'host_entity_urls' => [],
        ],
        '⿲sdc_test_all_props:all-props␟test_integer_by_the_dozen' => [
          'required' => FALSE,
          'instances' => [],
          'adapters' => [],
          'host_entity_urls' => [],
        ],
        '⿲sdc_test_all_props:all-props␟test_number' => [
          'required' => FALSE,
          'instances' => [
            'Authored by → User → Picture → Height' => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝uid␞␟entity␜␜entity:user␝user_picture␞␟height',
            ],
            'Authored by → User → Picture → Width' => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝uid␞␟entity␜␜entity:user␝user_picture␞␟width',
            ],
            "Silly image 🤡 → File size" => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝field_silly_image␞␟entity␜␜entity:file␝filesize␞␟value',
            ],
            "Silly image 🤡 → Height" => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝field_silly_image␞␟height',
            ],
            "Silly image 🤡 → Width" => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝field_silly_image␞␟width',
            ],
            'Primary topic → Taxonomy term → Weight' => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝primary_topic␞␟entity␜␜entity:taxonomy_term␝weight␞␟value',
            ],
            'Revision user → User → Picture → Height' => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝revision_uid␞␟entity␜␜entity:user␝user_picture␞␟height',
            ],
            'Revision user → User → Picture → Width' => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝revision_uid␞␟entity␜␜entity:user␝user_picture␞␟width',
            ],
          ],
          'adapters' => [],
          'host_entity_urls' => [],
        ],
        '⿲sdc_test_all_props:all-props␟test_object_drupal_image' => [
          'required' => FALSE,
          'instances' => [
            'Authored by → User → Picture' => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝uid␞␟entity␜␜entity:user␝user_picture␞␟{src↠src_with_alternate_widths,alt↠alt,width↠width,height↠height}',
            ],
            "Silly image 🤡" => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝field_silly_image␞␟{src↠src_with_alternate_widths,alt↠alt,width↠width,height↠height}',
            ],
            'Primary topic → Taxonomy term → Revision user' => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝primary_topic␞␟entity␜␜entity:taxonomy_term␝revision_user␞␟{src↝entity␜␜entity:user␝user_picture␞␟src_with_alternate_widths,alt↝entity␜␜entity:user␝name␞␟value,width↝entity␜␜entity:user␝user_picture␞␟width,height↝entity␜␜entity:user␝user_picture␞␟height}',
            ],
            'Revision user → User → Picture' => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝revision_uid␞␟entity␜␜entity:user␝user_picture␞␟{src↠src_with_alternate_widths,alt↠alt,width↠width,height↠height}',
            ],
          ],
          'adapters' => [
            'Apply image style' => 'image_apply_style',
            'Make relative image URL absolute' => 'image_url_rel_to_abs',
          ],
          'host_entity_urls' => [],
        ],
        '⿲sdc_test_all_props:all-props␟test_object_drupal_image_ARRAY' => [
          'required' => FALSE,
          'instances' => [
            "field_before_and_after" => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝field_before_and_after␞␟{src↠src_with_alternate_widths,alt↠alt,width↠width,height↠height}',
            ],
          ],
          'adapters' => [],
          'host_entity_urls' => [],
        ],
        '⿲sdc_test_all_props:all-props␟test_object_drupal_video' => [
          'required' => FALSE,
          'instances' => [],
          'adapters' => [],
          'host_entity_urls' => [],
        ],
        '⿲sdc_test_all_props:all-props␟test_object_drupal_date_range' => [
          'required' => FALSE,
          'instances' => [
            "field_event_duration" => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝field_event_duration␞␟{from↠value,to↠end_value}',
            ],
          ],
          'adapters' => [],
          'host_entity_urls' => [],
        ],
        '⿲sdc_test_all_props:all-props␟test_string_html_inline' => [
          'required' => FALSE,
          'instances' => [],
          'adapters' => [],
          'host_entity_urls' => [],
        ],
        '⿲sdc_test_all_props:all-props␟test_string_html_block' => [
          'required' => FALSE,
          'instances' => [
            "field_wall_of_text" => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝field_wall_of_text␞␟processed',
            ],
            'Primary topic → Taxonomy term → Some text field' => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝primary_topic␞␟entity␜␜entity:taxonomy_term:vocab_2␝some_text␞␟processed',
            ],
            'Primary topic → Taxonomy term → Description' => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝primary_topic␞␟entity␜␜entity:taxonomy_term␝description␞␟processed',
            ],
          ],
          'adapters' => [],
          'host_entity_urls' => [],
        ],
        '⿲sdc_test_all_props:all-props␟test_string_html' => [
          'required' => FALSE,
          'instances' => [
            "field_wall_of_text" => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝field_wall_of_text␞␟processed',
            ],
            'Primary topic → Taxonomy term → Some text field' => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝primary_topic␞␟entity␜␜entity:taxonomy_term:vocab_2␝some_text␞␟processed',
            ],
            'Primary topic → Taxonomy term → Description' => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝primary_topic␞␟entity␜␜entity:taxonomy_term␝description␞␟processed',
            ],
          ],
          'adapters' => [],
          'host_entity_urls' => [],
        ],
        '⿲sdc_test_all_props:all-props␟test_REQUIRED_string_html_inline' => [
          'required' => TRUE,
          'instances' => [],
          'adapters' => [],
          'host_entity_urls' => [],
        ],
        '⿲sdc_test_all_props:all-props␟test_REQUIRED_string_html_block' => [
          'required' => TRUE,
          'instances' => [
            "field_wall_of_text" => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝field_wall_of_text␞␟processed',
            ],
          ],
          'adapters' => [],
          'host_entity_urls' => [],
        ],
        '⿲sdc_test_all_props:all-props␟test_REQUIRED_string_html' => [
          'required' => TRUE,
          'instances' => [
            "field_wall_of_text" => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝field_wall_of_text␞␟processed',
            ],
          ],
          'adapters' => [],
          'host_entity_urls' => [],
        ],
        '⿲sdc_test_all_props:all-props␟test_array_integer' => [
          'required' => FALSE,
          'instances' => [
            "field_screenshots → File size" => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝field_screenshots␞␟entity␜␜entity:file␝filesize␞␟value',
            ],
            "field_screenshots → Height" => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝field_screenshots␞␟height',
            ],
            "field_screenshots → Width" => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝field_screenshots␞␟width',
            ],
            'Tags → Taxonomy term → Weight' => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝field_tags␞␟entity␜␜entity:taxonomy_term␝weight␞␟value',
            ],
          ],
          'adapters' => [],
          'host_entity_urls' => [],
        ],
        '⿲sdc_test_all_props:all-props␟test_array_integer_minItems' => [
          'required' => FALSE,
          'instances' => [],
          'adapters' => [],
          'host_entity_urls' => [],
        ],
        '⿲sdc_test_all_props:all-props␟test_array_integer_maxItems' => [
          'required' => FALSE,
          'instances' => [
            "field_before_and_after → File size" => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝field_before_and_after␞␟entity␜␜entity:file␝filesize␞␟value',
            ],
            "field_before_and_after → Height" => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝field_before_and_after␞␟height',
            ],
            "field_before_and_after → Width" => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:foo␝field_before_and_after␞␟width',
            ],
          ],
          'adapters' => [],
          'host_entity_urls' => [],
        ],
        '⿲sdc_test_all_props:all-props␟test_array_integer_minItemsMultiple' => [
          'required' => FALSE,
          'instances' => [],
          'adapters' => [],
          'host_entity_urls' => [],
        ],
        '⿲sdc_test_all_props:all-props␟test_array_integer_minMaxItems' => [
          'required' => FALSE,
          'instances' => [],
          'adapters' => [],
          'host_entity_urls' => [],
        ],
      ],
    ];
  }

}
