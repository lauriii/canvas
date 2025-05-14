<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Kernel\Config;

use Drupal\Core\Entity\Entity\EntityViewMode;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\experience_builder\Entity\ContentTemplate;
use Drupal\experience_builder\Plugin\DataType\ComponentTreeStructure;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\Tests\experience_builder\Traits\BetterConfigDependencyManagerTrait;
use Drupal\Tests\experience_builder\Traits\CreateTestJsComponentTrait;
use Drupal\Tests\experience_builder\Traits\GenerateComponentConfigTrait;
use Drupal\Tests\node\Traits\ContentTypeCreationTrait;

/**
 * @group experience_builder
 * @group #slow
 */
final class ContentTemplateValidationTest extends BetterConfigEntityValidationTestBase {

  use BetterConfigDependencyManagerTrait;
  use ContentTypeCreationTrait;
  use CreateTestJsComponentTrait;
  use GenerateComponentConfigTrait;

  /**
   * {@inheritdoc}
   */
  protected bool $hasLabel = FALSE;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    // The two only modules Drupal truly requires.
    'system',
    'user',
    // The module being tested.
    'experience_builder',
    // Modules providing used Components (and their ComponentSource plugins).
    'block',
    'sdc_test',
    'xb_test_sdc',
    // XB's dependencies (modules providing field types + widgets).
    'field',
    'file',
    'image',
    'link',
    'media',
    'node',
    'options',
    'text',
  ];

  /**
   * {@inheritdoc}
   *
   * @phpstan-ignore property.defaultValue
   */
  protected static array $propertiesWithRequiredKeys = [
    'component_tree' => [
      "The 'dynamic' prop source type must be present.",
      "'tree' is a required key.",
      "'inputs' is a required key.",
      "The array must contain a \"tree\" key.",
      "The array must contain an \"inputs\" key.",
    ],
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installConfig('node');
    $this->createContentType(['type' => 'alpha']);
    FieldStorageConfig::create([
      'field_name' => 'field_test',
      'type' => 'text',
      'entity_type' => 'node',
      'cardinality' => FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED,
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_test',
      'entity_type' => 'node',
      'bundle' => 'alpha',
      'label' => 'Test field',
    ])->save();
    $this->generateComponentConfig();
    $this->createMyCtaComponentFromSdc();

    $this->entity = ContentTemplate::create([
      'content_entity_type_id' => 'node',
      'content_entity_type_bundle' => 'alpha',
      'content_entity_type_view_mode' => 'full',
      'component_tree' => [
        'tree' => [
          ComponentTreeStructure::ROOT_UUID => [
            // An SDC populated by static prop sources.
            ['uuid' => 'sdc-static', 'component' => 'sdc.sdc_test.my-cta'],
            // A code component populated by an entity base field.
            ['uuid' => 'code-dynamic-base-field', 'component' => 'js.my-cta'],
            // An SDC populated by a normal entity field.
            ['uuid' => 'sdc-dynamic-bundle-field', 'component' => 'sdc.xb_test_sdc.props-no-slots'],
            // A block component.
            ['uuid' => 'block', 'component' => 'block.system_branding_block'],
            // An SDC with a slot that can be exposed.
            ['uuid' => 'b4937e35-ddc2-4f36-8d4c-b1cc14aaefef', 'component' => 'sdc.xb_test_sdc.props-slots'],
          ],
        ],
        'inputs' => [
          'sdc-static' => [
            'text' => [
              'sourceType' => 'static:field_item:string',
              'value' => 'This is really tricky for a first-timer',
              'expression' => 'ℹ︎string␟value',
            ],
          ],
          'code-dynamic-base-field' => [
            'text' => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:alpha␝title␞␟value',
            ],
          ],
          'sdc-dynamic-bundle-field' => [
            'heading' => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:alpha␝field_test␞␟value',
            ],
          ],
          'block' => [
            'label' => '',
            'label_display' => FALSE,
            'use_site_logo' => FALSE,
            'use_site_name' => TRUE,
            'use_site_slogan' => TRUE,
          ],
          'b4937e35-ddc2-4f36-8d4c-b1cc14aaefef' => [
            'heading' => [
              'sourceType' => 'static:field_item:string',
              'value' => 'There be a slot here',
              'expression' => 'ℹ︎string␟value',
            ],
          ],
        ],
      ],
    ]);
    $this->entity->save();
  }

  /**
   * {@inheritdoc}
   */
  public function testEntityIsValid(): void {
    parent::testEntityIsValid();

    $this->assertSame('node.alpha.full', $this->entity->id());

    // Also validate config dependencies are computed correctly.
    $this->assertSame(
      [
        'config' => [
          'core.entity_view_mode.node.full',
          'experience_builder.component.block.system_branding_block',
          'experience_builder.component.js.my-cta',
          'experience_builder.component.sdc.sdc_test.my-cta',
          'experience_builder.component.sdc.xb_test_sdc.props-no-slots',
          'experience_builder.component.sdc.xb_test_sdc.props-slots',
          'field.field.node.alpha.field_test',
          'node.type.alpha',
        ],
        'module' => [
          'node',
        ],
      ],
      $this->entity->getDependencies()
    );
    $this->assertSame([
      'config' => [
        'core.entity_view_mode.node.full',
        'experience_builder.component.block.system_branding_block',
        'experience_builder.component.js.my-cta',
        'experience_builder.component.sdc.sdc_test.my-cta',
        'experience_builder.component.sdc.xb_test_sdc.props-no-slots',
        'experience_builder.component.sdc.xb_test_sdc.props-slots',
        'field.field.node.alpha.field_test',
        'node.type.alpha',
        'experience_builder.js_component.my-cta',
        'field.storage.node.field_test',
      ],
      'module' => [
        'node',
        'experience_builder',
        'core',
        'system',
        'link',
        'options',
        'sdc_test',
        'xb_test_sdc',
        'text',
        'field',
      ],
    ], $this->getAllDependencies($this->entity));
  }

  /**
   * @dataProvider providerInvalidComponentTree
   */
  public function testInvalidComponentTree(array $component_tree, array $expected_messages): void {
    $this->entity->set('component_tree', $component_tree);
    $this->assertValidationErrors($expected_messages);
  }

  public static function providerInvalidComponentTree(): \Generator {
    yield "missing `component_tree` property" => [
      'component_tree' => [],
      'expected_messages' => [
        'component_tree' => [
          'The \'dynamic\' prop source type must be present.',
          '\'tree\' is a required key.',
          '\'inputs\' is a required key.',
          'The array must contain a "tree" key.',
          'The array must contain an "inputs" key.',
        ],
      ],
    ];

    yield "no DynamicPropSource, so no structured data from the content entity" => [
      'component_tree' => [
        'tree' => [
          ComponentTreeStructure::ROOT_UUID => [
            ['uuid' => 'sdc-valid', 'component' => 'sdc.experience_builder.druplicon'],
          ],
        ],
        'inputs' => [],
      ],
      'expected_messages' => [
        'component_tree' => "The 'dynamic' prop source type must be present.",
      ],
    ];

    yield "using disallowed Block-sourced Components" => [
      'component_tree' => [
        'tree' => [
          ComponentTreeStructure::ROOT_UUID => [
            ['uuid' => 'uuid-in-root', 'component' => 'sdc.xb_test_sdc.props-no-slots'],
            ['uuid' => 'block-valid', 'component' => 'block.system_branding_block'],
            ['uuid' => 'block-invalid', 'component' => 'block.page_title_block'],
            ['uuid' => 'block-invalid-messages', 'component' => 'block.system_messages_block'],
          ],
        ],
        'inputs' => [
          'uuid-in-root' => [
            'heading' => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:article␝title␞␟value',
            ],
          ],
          'block-valid' => [
            'use_site_logo' => TRUE,
            'use_site_name' => TRUE,
            'use_site_slogan' => TRUE,
            'label' => '',
            'label_display' => FALSE,
          ],
          'block-invalid' => [
            'label' => '',
            'label_display' => FALSE,
          ],
          'block-invalid-messages' => [
            'label' => '',
            'label_display' => FALSE,
          ],
        ],
      ],
      'expected_messages' => [
        'component_tree' => [
          'The \'Drupal\Core\Block\MessagesBlockPluginInterface\' component interface must be absent.',
          'The \'Drupal\Core\Block\TitleBlockPluginInterface\' component interface must be absent.',
        ],
      ],
    ];

    yield "using AdaptedPropSource" => [
      'component_tree' => [
        'tree' => [
          ComponentTreeStructure::ROOT_UUID => [
            ['uuid' => 'uuid-in-root', 'component' => 'sdc.xb_test_sdc.props-no-slots'],
            ['uuid' => 'dynamic-image-static-imageStyle-something7d', 'component' => 'sdc.experience_builder.image'],
          ],
        ],
        'inputs' => [
          'uuid-in-root' => [
            'heading' => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:article␝title␞␟value',
            ],
          ],
          'dynamic-image-static-imageStyle-something7d' => [
            'image' => [
              'sourceType' => 'adapter:image_apply_style',
              'adapterInputs' => [
                'image' => [
                  'sourceType' => 'dynamic',
                  'expression' => 'ℹ︎␜entity:node:article␝field_hero␞␟{src↝entity␜␜entity:file␝uri␞0␟value,alt↠alt,width↠width,height↠height}',
                ],
                'imageStyle' => [
                  'sourceType' => 'static:field_item:string',
                  'value' => 'thumbnail',
                  'expression' => 'ℹ︎string␟value',
                ],
              ],
            ],
          ],
        ],
      ],
      'expected_messages' => [
        'component_tree' => "The 'adapter' prop source type must be absent.",
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function testImmutableProperties(array $valid_values = []): void {
    $this->createContentType(['type' => 'beta']);
    EntityViewMode::create([
      'id' => 'node.social_media_card',
      'label' => 'Social Media Card',
      'targetEntityType' => 'node',
    ])->save();

    $valid_values = [
      'content_entity_type_id' => 'user',
      'content_entity_type_bundle' => 'beta',
      'content_entity_type_view_mode' => 'social_media_card',
    ];
    $additional_validation_errors = [
      'id' => [],
      'content_entity_type_id' => [
        'content_entity_type_bundle' => "The 'alpha' bundle does not exist on the 'user' entity type.",
        'content_entity_type_view_mode' => "The 'core.entity_view_mode.user.full' config does not exist.",
      ],
      'content_entity_type_bundle' => [],
      'content_entity_type_view_mode' => [],
    ];

    // @todo Update parent method to accept a `$additional_validation_errors` parameter in addition to `$valid_values`, and uncomment the next line, remove all lines after it.
    // parent::testImmutableProperties($valid_values);
    $constraints = $this->entity->getEntityType()->getConstraints();
    $this->assertNotEmpty($constraints['ImmutableProperties'], 'All config entities should have at least one immutable ID property.');

    foreach ($constraints['ImmutableProperties'] as $property_name) {
      $original_value = $this->entity->get($property_name);
      $this->entity->set($property_name, $valid_values[$property_name] ?? $this->randomMachineName());
      $this->assertValidationErrors([
        '' => "The '$property_name' property cannot be changed.",
      ] + $additional_validation_errors[$property_name]);
      $this->entity->set($property_name, $original_value);
    }
  }

  public function testInvalidContentEntityTypeId(): void {
    $this->entity->set('content_entity_type_id', 'nope');
    $this->assertValidationErrors([
      '' => "The 'content_entity_type_id' property cannot be changed.",
      'content_entity_type_id' => "The 'nope' plugin does not exist.",
      'content_entity_type_bundle' => "The 'alpha' bundle does not exist on the 'nope' entity type.",
      'content_entity_type_view_mode' => "The 'core.entity_view_mode.nope.full' config does not exist.",
    ]);
  }

  public function testInvalidContentEntityTypeBundle(): void {
    $this->entity->set('content_entity_type_bundle', 'nope');
    $this->assertValidationErrors([
      '' => "The 'content_entity_type_bundle' property cannot be changed.",
      'content_entity_type_bundle' => "The 'nope' bundle does not exist on the 'node' entity type.",
    ]);
  }

  public function testInvalidContentEntityTypeViewMode(): void {
    $this->entity->set('content_entity_type_view_mode', 'nope');
    $this->assertValidationErrors([
      '' => "The 'content_entity_type_view_mode' property cannot be changed.",
      'content_entity_type_view_mode' => "The 'core.entity_view_mode.node.nope' config does not exist.",
    ]);
  }

  public function testExposedSlotMustBeEmpty(): void {
    assert($this->entity instanceof ContentTemplate);

    // Add a component in one of the open slots.
    $item = $this->entity->getComponentTree();
    $tree = json_decode($item->get('tree')->getValue(), TRUE);
    $tree['b4937e35-ddc2-4f36-8d4c-b1cc14aaefef']['the_footer'][] = [
      'uuid' => 'greeting',
      'component' => 'sdc.xb_test_sdc.props-no-slots',
    ];
    $inputs = json_decode($item->get('inputs')->getValue(), TRUE);
    $inputs['greeting']['heading'] = [
      'sourceType' => 'dynamic',
      'expression' => 'ℹ︎␜entity:node:alpha␝title␞␟value',
    ];
    $this->entity->set('component_tree', [
      'tree' => $tree,
      'inputs' => $inputs,
    ]);

    $this->entity->set('exposed_slots', [
      'filled_footer' => [
        'component_uuid' => 'b4937e35-ddc2-4f36-8d4c-b1cc14aaefef',
        'slot_name' => 'the_footer',
        'label' => "Something's already here!",
      ],
    ]);
    $this->assertValidationErrors([
      'exposed_slots.filled_footer' => 'The <em class="placeholder">the_footer</em> slot must be empty.',
    ]);
  }

  public static function providerInvalidExposedSlot(): iterable {
    yield 'root uuid is exposed' => [
      [
        'not_allowed' => [
          'component_uuid' => ComponentTreeStructure::ROOT_UUID,
          'slot_name' => 'not-a-thing',
          'label' => "This won't work",
        ],
      ],
      [
        'exposed_slots.not_allowed' => 'Exposing the full component tree is not allowed.',
      ],
    ];

    yield 'component exposing the slot does not exist in the tree' => [
      [
        'not_a_thing' => [
          'component_uuid' => '6348ee20-cf62-49e3-bc86-cf62abc09c74',
          'slot_name' => 'not-a-thing',
          'label' => "Can't expose a slot in a component we don't have!",
        ],
      ],
      [
        'exposed_slots.not_a_thing' => 'The component <em class="placeholder">6348ee20-cf62-49e3-bc86-cf62abc09c74</em> does not exist in the tree.',
      ],
    ];

    yield 'exposed slot is not defined by the component' => [
      [
        'filled_footer' => [
          'component_uuid' => 'b4937e35-ddc2-4f36-8d4c-b1cc14aaefef',
          'slot_name' => 'not_a_real_slot',
          'label' => "Whither this slot you speak of?",
        ],
      ],
      [
        'exposed_slots.filled_footer' => 'The component <em class="placeholder">b4937e35-ddc2-4f36-8d4c-b1cc14aaefef</em> does not have a <em class="placeholder">not_a_real_slot</em> slot.',
      ],
    ];

    yield 'exposed slot machine name is not valid' => [
      [
        'not a valid exposed slot name' => [
          'component_uuid' => 'b4937e35-ddc2-4f36-8d4c-b1cc14aaefef',
          'slot_name' => 'the_footer',
          'label' => "I got your footer right here",
        ],
      ],
      [
        'exposed_slots' => 'The <em class="placeholder">&quot;not a valid exposed slot name&quot;</em> key is not a valid machine name.',
      ],
    ];
  }

  /**
   * @dataProvider providerInvalidExposedSlot
   */
  public function testInvalidExposedSlot(array $exposed_slots, array $expected_errors): void {
    $this->entity->set('exposed_slots', $exposed_slots);
    $this->assertValidationErrors($expected_errors);
  }

  public function testExposedSlotsOnlyAllowedInFullViewMode(): void {
    $this->entity = $this->entity->createDuplicate();
    $this->entity->set('content_entity_type_view_mode', 'teaser');
    $this->entity->set('id', 'node.alpha.teaser');
    $this->entity->set('exposed_slots', [
      'footer_for_you' => [
        'component_uuid' => 'b4937e35-ddc2-4f36-8d4c-b1cc14aaefef',
        'slot_name' => 'the_footer',
        'label' => "I got your footer right here",
      ],
    ]);
    $this->assertValidationErrors([
      'exposed_slots.footer_for_you' => 'Exposed slots are only allowed in the <em class="placeholder">full</em> view mode.',
    ]);
  }

}
