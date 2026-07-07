<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\ShapeMatcher;

use Drupal\canvas\JsonSchemaInterpreter\JsonSchemaObjectRef;
use Drupal\canvas\Plugin\Adapter\AdapterInterface;
use Drupal\canvas\PropSource\EntityFieldPropSource;
use Drupal\canvas\PropSource\HostEntityPropSource;
use Drupal\canvas\PropSource\HostEntityUrlPropSource;
use Drupal\canvas\PropSource\PropSource;
use Drupal\canvas\ShapeMatcher\PropSourceSuggester;
use Drupal\Core\Entity\TypedData\EntityDataDefinition;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\Plugin\Component;
use Drupal\Core\Theme\Component\ComponentMetadata;
use Drupal\Core\Theme\ComponentPluginManager;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\link\LinkItemInterface;
use Drupal\link\LinkTitleVisibility;
use Drupal\node\Entity\NodeType;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\field\Traits\EntityReferenceFieldCreationTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests Prop Source Suggester.
 *
 * @phpstan-import-type HostEntityUrlPropSourceArray from \Drupal\canvas\PropSource\PropSourceBase
 * @phpstan-import-type HostEntityPropSourceArray from \Drupal\canvas\PropSource\PropSourceBase
 */
#[CoversClass(PropSourceSuggester::class)]
#[RunTestsInSeparateProcesses]
#[Group('canvas')]
#[Group('canvas_shape_matching')]
class PropSourceSuggesterTest extends CanvasKernelTestBase {

  use EntityReferenceFieldCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    // The module providing the sample SDC to test all JSON schema types.
    'sdc_test_all_props',
    // All other core modules providing field types (in addition to the ones
    // installed by CanvasKernelTestBase).
    'comment',
    'datetime_range',
    'telephone',
    // Create sample configurable fields on the `node` entity type.
    'node',
    'field',
    'taxonomy',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
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
        'title' => LinkTitleVisibility::Optional->value,
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
   * Tests .
   *
   * @param array<string, array{'required': bool, 'entity-field': array<string, string>, 'adapter': array<string, string>, 'host-entity-url': array<string, HostEntityUrlPropSourceArray>, 'host-entity': array<string, HostEntityPropSourceArray>}> $expected
   */
  #[DataProvider('provider')]
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
    foreach (\array_keys($expected) as $prop_name) {
      $this->assertSame(
        $expected[$prop_name],
        [
          'required' => $suggestions[$prop_name]['required'],
          PropSource::EntityField->value => \array_map(fn (EntityFieldPropSource $s): array => $s->toArray(), $suggestions[$prop_name][PropSource::EntityField->value]),
          PropSource::Adapter->value => \array_map(fn (AdapterInterface $a): string => $a->getPluginId(), $suggestions[$prop_name][PropSource::Adapter->value]),
          PropSource::HostEntityUrl->value => \array_map(fn (HostEntityUrlPropSource $s): array => $s->toArray(), $suggestions[$prop_name][PropSource::HostEntityUrl->value]),
          PropSource::HostEntity->value => \array_map(fn (HostEntityPropSource $s): array => $s->toArray(), $suggestions[$prop_name][PropSource::HostEntity->value]),
        ],
        "Unexpected prop source suggestion for $prop_name"
      );
    }

    // Finally, the set of expectations must be complete.
    $this->assertSame(\array_keys($expected), \array_keys($suggestions));
  }

  public static function provider(): \Generator {
    yield 'a component with a required "image" object-shaped prop' => [
      'canvas_test_sdc:image',
      'entity:node:foo',
      [
        '⿲canvas_test_sdc:image␟image' => [
          'required' => TRUE,
          PropSource::EntityField->value => [
            "Silly image 🤡" => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝field_silly_image␞␟{src↠src_with_alternate_widths,alt↠alt,width↠width,height↠height}',
            ],
          ],
          PropSource::Adapter->value => [
            'Apply image style' => 'image_apply_style',
            'Make relative image URL absolute' => 'image_url_rel_to_abs',
          ],
          PropSource::HostEntityUrl->value => [],
          PropSource::HostEntity->value => [],
        ],
      ],
    ];

    yield 'a component with an optional "image" object-shaped-prop' => [
      'canvas_test_sdc:image-optional-with-example',
      'entity:node:foo',
      [
        '⿲canvas_test_sdc:image-optional-with-example␟image' => [
          'required' => FALSE,
          PropSource::EntityField->value => [
            'Authored by → User → Picture' => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝uid␞␟entity␜␜entity:user␝user_picture␞␟{src↠src_with_alternate_widths,alt↠alt,width↠width,height↠height}',
            ],
            'Silly image 🤡' => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝field_silly_image␞␟{src↠src_with_alternate_widths,alt↠alt,width↠width,height↠height}',
            ],
            'Primary topic → Taxonomy term → Revision user' => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝primary_topic␞␟entity␜␜entity:taxonomy_term␝revision_user␞␟{src↝entity␜␜entity:user␝user_picture␞␟src_with_alternate_widths,alt↝entity␜␜entity:user␝name␞␟value,width↝entity␜␜entity:user␝user_picture␞␟width,height↝entity␜␜entity:user␝user_picture␞␟height}',
            ],
            'Revision user → User → Picture' => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝revision_uid␞␟entity␜␜entity:user␝user_picture␞␟{src↠src_with_alternate_widths,alt↠alt,width↠width,height↠height}',
            ],
          ],
          PropSource::Adapter->value => [
            'Apply image style' => 'image_apply_style',
            'Make relative image URL absolute' => 'image_url_rel_to_abs',
          ],
          PropSource::HostEntityUrl->value => [],
          PropSource::HostEntity->value => [],
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
          PropSource::EntityField->value => [
            "Silly image 🤡" => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝field_silly_image␞␟{src↠src_with_alternate_widths,alt↠alt,width↠width,height↠height}',
            ],
          ],
          PropSource::Adapter->value => [
            'Apply image style' => 'image_apply_style',
            'Make relative image URL absolute' => 'image_url_rel_to_abs',
          ],
          PropSource::HostEntityUrl->value => [],
          PropSource::HostEntity->value => [],
        ],
        '⿲canvas_test_sdc:image-srcset-candidate-template-uri␟srcSetCandidateTemplate' => [
          'required' => FALSE,
          PropSource::EntityField->value => [
            'Authored by → User → Picture → srcset template' => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝uid␞␟entity␜␜entity:user␝user_picture␞␟srcset_candidate_uri_template',
            ],
            'Silly image 🤡 → srcset template' => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝field_silly_image␞␟srcset_candidate_uri_template',
            ],
            'Revision user → User → Picture → srcset template' => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝revision_uid␞␟entity␜␜entity:user␝user_picture␞␟srcset_candidate_uri_template',
            ],
          ],
          PropSource::Adapter->value => [],
          PropSource::HostEntityUrl->value => [],
          PropSource::HostEntity->value => [],
        ],
      ],
    ];

    yield 'the tags component' => [
      'canvas_test_sdc:tags',
      'entity:node:foo',
      [
        '⿲canvas_test_sdc:tags␟tags' => [
          'required' => FALSE,
          PropSource::EntityField->value => [
            'field_screenshots → Alternative text' => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝field_screenshots␞␟alt',
            ],
            'field_screenshots → Title' => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝field_screenshots␞␟title',
            ],
            'Tags → Taxonomy term → Name' => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝field_tags␞␟entity␜␜entity:taxonomy_term␝name␞␟value',
            ],
          ],
          PropSource::Adapter->value => [],
          PropSource::HostEntityUrl->value => [],
          PropSource::HostEntity->value => [],
        ],
      ],
    ];

    yield 'a component with a `type: string, format: date`-shaped prop' => [
      'canvas_test_sdc:date',
      'entity:node:foo',
      [
        '⿲canvas_test_sdc:date␟date' => [
          'required' => FALSE,
          PropSource::EntityField->value => [
            'Authored on' => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node␝created␞␟value',
              PropSource::Adapter->value => 'unix_to_date',
            ],
            'field_event_duration → End date value' => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝field_event_duration␞␟end_value',
            ],
            'field_event_duration' => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝field_event_duration␞␟value',
            ],
            'Changed' => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node␝changed␞␟value',
              PropSource::Adapter->value => 'unix_to_date',
            ],
          ],
          PropSource::Adapter->value => [
            'UNIX timestamp to date' => 'unix_to_date',
          ],
          PropSource::HostEntityUrl->value => [],
          PropSource::HostEntity->value => [],
        ],
        '⿲canvas_test_sdc:date␟caption' => [
          'required' => FALSE,
          PropSource::EntityField->value => [
            'Title' => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝title␞␟value',
            ],
            'Authored by → User → Name' => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝uid␞␟entity␜␜entity:user␝name␞␟value',
            ],
            'Authored by → User → Picture → Alternative text' => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝uid␞␟entity␜␜entity:user␝user_picture␞␟alt',
            ],
            'Authored by → User → Picture → Title' => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝uid␞␟entity␜␜entity:user␝user_picture␞␟title',
            ],
            'Check it out! → Link text' => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝field_check_it_out␞␟title',
            ],
            'Silly image 🤡 → Alternative text' => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝field_silly_image␞␟alt',
            ],
            'Silly image 🤡 → Title' => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝field_silly_image␞␟title',
            ],
            'Primary topic → Taxonomy term → Name' => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝primary_topic␞␟entity␜␜entity:taxonomy_term␝name␞␟value',
            ],
            'Revision user → User → Name' => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝revision_uid␞␟entity␜␜entity:user␝name␞␟value',
            ],
            'Revision user → User → Picture → Alternative text' => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝revision_uid␞␟entity␜␜entity:user␝user_picture␞␟alt',
            ],
            'Revision user → User → Picture → Title' => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝revision_uid␞␟entity␜␜entity:user␝user_picture␞␟title',
            ],
          ],
          PropSource::Adapter->value => [],
          PropSource::HostEntityUrl->value => [],
          PropSource::HostEntity->value => [],
        ],
      ],
    ];

    yield 'the "ALL PROPS" test component' => [
      'sdc_test_all_props:all-props',
      'entity:node:foo',
      [
        '⿲sdc_test_all_props:all-props␟test_bool_default_false' => [
          'required' => FALSE,
          PropSource::EntityField->value => [
            "Authored by → User → User status" => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝uid␞␟entity␜␜entity:user␝status␞␟value',
            ],
            "Published" => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝status␞␟value',
            ],
            "Silly image 🤡 → Status" => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝field_silly_image␞␟entity␜␜entity:file␝status␞␟value',
            ],
            'Primary topic → Taxonomy term → Published' => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝primary_topic␞␟entity␜␜entity:taxonomy_term␝status␞␟value',
            ],
            "Revision user → User → User status" => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝revision_uid␞␟entity␜␜entity:user␝status␞␟value',
            ],
          ],
          PropSource::Adapter->value => [],
          PropSource::HostEntityUrl->value => [],
          PropSource::HostEntity->value => [],
        ],
        '⿲sdc_test_all_props:all-props␟test_bool_default_true' => [
          'required' => FALSE,
          PropSource::EntityField->value => [
            "Authored by → User → User status" => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝uid␞␟entity␜␜entity:user␝status␞␟value',
            ],
            "Published" => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝status␞␟value',
            ],
            "Silly image 🤡 → Status" => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝field_silly_image␞␟entity␜␜entity:file␝status␞␟value',
            ],
            'Primary topic → Taxonomy term → Published' => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝primary_topic␞␟entity␜␜entity:taxonomy_term␝status␞␟value',
            ],
            "Revision user → User → User status" => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝revision_uid␞␟entity␜␜entity:user␝status␞␟value',
            ],
          ],
          PropSource::Adapter->value => [],
          PropSource::HostEntityUrl->value => [],
          PropSource::HostEntity->value => [],
        ],
        '⿲sdc_test_all_props:all-props␟test_string' => [
          'required' => FALSE,
          PropSource::EntityField->value => [
            "Title" => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝title␞␟value',
            ],
            'Authored by → User → Name' => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝uid␞␟entity␜␜entity:user␝name␞␟value',
            ],
            'Authored by → User → Picture → Alternative text' => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝uid␞␟entity␜␜entity:user␝user_picture␞␟alt',
            ],
            'Authored by → User → Picture → Title' => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝uid␞␟entity␜␜entity:user␝user_picture␞␟title',
            ],
            'Check it out! → Link text' => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝field_check_it_out␞␟title',
            ],
            "Silly image 🤡 → Alternative text" => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝field_silly_image␞␟alt',
            ],
            "Silly image 🤡 → Title" => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝field_silly_image␞␟title',
            ],
            'Primary topic → Taxonomy term → Name' => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝primary_topic␞␟entity␜␜entity:taxonomy_term␝name␞␟value',
            ],
            'Revision user → User → Name' => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝revision_uid␞␟entity␜␜entity:user␝name␞␟value',
            ],
            'Revision user → User → Picture → Alternative text' => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝revision_uid␞␟entity␜␜entity:user␝user_picture␞␟alt',
            ],
            'Revision user → User → Picture → Title' => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝revision_uid␞␟entity␜␜entity:user␝user_picture␞␟title',
            ],
          ],
          PropSource::Adapter->value => [],
          PropSource::HostEntityUrl->value => [],
          PropSource::HostEntity->value => [],
        ],
        '⿲sdc_test_all_props:all-props␟test_string_multiline' => [
          'required' => FALSE,
          PropSource::EntityField->value => [],
          PropSource::Adapter->value => [],
          PropSource::HostEntityUrl->value => [],
          PropSource::HostEntity->value => [],
        ],
        '⿲sdc_test_all_props:all-props␟test_REQUIRED_string' => [
          'required' => TRUE,
          PropSource::EntityField->value => [
            "Title" => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝title␞␟value',
            ],
          ],
          PropSource::Adapter->value => [],
          PropSource::HostEntityUrl->value => [],
          PropSource::HostEntity->value => [],
        ],
        '⿲sdc_test_all_props:all-props␟test_string_enum' => [
          'required' => FALSE,
          PropSource::EntityField->value => [],
          PropSource::Adapter->value => [],
          PropSource::HostEntityUrl->value => [],
          PropSource::HostEntity->value => [],
        ],
        '⿲sdc_test_all_props:all-props␟test_integer_enum' => [
          'required' => FALSE,
          PropSource::EntityField->value => [],
          PropSource::Adapter->value => [],
          PropSource::HostEntityUrl->value => [],
          PropSource::HostEntity->value => [],
        ],
        '⿲sdc_test_all_props:all-props␟test_string_format_date_time' => [
          'required' => FALSE,
          PropSource::EntityField->value => [
            "field_event_duration → End date value" => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝field_event_duration␞␟end_value',
            ],
            "field_event_duration" => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝field_event_duration␞␟value',
            ],
          ],
          PropSource::Adapter->value => [],
          PropSource::HostEntityUrl->value => [],
          PropSource::HostEntity->value => [],
        ],
        '⿲sdc_test_all_props:all-props␟test_string_format_date' => [
          'required' => FALSE,
          PropSource::EntityField->value => [
            'Authored on' => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node␝created␞␟value',
              PropSource::Adapter->value => 'unix_to_date',
            ],
            "field_event_duration → End date value" => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝field_event_duration␞␟end_value',
            ],
            "field_event_duration" => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝field_event_duration␞␟value',
            ],
            'Changed' => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node␝changed␞␟value',
              PropSource::Adapter->value => 'unix_to_date',
            ],
          ],
          PropSource::Adapter->value => [
            'UNIX timestamp to date' => 'unix_to_date',
          ],
          PropSource::HostEntityUrl->value => [],
          PropSource::HostEntity->value => [],
        ],
        '⿲sdc_test_all_props:all-props␟test_string_format_time' => [
          'required' => FALSE,
          PropSource::EntityField->value => [],
          PropSource::Adapter->value => [],
          PropSource::HostEntityUrl->value => [],
          PropSource::HostEntity->value => [],
        ],
        '⿲sdc_test_all_props:all-props␟test_string_format_duration' => [
          'required' => FALSE,
          PropSource::EntityField->value => [],
          PropSource::Adapter->value => [],
          PropSource::HostEntityUrl->value => [],
          PropSource::HostEntity->value => [],
        ],
        '⿲sdc_test_all_props:all-props␟test_string_format_email' => [
          'required' => FALSE,
          PropSource::EntityField->value => [
            "Authored by → User → Initial email" => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝uid␞␟entity␜␜entity:user␝init␞␟value',
            ],
            "Authored by → User → Email" => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝uid␞␟entity␜␜entity:user␝mail␞␟value',
            ],
            "Revision user → User → Initial email" => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝revision_uid␞␟entity␜␜entity:user␝init␞␟value',
            ],
            "Revision user → User → Email" => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝revision_uid␞␟entity␜␜entity:user␝mail␞␟value',
            ],
          ],
          PropSource::Adapter->value => [],
          PropSource::HostEntityUrl->value => [],
          PropSource::HostEntity->value => [],
        ],
        '⿲sdc_test_all_props:all-props␟test_string_format_idn_email' => [
          'required' => FALSE,
          PropSource::EntityField->value => [
            "Authored by → User → Initial email" => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝uid␞␟entity␜␜entity:user␝init␞␟value',
            ],
            "Authored by → User → Email" => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝uid␞␟entity␜␜entity:user␝mail␞␟value',
            ],
            "Revision user → User → Initial email" => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝revision_uid␞␟entity␜␜entity:user␝init␞␟value',
            ],
            "Revision user → User → Email" => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝revision_uid␞␟entity␜␜entity:user␝mail␞␟value',
            ],
          ],
          PropSource::Adapter->value => [],
          PropSource::HostEntityUrl->value => [],
          PropSource::HostEntity->value => [],
        ],
        '⿲sdc_test_all_props:all-props␟test_string_format_hostname' => [
          'required' => FALSE,
          PropSource::EntityField->value => [],
          PropSource::Adapter->value => [],
          PropSource::HostEntityUrl->value => [],
          PropSource::HostEntity->value => [],
        ],
        '⿲sdc_test_all_props:all-props␟test_string_format_idn_hostname' => [
          'required' => FALSE,
          PropSource::EntityField->value => [],
          PropSource::Adapter->value => [],
          PropSource::HostEntityUrl->value => [],
          PropSource::HostEntity->value => [],
        ],
        '⿲sdc_test_all_props:all-props␟test_string_format_ipv4' => [
          'required' => FALSE,
          PropSource::EntityField->value => [],
          PropSource::Adapter->value => [],
          PropSource::HostEntityUrl->value => [],
          PropSource::HostEntity->value => [],
        ],
        '⿲sdc_test_all_props:all-props␟test_string_format_ipv6' => [
          'required' => FALSE,
          PropSource::EntityField->value => [],
          PropSource::Adapter->value => [],
          PropSource::HostEntityUrl->value => [],
          PropSource::HostEntity->value => [],
        ],
        '⿲sdc_test_all_props:all-props␟test_string_format_uuid' => [
          'required' => FALSE,
          PropSource::EntityField->value => [
            "Authored by → User → UUID" => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝uid␞␟entity␜␜entity:user␝uuid␞␟value',
            ],
            "Authored by → Target UUID" => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝uid␞␟target_uuid',
            ],
            "Silly image 🤡 → UUID" => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝field_silly_image␞␟entity␜␜entity:file␝uuid␞␟value',
            ],
            'Primary topic → Taxonomy term → Revision user → Target UUID' => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝primary_topic␞␟entity␜␜entity:taxonomy_term␝revision_user␞␟target_uuid',
            ],
            'Primary topic → Taxonomy term → UUID' => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝primary_topic␞␟entity␜␜entity:taxonomy_term␝uuid␞␟value',
            ],
            'Primary topic → Target UUID' => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝primary_topic␞␟target_uuid',
            ],
            "Revision user → User → UUID" => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝revision_uid␞␟entity␜␜entity:user␝uuid␞␟value',
            ],
            "Revision user → Target UUID" => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝revision_uid␞␟target_uuid',
            ],
            "UUID" => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝uuid␞␟value',
            ],
          ],
          PropSource::Adapter->value => [],
          PropSource::HostEntityUrl->value => [],
          PropSource::HostEntity->value => [],
        ],
        '⿲sdc_test_all_props:all-props␟test_REQUIRED_string_format_uri' => [
          'required' => TRUE,
          PropSource::EntityField->value => [
            "Silly image 🤡 → URI" => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝field_silly_image␞␟entity␜␜entity:file␝uri␞␟value',
            ],
          ],
          PropSource::Adapter->value => [],
          PropSource::HostEntityUrl->value => [
            'Absolute URL' => [
              'sourceType' => PropSource::HostEntityUrl->value,
              'absolute' => TRUE,
            ],
          ],
          PropSource::HostEntity->value => [],
        ],
        '⿲sdc_test_all_props:all-props␟test_REQUIRED_string_format_uri_reference_web_links' => [
          'required' => TRUE,
          PropSource::EntityField->value => [
            'Check it out! → Resolved URL' => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝field_check_it_out␞␟url',
            ],
            "Silly image 🤡 → URI → Root-relative file URL" => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝field_silly_image␞␟entity␜␜entity:file␝uri␞␟url',
            ],
            "Silly image 🤡" => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝field_silly_image␞␟src_with_alternate_widths',
            ],
          ],
          PropSource::Adapter->value => [],
          PropSource::HostEntityUrl->value => [
            'Relative URL' => [
              'sourceType' => PropSource::HostEntityUrl->value,
              'absolute' => FALSE,
            ],
          ],
          PropSource::HostEntity->value => [],
        ],
        '⿲sdc_test_all_props:all-props␟test_string_format_uri' => [
          'required' => FALSE,
          PropSource::EntityField->value => [
            'Authored by → User → Picture → URI' => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝uid␞␟entity␜␜entity:user␝user_picture␞␟entity␜␜entity:file␝uri␞␟value',
            ],
            "Silly image 🤡 → URI" => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝field_silly_image␞␟entity␜␜entity:file␝uri␞␟value',
            ],
            'Revision user → User → Picture → URI' => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝revision_uid␞␟entity␜␜entity:user␝user_picture␞␟entity␜␜entity:file␝uri␞␟value',
            ],
          ],
          PropSource::Adapter->value => [],
          PropSource::HostEntityUrl->value => [
            'Absolute URL' => [
              'sourceType' => PropSource::HostEntityUrl->value,
              'absolute' => TRUE,
            ],
          ],
          PropSource::HostEntity->value => [],
        ],
        '⿲sdc_test_all_props:all-props␟test_string_format_uri_image' => [
          'required' => FALSE,
          PropSource::EntityField->value => [
            'Authored by → User → Picture → URI → Root-relative file URL' => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝uid␞␟entity␜␜entity:user␝user_picture␞␟entity␜␜entity:file␝uri␞␟url',
            ],
            'Authored by → User → Picture' => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝uid␞␟entity␜␜entity:user␝user_picture␞␟src_with_alternate_widths',
            ],
            "Silly image 🤡 → URI → Root-relative file URL" => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝field_silly_image␞␟entity␜␜entity:file␝uri␞␟url',
            ],
            "Silly image 🤡" => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝field_silly_image␞␟src_with_alternate_widths',
            ],
            'Primary topic → Taxonomy term → Revision user → User → Picture' => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝primary_topic␞␟entity␜␜entity:taxonomy_term␝revision_user␞␟entity␜␜entity:user␝user_picture␞␟src_with_alternate_widths',
            ],
            'Revision user → User → Picture → URI → Root-relative file URL' => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝revision_uid␞␟entity␜␜entity:user␝user_picture␞␟entity␜␜entity:file␝uri␞␟url',
            ],
            'Revision user → User → Picture' => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝revision_uid␞␟entity␜␜entity:user␝user_picture␞␟src_with_alternate_widths',
            ],
          ],
          PropSource::Adapter->value => [
            'Extract image URL' => 'image_extract_url',
          ],
          PropSource::HostEntityUrl->value => [],
          PropSource::HostEntity->value => [],
        ],
        '⿲sdc_test_all_props:all-props␟test_string_format_uri_image_using_ref' => [
          'required' => FALSE,
          PropSource::EntityField->value => [
            'Authored by → User → Picture → URI → Root-relative file URL' => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝uid␞␟entity␜␜entity:user␝user_picture␞␟entity␜␜entity:file␝uri␞␟url',
            ],
            'Authored by → User → Picture' => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝uid␞␟entity␜␜entity:user␝user_picture␞␟src_with_alternate_widths',
            ],
            "Silly image 🤡 → URI → Root-relative file URL" => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝field_silly_image␞␟entity␜␜entity:file␝uri␞␟url',
            ],
            "Silly image 🤡" => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝field_silly_image␞␟src_with_alternate_widths',
            ],
            'Primary topic → Taxonomy term → Revision user → User → Picture' => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝primary_topic␞␟entity␜␜entity:taxonomy_term␝revision_user␞␟entity␜␜entity:user␝user_picture␞␟src_with_alternate_widths',
            ],
            'Revision user → User → Picture → URI → Root-relative file URL' => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝revision_uid␞␟entity␜␜entity:user␝user_picture␞␟entity␜␜entity:file␝uri␞␟url',
            ],
            'Revision user → User → Picture' => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝revision_uid␞␟entity␜␜entity:user␝user_picture␞␟src_with_alternate_widths',
            ],
          ],
          PropSource::Adapter->value => [
            'Extract image URL' => 'image_extract_url',
          ],
          PropSource::HostEntityUrl->value => [],
          PropSource::HostEntity->value => [],
        ],
        '⿲sdc_test_all_props:all-props␟test_string_format_uri_public_stream_wrapper' => [
          'required' => FALSE,
          PropSource::EntityField->value => [
            'Authored by → User → Picture → URI' => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝uid␞␟entity␜␜entity:user␝user_picture␞␟entity␜␜entity:file␝uri␞␟value',
            ],
            "Silly image 🤡 → URI" => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝field_silly_image␞␟entity␜␜entity:file␝uri␞␟value',
            ],
            'Revision user → User → Picture → URI' => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝revision_uid␞␟entity␜␜entity:user␝user_picture␞␟entity␜␜entity:file␝uri␞␟value',
            ],
          ],
          PropSource::Adapter->value => [],
          PropSource::HostEntityUrl->value => [],
          PropSource::HostEntity->value => [],
        ],
        '⿲sdc_test_all_props:all-props␟test_string_format_uri_public_stream_wrapper_using_ref' => [
          'required' => FALSE,
          PropSource::EntityField->value => [
            'Authored by → User → Picture → URI' => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝uid␞␟entity␜␜entity:user␝user_picture␞␟entity␜␜entity:file␝uri␞␟value',
            ],
            "Silly image 🤡 → URI" => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝field_silly_image␞␟entity␜␜entity:file␝uri␞␟value',
            ],
            'Revision user → User → Picture → URI' => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝revision_uid␞␟entity␜␜entity:user␝user_picture␞␟entity␜␜entity:file␝uri␞␟value',
            ],
          ],
          PropSource::Adapter->value => [],
          PropSource::HostEntityUrl->value => [],
          PropSource::HostEntity->value => [],
        ],
        '⿲sdc_test_all_props:all-props␟test_string_format_uri_reference' => [
          'required' => FALSE,
          PropSource::EntityField->value => [
            'Authored by → User → Picture → URI → Root-relative file URL' => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝uid␞␟entity␜␜entity:user␝user_picture␞␟entity␜␜entity:file␝uri␞␟url',
            ],
            'Authored by → User → Picture → URI' => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝uid␞␟entity␜␜entity:user␝user_picture␞␟entity␜␜entity:file␝uri␞␟value',
            ],
            'Authored by → User → Picture' => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝uid␞␟entity␜␜entity:user␝user_picture␞␟src_with_alternate_widths',
            ],
            'Authored by → URL' => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝uid␞␟url',
            ],
            'Check it out!' => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝field_check_it_out␞␟uri',
            ],
            'Check it out! → Resolved URL' => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝field_check_it_out␞␟url',
            ],
            'Silly image 🤡 → URI → Root-relative file URL' => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝field_silly_image␞␟entity␜␜entity:file␝uri␞␟url',
            ],
            'Silly image 🤡 → URI' => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝field_silly_image␞␟entity␜␜entity:file␝uri␞␟value',
            ],
            "Silly image 🤡" => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝field_silly_image␞␟src_with_alternate_widths',
            ],
            'Primary topic → Taxonomy term → Revision user → User → Picture' => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝primary_topic␞␟entity␜␜entity:taxonomy_term␝revision_user␞␟entity␜␜entity:user␝user_picture␞␟src_with_alternate_widths',
            ],
            'Primary topic → Taxonomy term → Revision user → URL' => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝primary_topic␞␟entity␜␜entity:taxonomy_term␝revision_user␞␟url',
            ],
            'Primary topic → URL' => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝primary_topic␞␟url',
            ],
            'Revision user → User → Picture → URI → Root-relative file URL' => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝revision_uid␞␟entity␜␜entity:user␝user_picture␞␟entity␜␜entity:file␝uri␞␟url',
            ],
            'Revision user → User → Picture → URI' => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝revision_uid␞␟entity␜␜entity:user␝user_picture␞␟entity␜␜entity:file␝uri␞␟value',
            ],
            'Revision user → User → Picture' => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝revision_uid␞␟entity␜␜entity:user␝user_picture␞␟src_with_alternate_widths',
            ],
            'Revision user → URL' => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝revision_uid␞␟url',
            ],
          ],
          PropSource::Adapter->value => [],
          PropSource::HostEntityUrl->value => [
            'Relative URL' => [
              'sourceType' => PropSource::HostEntityUrl->value,
              'absolute' => FALSE,
            ],
          ],
          PropSource::HostEntity->value => [],
        ],
        '⿲sdc_test_all_props:all-props␟test_string_format_iri' => [
          'required' => FALSE,
          PropSource::EntityField->value => [
            'Authored by → User → Picture → URI' => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝uid␞␟entity␜␜entity:user␝user_picture␞␟entity␜␜entity:file␝uri␞␟value',
            ],
            'Silly image 🤡 → URI' => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝field_silly_image␞␟entity␜␜entity:file␝uri␞␟value',
            ],
            'Revision user → User → Picture → URI' => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝revision_uid␞␟entity␜␜entity:user␝user_picture␞␟entity␜␜entity:file␝uri␞␟value',
            ],
          ],
          PropSource::Adapter->value => [],
          PropSource::HostEntityUrl->value => [
            'Absolute URL' => [
              'sourceType' => PropSource::HostEntityUrl->value,
              'absolute' => TRUE,
            ],
          ],
          PropSource::HostEntity->value => [],
        ],
        '⿲sdc_test_all_props:all-props␟test_string_format_iri_reference' => [
          'required' => FALSE,
          PropSource::EntityField->value => [
            'Authored by → User → Picture → URI → Root-relative file URL' => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝uid␞␟entity␜␜entity:user␝user_picture␞␟entity␜␜entity:file␝uri␞␟url',
            ],
            'Authored by → User → Picture → URI' => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝uid␞␟entity␜␜entity:user␝user_picture␞␟entity␜␜entity:file␝uri␞␟value',
            ],
            'Authored by → User → Picture' => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝uid␞␟entity␜␜entity:user␝user_picture␞␟src_with_alternate_widths',
            ],
            'Authored by → URL' => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝uid␞␟url',
            ],
            'Check it out!' => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝field_check_it_out␞␟uri',
            ],
            'Check it out! → Resolved URL' => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝field_check_it_out␞␟url',
            ],
            'Silly image 🤡 → URI → Root-relative file URL' => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝field_silly_image␞␟entity␜␜entity:file␝uri␞␟url',
            ],
            'Silly image 🤡 → URI' => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝field_silly_image␞␟entity␜␜entity:file␝uri␞␟value',
            ],
            "Silly image 🤡" => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝field_silly_image␞␟src_with_alternate_widths',
            ],
            'Primary topic → Taxonomy term → Revision user → User → Picture' => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝primary_topic␞␟entity␜␜entity:taxonomy_term␝revision_user␞␟entity␜␜entity:user␝user_picture␞␟src_with_alternate_widths',
            ],
            'Primary topic → Taxonomy term → Revision user → URL' => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝primary_topic␞␟entity␜␜entity:taxonomy_term␝revision_user␞␟url',
            ],
            'Primary topic → URL' => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝primary_topic␞␟url',
            ],
            'Revision user → User → Picture → URI → Root-relative file URL' => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝revision_uid␞␟entity␜␜entity:user␝user_picture␞␟entity␜␜entity:file␝uri␞␟url',
            ],
            'Revision user → User → Picture → URI' => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝revision_uid␞␟entity␜␜entity:user␝user_picture␞␟entity␜␜entity:file␝uri␞␟value',
            ],
            'Revision user → User → Picture' => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝revision_uid␞␟entity␜␜entity:user␝user_picture␞␟src_with_alternate_widths',
            ],
            'Revision user → URL' => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝revision_uid␞␟url',
            ],
          ],
          PropSource::Adapter->value => [],
          PropSource::HostEntityUrl->value => [
            'Relative URL' => [
              'sourceType' => PropSource::HostEntityUrl->value,
              'absolute' => FALSE,
            ],
          ],
          PropSource::HostEntity->value => [],
        ],
        '⿲sdc_test_all_props:all-props␟test_string_format_uri_template' => [
          'required' => FALSE,
          PropSource::EntityField->value => [],
          PropSource::Adapter->value => [],
          PropSource::HostEntityUrl->value => [],
          PropSource::HostEntity->value => [],
        ],
        '⿲sdc_test_all_props:all-props␟test_string_format_json_pointer' => [
          'required' => FALSE,
          PropSource::EntityField->value => [],
          PropSource::Adapter->value => [],
          PropSource::HostEntityUrl->value => [],
          PropSource::HostEntity->value => [],
        ],
        '⿲sdc_test_all_props:all-props␟test_string_format_relative_json_pointer' => [
          'required' => FALSE,
          PropSource::EntityField->value => [],
          PropSource::Adapter->value => [],
          PropSource::HostEntityUrl->value => [],
          PropSource::HostEntity->value => [],
        ],
        '⿲sdc_test_all_props:all-props␟test_string_format_regex' => [
          'required' => FALSE,
          PropSource::EntityField->value => [],
          PropSource::Adapter->value => [],
          PropSource::HostEntityUrl->value => [],
          PropSource::HostEntity->value => [],
        ],
        '⿲sdc_test_all_props:all-props␟test_integer' => [
          'required' => FALSE,
          PropSource::EntityField->value => [
            'Authored by → User → Picture → Height' => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝uid␞␟entity␜␜entity:user␝user_picture␞␟height',
            ],
            'Authored by → User → Picture → Width' => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝uid␞␟entity␜␜entity:user␝user_picture␞␟width',
            ],
            "Silly image 🤡 → File size" => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝field_silly_image␞␟entity␜␜entity:file␝filesize␞␟value',
            ],
            "Silly image 🤡 → Height" => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝field_silly_image␞␟height',
            ],
            "Silly image 🤡 → Width" => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝field_silly_image␞␟width',
            ],
            'Primary topic → Taxonomy term → Weight' => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝primary_topic␞␟entity␜␜entity:taxonomy_term␝weight␞␟value',
            ],
            'Revision user → User → Picture → Height' => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝revision_uid␞␟entity␜␜entity:user␝user_picture␞␟height',
            ],
            'Revision user → User → Picture → Width' => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝revision_uid␞␟entity␜␜entity:user␝user_picture␞␟width',
            ],
          ],
          PropSource::Adapter->value => [
            'Count days' => 'day_count',
          ],
          PropSource::HostEntityUrl->value => [],
          PropSource::HostEntity->value => [],
        ],
        '⿲sdc_test_all_props:all-props␟test_integer_range_minimum' => [
          'required' => FALSE,
          PropSource::EntityField->value => [],
          PropSource::Adapter->value => [],
          PropSource::HostEntityUrl->value => [],
          PropSource::HostEntity->value => [],
        ],
        '⿲sdc_test_all_props:all-props␟test_integer_range_minimum_maximum_timestamps' => [
          'required' => FALSE,
          PropSource::EntityField->value => [
            "Authored by → User → Last access" => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝uid␞␟entity␜␜entity:user␝access␞␟value',
            ],
            "Authored by → User → Changed" => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝uid␞␟entity␜␜entity:user␝changed␞␟value',
            ],
            "Authored by → User → Created" => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝uid␞␟entity␜␜entity:user␝created␞␟value',
            ],
            "Authored by → User → Last login" => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝uid␞␟entity␜␜entity:user␝login␞␟value',
            ],
            'Authored on' => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝created␞␟value',
            ],
            'Changed' => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝changed␞␟value',
            ],
            "Silly image 🤡 → Changed" => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝field_silly_image␞␟entity␜␜entity:file␝changed␞␟value',
            ],
            "Silly image 🤡 → Created" => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝field_silly_image␞␟entity␜␜entity:file␝created␞␟value',
            ],
            'Primary topic → Taxonomy term → Changed' => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝primary_topic␞␟entity␜␜entity:taxonomy_term␝changed␞␟value',
            ],
            'Primary topic → Taxonomy term → Revision create time' => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝primary_topic␞␟entity␜␜entity:taxonomy_term␝revision_created␞␟value',
            ],
            "Revision create time" => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝revision_timestamp␞␟value',
            ],
            "Revision user → User → Last access" => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝revision_uid␞␟entity␜␜entity:user␝access␞␟value',
            ],
            "Revision user → User → Changed" => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝revision_uid␞␟entity␜␜entity:user␝changed␞␟value',
            ],
            "Revision user → User → Created" => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝revision_uid␞␟entity␜␜entity:user␝created␞␟value',
            ],
            "Revision user → User → Last login" => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝revision_uid␞␟entity␜␜entity:user␝login␞␟value',
            ],
          ],
          PropSource::Adapter->value => [],
          PropSource::HostEntityUrl->value => [],
          PropSource::HostEntity->value => [],
        ],
        '⿲sdc_test_all_props:all-props␟test_integer_by_the_dozen' => [
          'required' => FALSE,
          PropSource::EntityField->value => [],
          PropSource::Adapter->value => [],
          PropSource::HostEntityUrl->value => [],
          PropSource::HostEntity->value => [],
        ],
        '⿲sdc_test_all_props:all-props␟test_number' => [
          'required' => FALSE,
          PropSource::EntityField->value => [
            'Authored by → User → Picture → Height' => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝uid␞␟entity␜␜entity:user␝user_picture␞␟height',
            ],
            'Authored by → User → Picture → Width' => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝uid␞␟entity␜␜entity:user␝user_picture␞␟width',
            ],
            "Silly image 🤡 → File size" => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝field_silly_image␞␟entity␜␜entity:file␝filesize␞␟value',
            ],
            "Silly image 🤡 → Height" => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝field_silly_image␞␟height',
            ],
            "Silly image 🤡 → Width" => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝field_silly_image␞␟width',
            ],
            'Primary topic → Taxonomy term → Weight' => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝primary_topic␞␟entity␜␜entity:taxonomy_term␝weight␞␟value',
            ],
            'Revision user → User → Picture → Height' => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝revision_uid␞␟entity␜␜entity:user␝user_picture␞␟height',
            ],
            'Revision user → User → Picture → Width' => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝revision_uid␞␟entity␜␜entity:user␝user_picture␞␟width',
            ],
          ],
          PropSource::Adapter->value => [],
          PropSource::HostEntityUrl->value => [],
          PropSource::HostEntity->value => [],
        ],
        '⿲sdc_test_all_props:all-props␟test_object_drupal_image' => [
          'required' => FALSE,
          PropSource::EntityField->value => [
            'Authored by → User → Picture' => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝uid␞␟entity␜␜entity:user␝user_picture␞␟{src↠src_with_alternate_widths,alt↠alt,width↠width,height↠height}',
            ],
            "Silly image 🤡" => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝field_silly_image␞␟{src↠src_with_alternate_widths,alt↠alt,width↠width,height↠height}',
            ],
            'Primary topic → Taxonomy term → Revision user' => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝primary_topic␞␟entity␜␜entity:taxonomy_term␝revision_user␞␟{src↝entity␜␜entity:user␝user_picture␞␟src_with_alternate_widths,alt↝entity␜␜entity:user␝name␞␟value,width↝entity␜␜entity:user␝user_picture␞␟width,height↝entity␜␜entity:user␝user_picture␞␟height}',
            ],
            'Revision user → User → Picture' => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝revision_uid␞␟entity␜␜entity:user␝user_picture␞␟{src↠src_with_alternate_widths,alt↠alt,width↠width,height↠height}',
            ],
          ],
          PropSource::Adapter->value => [
            'Apply image style' => 'image_apply_style',
            'Make relative image URL absolute' => 'image_url_rel_to_abs',
          ],
          PropSource::HostEntityUrl->value => [],
          PropSource::HostEntity->value => [],
        ],
        '⿲sdc_test_all_props:all-props␟test_object_drupal_image_ARRAY' => [
          'required' => FALSE,
          PropSource::EntityField->value => [
            "field_before_and_after" => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝field_before_and_after␞␟{src↠src_with_alternate_widths,alt↠alt,width↠width,height↠height}',
            ],
          ],
          PropSource::Adapter->value => [],
          PropSource::HostEntityUrl->value => [],
          PropSource::HostEntity->value => [],
        ],
        '⿲sdc_test_all_props:all-props␟test_object_drupal_video' => [
          'required' => FALSE,
          PropSource::EntityField->value => [],
          PropSource::Adapter->value => [],
          PropSource::HostEntityUrl->value => [],
          PropSource::HostEntity->value => [],
        ],
        '⿲sdc_test_all_props:all-props␟test_object_drupal_date_range' => [
          'required' => FALSE,
          PropSource::EntityField->value => [
            "field_event_duration" => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝field_event_duration␞␟{from↠value,to↠end_value}',
            ],
          ],
          PropSource::Adapter->value => [],
          PropSource::HostEntityUrl->value => [],
          PropSource::HostEntity->value => [],
        ],
        '⿲sdc_test_all_props:all-props␟test_string_html_inline' => [
          'required' => FALSE,
          PropSource::EntityField->value => [],
          PropSource::Adapter->value => [],
          PropSource::HostEntityUrl->value => [],
          PropSource::HostEntity->value => [],
        ],
        '⿲sdc_test_all_props:all-props␟test_string_html_block' => [
          'required' => FALSE,
          PropSource::EntityField->value => [
            "field_wall_of_text" => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝field_wall_of_text␞␟processed',
            ],
            'Primary topic → Taxonomy term → Some text field' => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝primary_topic␞␟entity␜␜entity:taxonomy_term:vocab_2␝some_text␞␟processed',
            ],
            'Primary topic → Taxonomy term → Description' => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝primary_topic␞␟entity␜␜entity:taxonomy_term␝description␞␟processed',
            ],
          ],
          PropSource::Adapter->value => [],
          PropSource::HostEntityUrl->value => [],
          PropSource::HostEntity->value => [],
        ],
        '⿲sdc_test_all_props:all-props␟test_string_html' => [
          'required' => FALSE,
          PropSource::EntityField->value => [
            "field_wall_of_text" => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝field_wall_of_text␞␟processed',
            ],
            'Primary topic → Taxonomy term → Some text field' => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝primary_topic␞␟entity␜␜entity:taxonomy_term:vocab_2␝some_text␞␟processed',
            ],
            'Primary topic → Taxonomy term → Description' => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝primary_topic␞␟entity␜␜entity:taxonomy_term␝description␞␟processed',
            ],
          ],
          PropSource::Adapter->value => [],
          PropSource::HostEntityUrl->value => [],
          PropSource::HostEntity->value => [],
        ],
        '⿲sdc_test_all_props:all-props␟test_REQUIRED_string_html_inline' => [
          'required' => TRUE,
          PropSource::EntityField->value => [],
          PropSource::Adapter->value => [],
          PropSource::HostEntityUrl->value => [],
          PropSource::HostEntity->value => [],
        ],
        '⿲sdc_test_all_props:all-props␟test_REQUIRED_string_html_block' => [
          'required' => TRUE,
          PropSource::EntityField->value => [
            "field_wall_of_text" => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝field_wall_of_text␞␟processed',
            ],
          ],
          PropSource::Adapter->value => [],
          PropSource::HostEntityUrl->value => [],
          PropSource::HostEntity->value => [],
        ],
        '⿲sdc_test_all_props:all-props␟test_REQUIRED_string_html' => [
          'required' => TRUE,
          PropSource::EntityField->value => [
            "field_wall_of_text" => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝field_wall_of_text␞␟processed',
            ],
          ],
          PropSource::Adapter->value => [],
          PropSource::HostEntityUrl->value => [],
          PropSource::HostEntity->value => [],
        ],
        '⿲sdc_test_all_props:all-props␟test_array_integer' => [
          'required' => FALSE,
          PropSource::EntityField->value => [
            "field_screenshots → File size" => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝field_screenshots␞␟entity␜␜entity:file␝filesize␞␟value',
            ],
            "field_screenshots → Height" => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝field_screenshots␞␟height',
            ],
            "field_screenshots → Width" => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝field_screenshots␞␟width',
            ],
            'Tags → Taxonomy term → Weight' => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝field_tags␞␟entity␜␜entity:taxonomy_term␝weight␞␟value',
            ],
          ],
          PropSource::Adapter->value => [],
          PropSource::HostEntityUrl->value => [],
          PropSource::HostEntity->value => [],
        ],
        '⿲sdc_test_all_props:all-props␟test_array_integer_minItems' => [
          'required' => FALSE,
          PropSource::EntityField->value => [
            'field_screenshots → File size' => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝field_screenshots␞␟entity␜␜entity:file␝filesize␞␟value',
            ],
            'field_screenshots → Height' => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝field_screenshots␞␟height',
            ],
            'field_screenshots → Width' => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝field_screenshots␞␟width',
            ],
            'Tags → Taxonomy term → Weight' => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝field_tags␞␟entity␜␜entity:taxonomy_term␝weight␞␟value',
            ],
          ],
          PropSource::Adapter->value => [],
          PropSource::HostEntityUrl->value => [],
          PropSource::HostEntity->value => [],
        ],
        '⿲sdc_test_all_props:all-props␟test_array_integer_maxItems' => [
          'required' => FALSE,
          PropSource::EntityField->value => [
            "field_before_and_after → File size" => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝field_before_and_after␞␟entity␜␜entity:file␝filesize␞␟value',
            ],
            "field_before_and_after → Height" => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝field_before_and_after␞␟height',
            ],
            "field_before_and_after → Width" => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝field_before_and_after␞␟width',
            ],
          ],
          PropSource::Adapter->value => [],
          PropSource::HostEntityUrl->value => [],
          PropSource::HostEntity->value => [],
        ],
        '⿲sdc_test_all_props:all-props␟test_array_integer_minItemsMultiple' => [
          'required' => FALSE,
          PropSource::EntityField->value => [],
          PropSource::Adapter->value => [],
          PropSource::HostEntityUrl->value => [],
          PropSource::HostEntity->value => [],
        ],
        '⿲sdc_test_all_props:all-props␟test_array_integer_minMaxItems' => [
          'required' => FALSE,
          PropSource::EntityField->value => [
            'field_before_and_after → File size' => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝field_before_and_after␞␟entity␜␜entity:file␝filesize␞␟value',
            ],
            'field_before_and_after → Height' => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝field_before_and_after␞␟height',
            ],
            'field_before_and_after → Width' => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝field_before_and_after␞␟width',
            ],
          ],
          PropSource::Adapter->value => [],
          PropSource::HostEntityUrl->value => [],
          PropSource::HostEntity->value => [],
        ],
        '⿲sdc_test_all_props:all-props␟test_array_string' => [
          'required' => FALSE,
          PropSource::EntityField->value => [
            'field_screenshots → Alternative text' => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝field_screenshots␞␟alt',
            ],
            'field_screenshots → Title' => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝field_screenshots␞␟title',
            ],
            'Tags → Taxonomy term → Name' => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:foo␝field_tags␞␟entity␜␜entity:taxonomy_term␝name␞␟value',
            ],
          ],
          PropSource::Adapter->value => [],
          PropSource::HostEntityUrl->value => [],
          PropSource::HostEntity->value => [],
        ],
      ],
    ];
  }

  /**
   * Tests the `HostEntity` bucket is populated iff the host satisfies the prop's `x-allowed-*` constraints.
   *
   * @param array<string, string> $constraints
   *   The `x-allowed-entity-type-id` (and optionally `x-allowed-bundle`).
   * @param string $host_data_type
   *   The host entity data type, e.g. `entity:node:foo` or `entity:user:user`.
   * @param bool $expect_match
   *   Whether the HostEntity bucket should contain a `HostEntityPropSource`.
   *
   * @see \Drupal\canvas\ShapeMatcher\HostEntityPropSourceMatcher
   */
  #[DataProvider('provideHostEntityPropSourceCases')]
  public function testHostEntityPropSourceSuggestion(array $constraints, string $host_data_type, bool $expect_match, ?string $expected_label): void {
    $bucket = $this->suggestHostEntityBucketForReferenceProp('test:host', 'ref', $constraints, EntityDataDefinition::createFromDataType($host_data_type));

    if ($expect_match) {
      $this->assertCount(1, $bucket);
      $this->assertNotNull($expected_label);
      $this->assertArrayHasKey($expected_label, $bucket);
    }
    else {
      $this->assertSame([], $bucket);
    }
  }

  public static function provideHostEntityPropSourceCases(): \Generator {
    yield 'bundleless target (user); host=user:user → match' => [
      'constraints' => ['x-allowed-entity-type-id' => 'user'],
      'host_data_type' => 'entity:user:user',
      'expect_match' => TRUE,
      'expected_label' => 'This user',
    ];
    yield 'bundleless target (user); host=node:foo → entity-type mismatch' => [
      'constraints' => ['x-allowed-entity-type-id' => 'user'],
      'host_data_type' => 'entity:node:foo',
      'expect_match' => FALSE,
      'expected_label' => NULL,
    ];
    yield 'bundled target (node:foo); host=node:foo → match' => [
      'constraints' => ['x-allowed-entity-type-id' => 'node', 'x-allowed-bundle' => 'foo'],
      'host_data_type' => 'entity:node:foo',
      'expect_match' => TRUE,
      'expected_label' => 'This Foo content item',
    ];
    yield 'bundled target (node:foo); host=user:user → entity-type mismatch' => [
      'constraints' => ['x-allowed-entity-type-id' => 'node', 'x-allowed-bundle' => 'foo'],
      'host_data_type' => 'entity:user:user',
      'expect_match' => FALSE,
      'expected_label' => NULL,
    ];
    // Bundle mismatch path: the host node bundle differs from the prop's
    // `x-allowed-bundle` on the same entity type.
    yield 'bundled target (node:article); host=node:foo → bundle mismatch' => [
      'constraints' => ['x-allowed-entity-type-id' => 'node', 'x-allowed-bundle' => 'article'],
      'host_data_type' => 'entity:node:foo',
      'expect_match' => FALSE,
      'expected_label' => NULL,
    ];
  }

  /**
   * Returns the HostEntity bucket for a synthetic single-prop entity-ref component.
   *
   * @param string $plugin_id
   *   A synthetic component plugin id (only used for CPE strings).
   * @param string $prop_name
   *   The prop name.
   * @param array<string, string> $constraints
   *   The `x-allowed-entity-type-id` (and optionally `x-allowed-bundle`).
   * @param \Drupal\Core\Entity\TypedData\EntityDataDefinition $host_entity_type
   *   The host entity type + bundle data definition.
   *
   * @return array<string, HostEntityPropSource>
   */
  private function suggestHostEntityBucketForReferenceProp(string $plugin_id, string $prop_name, array $constraints, EntityDataDefinition $host_entity_type): array {
    $prop_shape = JsonSchemaObjectRef::ContentEntityReference->asPropShapeArray() + $constraints;
    $definition = [
      'machineName' => $plugin_id,
      'extension_type' => 'module',
      'id' => $plugin_id,
      'provider' => 'canvas',
      'name' => $plugin_id,
      'props' => [
        'type' => 'object',
        'properties' => [
          $prop_name => $prop_shape + ['title' => $prop_name],
        ],
      ],
      'library' => [],
      'path' => '',
      'template' => 'phony',
    ];
    $metadata = new ComponentMetadata($definition, app_root: '', enforce_schemas: FALSE);
    $suggestions = $this->container->get(PropSourceSuggester::class)
      ->suggest($plugin_id, $metadata, $host_entity_type);
    $cpe = "⿲{$plugin_id}␟{$prop_name}";
    return $suggestions[$cpe][PropSource::HostEntity->value];
  }

}
