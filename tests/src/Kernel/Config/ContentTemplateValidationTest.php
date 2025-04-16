<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Kernel\Config;

use Drupal\Core\Entity\Entity\EntityViewMode;
use Drupal\experience_builder\Entity\ContentTemplate;
use Drupal\experience_builder\Plugin\DataType\ComponentTreeStructure;
use Drupal\Tests\experience_builder\Traits\BetterConfigDependencyManagerTrait;
use Drupal\Tests\experience_builder\Traits\CreateTestJsComponentTrait;
use Drupal\Tests\experience_builder\Traits\GenerateComponentConfigTrait;
use Drupal\Tests\experience_builder\Traits\TestDataUtilitiesTrait;
use Drupal\Tests\node\Traits\ContentTypeCreationTrait;

/**
 * @group experience_builder
 */
final class ContentTemplateValidationTest extends BetterConfigEntityValidationTestBase {

  use BetterConfigDependencyManagerTrait;
  use ContentTypeCreationTrait;
  use CreateTestJsComponentTrait;
  use GenerateComponentConfigTrait;
  use TestDataUtilitiesTrait;

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
    $this->generateComponentConfig();
    $this->createMyCtaComponentFromSdc();

    $this->entity = ContentTemplate::create([
      'content_entity_type_id' => 'node',
      'content_entity_type_bundle' => 'alpha',
      'content_entity_type_view_mode' => 'full',
      'component_tree' => [
        'tree' => self::encodeXBData([
          ComponentTreeStructure::ROOT_UUID => [
            // An SDC populated by static prop sources.
            ['uuid' => 'sdc-static', 'component' => 'sdc.sdc_test.my-cta'],
            // A code component populated by an entity base field.
            ['uuid' => 'code-dynamic-base-field', 'component' => 'js.my-cta'],
            // An SDC populated by a normal entity field.
            ['uuid' => 'sdc-dynamic-bundle-field', 'component' => 'sdc.xb_test_sdc.props-no-slots'],
            // A block component.
            ['uuid' => 'block', 'component' => 'block.system_branding_block'],
          ],
        ]),
        'inputs' => self::encodeXBData([
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
        ]),
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
    // @todo Ensure that field_config entities related to dynamic prop sources
    //   appear in the config dependencies in https://www.drupal.org/i/3518336.
    $this->assertSame(
      [
        'config' => [
          'core.entity_view_mode.node.full',
          'experience_builder.component.block.system_branding_block',
          'experience_builder.component.js.my-cta',
          'experience_builder.component.sdc.sdc_test.my-cta',
          'experience_builder.component.sdc.xb_test_sdc.props-no-slots',
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
        'experience_builder.js_component.my-cta',
      ],
      'module' => [
        'experience_builder',
        'node',
        'core',
        'link',
        'options',
        'sdc_test',
        'xb_test_sdc',
      ],
    ], $this->getAllDependencies($this->entity));
  }

  /**
   * @dataProvider providerInvalidComponentTree
   */
  public function testInvalidComponentTree(array $component_trees, array $expected_messages): void {
    $this->entity->set('component_tree', $component_trees);
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
        'tree' => self::encodeXBData([
          ComponentTreeStructure::ROOT_UUID => [
            ['uuid' => 'sdc-valid', 'component' => 'sdc.experience_builder.druplicon'],
          ],
        ]),
        'inputs' => self::encodeXBData([]),
      ],
      'expected_messages' => [
        'component_tree' => "The 'dynamic' prop source type must be present.",
      ],
    ];

    yield "using disallowed Block-sourced Components" => [
      'component_tree' => [
        'tree' => self::encodeXBData([
          ComponentTreeStructure::ROOT_UUID => [
            ['uuid' => 'uuid-in-root', 'component' => 'sdc.xb_test_sdc.props-no-slots'],
            ['uuid' => 'block-valid', 'component' => 'block.system_branding_block'],
            ['uuid' => 'block-invalid', 'component' => 'block.page_title_block'],
            ['uuid' => 'block-invalid-messages', 'component' => 'block.system_messages_block'],
          ],
        ]),
        'inputs' => self::encodeXBData([
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
        ]),
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
        'tree' => self::encodeXBData([
          ComponentTreeStructure::ROOT_UUID => [
            ['uuid' => 'uuid-in-root', 'component' => 'sdc.xb_test_sdc.props-no-slots'],
            ['uuid' => 'dynamic-image-static-imageStyle-something7d', 'component' => 'sdc.experience_builder.image'],
          ],
        ]),
        'inputs' => self::encodeXBData([
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
        ]),
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

}
