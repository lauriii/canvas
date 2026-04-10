<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\Config;

// cspell:ignore thisisatestpattern
use Drupal\file\Entity\File;
use Drupal\media\Entity\Media;
use Drupal\Tests\canvas\Traits\ConstraintViolationsTestTrait;
use Drupal\Tests\media\Traits\MediaTypeCreationTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\Pattern;
use Drupal\canvas\PropSource\PropSource;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\canvas\Traits\BetterConfigDependencyManagerTrait;
use Drupal\Tests\canvas\Traits\DataProviderWithComponentTreeTrait;
use Drupal\Tests\canvas\Traits\GenerateComponentConfigTrait;
use Drupal\TestTools\Random;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests Pattern Validation.
 */
#[Group('canvas')]
#[RunTestsInSeparateProcesses]
class PatternValidationTest extends BetterConfigEntityValidationTestBase {

  use BetterConfigDependencyManagerTrait;
  use DataProviderWithComponentTreeTrait;
  use MediaTypeCreationTrait;
  use GenerateComponentConfigTrait;
  use ConstraintViolationsTestTrait;
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    ...CanvasKernelTestBase::CANVAS_KERNEL_TEST_MINIMAL_MODULES,
    // Necessary for Media entities.
    'field',
    // Test components.
    'block',
    'canvas_test_sdc',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['canvas']);
    $this->generateComponentConfig();
    $generate_static_prop_source = function (string $label): string {
      return "Hello, $label!";
    };

    // Generate a File entity + image Media entity to populate an "image" static
    // prop source.
    $this->installEntitySchema('file');
    $this->installEntitySchema('media');
    $this->installEntitySchema('path_alias');
    $this->installSchema('file', ['file_usage']);
    $this->setUpCurrentUser(permissions: ['access content']);
    $image_uri = $this->getRandomGenerator()
      ->image(uniqid('public://') . '.png', '200x200', '400x400');
    self::assertFileExists($image_uri);
    $original_media_referenced_file = File::create([
      'uuid' => '3aa127f9-a9f4-4391-acbc-1dc200d3bd7f',
      'uri' => $image_uri,
      'status' => File::STATUS_PERMANENT,
    ]);
    self::assertSame([], self::violationsToArray($original_media_referenced_file->validate()));
    $original_media_referenced_file->save();
    $original_media = Media::create([
      'bundle' => $this->createMediaType('image')->id(),
      'name' => 'Test image',
      'field_media_image' => $original_media_referenced_file,
    ]);
    self::assertSame([], self::violationsToArray($original_media->validate()));
    $original_media->save();

    $this->entity = Pattern::create([
      'id' => 'test_pattern',
      'label' => 'Test pattern',
      'component_tree' => [
        [
          'uuid' => '8c59b08a-59f7-4c33-b1b6-06af8f153e73',
          'component_id' => 'sdc.canvas_test_sdc.props-no-slots',
          'component_version' => 'b1e991f726a2a266',
          'inputs' => [
            'heading' => $generate_static_prop_source('world'),
          ],
          'label' => Random::string(255),
        ],
        [
          'uuid' => 'cdaf905d-4b07-4f3c-a691-4b9d07891124',
          'component_id' => 'sdc.canvas_test_sdc.props-no-slots',
          'component_version' => 'b1e991f726a2a266',
          'inputs' => [
            'heading' => $generate_static_prop_source('another world'),
          ],
        ],
        [
          'uuid' => '02f06f2a-c3af-4f71-8920-5f74169a88a5',
          'component_id' => 'sdc.canvas_test_sdc.heading',
          'component_version' => '8c01a2bdb897a810',
          'inputs' => [
            'text' => $generate_static_prop_source('heading level three'),
            'element' => 'h3',
          ],
        ],
        [
          'uuid' => '56031f0f-a073-471d-8298-4ecf757ff0e7',
          'component_id' => 'block.local_tasks_block',
          'component_version' => Component::load('block.local_tasks_block')?->getActiveVersion(),
          'inputs' => [
            'label_display' => '0',
            'primary' => TRUE,
            'secondary' => TRUE,
            'label' => '',
          ],
        ],
        [
          'uuid' => '460854df-47dc-4cce-b8ce-3fc38fbf4760',
          'component_id' => 'sdc.canvas_test_sdc.image-optional-without-example',
          'component_version' => 'b3a78d7dc6559ea5',
          'inputs' => [
            'image' => [
              'target_id' => $original_media->id(),
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

    // Beyond validity, validate config dependencies are computed correctly.
    $this->assertSame(
      [
        'config' => [
          'canvas.component.block.local_tasks_block',
          'canvas.component.sdc.canvas_test_sdc.heading',
          'canvas.component.sdc.canvas_test_sdc.image-optional-without-example',
          'canvas.component.sdc.canvas_test_sdc.props-no-slots',
          // @todo Remove this in https://www.drupal.org/i/3579536
          'image.style.canvas_parametrized_width',
        ],
        'content' => [
          'file:file:3aa127f9-a9f4-4391-acbc-1dc200d3bd7f',
        ],
        'module' => [
          'file',
          'image',
          // @todo Remove the 2 above in https://www.drupal.org/i/3579536
          'options',
        ],
      ],
      $this->entity->getDependencies()
    );
    $this->assertSame([
      'config' => [
        'canvas.component.block.local_tasks_block',
        'canvas.component.sdc.canvas_test_sdc.heading',
        'canvas.component.sdc.canvas_test_sdc.image-optional-without-example',
        'canvas.component.sdc.canvas_test_sdc.props-no-slots',
        'image.style.canvas_parametrized_width',
      ],
      'module' => [
        'file',
        'image',
        'options',
        'canvas',
        'canvas_test_sdc',
      ],
      'content' => [
        'file:file:3aa127f9-a9f4-4391-acbc-1dc200d3bd7f',
      ],
    ], $this->getAllDependencies($this->entity));
  }

  /**
   * Pattern config entities atypically do not need an ID to be specified.
   *
   * @see \Drupal\canvas\Entity\Pattern::preCreate()
   */
  public function testValidWithoutIdSpecified(): void {
    $this->assertCount(1, Pattern::loadMultiple());

    // Reuse most of the values of the test Pattern.
    $values = $this->entity->toArray();
    // Each config entity must have a unique UUID; it is generated by config
    // storage.
    unset($values['uuid']);

    // Test creating Pattern entities with a specific label and no ID.
    $values['label'] = 'This is a test pattern';
    unset($values['id']);

    for ($i = 0; $i < 3; $i++) {
      $pattern = Pattern::create($values);
      $pattern->save();
      $this->assertSame($values['label'], $pattern->label());
      if ($i === 0) {
        // The first Pattern generated from this label does not have a suffix.
        $this->assertSame('thisisatestpattern', $pattern->id());
      }
      else {
        // All others do.
        // @phpstan-ignore-next-line
        $this->assertMatchesRegularExpression('/^thisisatestpattern_[a-z0-9]+$/', $pattern->id());
      }
    }

    $this->assertSame([
      'Test pattern',
      'This is a test pattern',
      'This is a test pattern',
      'This is a test pattern',
    ], \array_map(
      fn (Pattern $p): string => (string) $p->label(),
      array_values(Pattern::loadMultiple())
    ));
  }

  /**
   * Tests invalid component tree.
   *
   * @legacy-covers \Drupal\canvas\Plugin\Validation\Constraint\ComponentTreeMeetsRequirementsConstraint
   */
  #[DataProvider('providerInvalidComponentTree')]
  public function testInvalidComponentTree(array $component_tree, array $expected_messages): void {
    \assert($this->entity instanceof Pattern);
    $component_tree = self::populateActiveComponentVersionPlaceholders($component_tree);
    $this->entity->setComponentTree($component_tree);
    $this->assertValidationErrors($expected_messages);
  }

  public static function providerInvalidComponentTree(): \Generator {
    yield "using EntityFieldPropSource" => [
      'component_tree' => [
        [
          'uuid' => '8c59b08a-59f7-4c33-b1b6-06af8f153e73',
          'component_id' => 'sdc.canvas_test_sdc.props-no-slots',
          'component_version' => 'b1e991f726a2a266',
          'inputs' => [
            'heading' => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:article␝title␞␟value',
            ],
          ],
        ],
      ],
      'expected_messages' => [
        'component_tree' => 'The \'entity-field\' prop source type must be absent.',
      ],
    ];

    yield 'using HostEntityUrlPropSource' => [
      'component_tree' => [
        [
          'uuid' => '15616c29-72c6-417a-a7d9-aff329467cc4',
          'component_id' => 'sdc.canvas_test_sdc.my-cta',
          'component_version' => '89881c04a0fde367',
          'inputs' => [
            'text' => [
              'value' => 'Visit sunny Vienna',
            ],
            'href' => [
              'sourceType' => PropSource::HostEntityUrl->value,
              'absolute' => TRUE,
            ],
          ],
        ],
      ],
      'expected_messages' => [
        'component_tree' => "The 'host-entity-url' prop source type must be absent.",
      ],
    ];

    yield "using a disallowed Block-sourced Component" => [
      'component_tree' => [
        [
          'uuid' => '62602a53-de40-4e33-aad5-241a7cf74499',
          'component_id' => 'sdc.canvas_test_sdc.druplicon',
          'component_version' => '8fe3be948e0194e1',
          'inputs' => [],
        ],
        [
          'uuid' => '7f91aa44-c672-454f-8ed0-417d0de76b14',
          'component_id' => 'block.system_branding_block',
          'component_version' => '::ACTIVE_VERSION_IN_SUT::',
          'inputs' => [
            'use_site_logo' => TRUE,
            'use_site_name' => TRUE,
            'use_site_slogan' => TRUE,
            'label' => '',
            'label_display' => '0',
          ],
        ],
        [
          'uuid' => 'block-invalid',
          'component_id' => 'block.page_title_block',
          'component_version' => '::ACTIVE_VERSION_IN_SUT::',
          'inputs' => [],
        ],
      ],
      'expected_messages' => [
        'component_tree' => 'The \'Drupal\Core\Block\TitleBlockPluginInterface\' component interface must be absent.',
        'component_tree.2.uuid' => 'This is not a valid UUID.',
        'component_tree.2.inputs' => [
          "'label' is a required key.",
          "'label_display' is a required key.",
        ],
      ],
    ];

    yield "invalid parent" => [
      'component_tree' => [
        [
          'uuid' => 'fa9ff0a8-e23a-492a-ab14-5460611fa2c1',
          'component_id' => 'sdc.canvas_test_sdc.props-slots',
          'component_version' => '85a5c0c7dd53e0bb',
          'inputs' => [
            'heading' => 'And we laugh like soft, mad children',
          ],
        ],
        [
          'uuid' => 'e303dd88-9409-4dc7-8a8b-a31602884a94',
          'slot' => 'the_body',
          'parent_uuid' => '6381352f-5b0a-4ca1-960d-a5505b37b27c',
          'component_id' => 'sdc.canvas_test_sdc.props-slots',
          'component_version' => '85a5c0c7dd53e0bb',
          'inputs' => [
            'heading' => ' Smug in the wooly cotton brains of infancy',
          ],
        ],
      ],
      'expected_messages' => [
        'component_tree.1.parent_uuid' => 'Invalid component tree item with UUID <em class="placeholder">e303dd88-9409-4dc7-8a8b-a31602884a94</em> references an invalid parent <em class="placeholder">6381352f-5b0a-4ca1-960d-a5505b37b27c</em>.',
      ],
    ];

    yield "invalid slot" => [
      'component_tree' => [
        [
          'uuid' => 'fa9ff0a8-e23a-492a-ab14-5460611fa2c1',
          'component_id' => 'sdc.canvas_test_sdc.props-slots',
          'component_version' => '85a5c0c7dd53e0bb',
          'inputs' => [
            'heading' => 'And we laugh like soft, mad children',
          ],
        ],
        [
          'uuid' => 'e303dd88-9409-4dc7-8a8b-a31602884a94',
          'slot' => 'banana',
          'parent_uuid' => 'fa9ff0a8-e23a-492a-ab14-5460611fa2c1',
          'component_id' => 'sdc.canvas_test_sdc.props-slots',
          'component_version' => '85a5c0c7dd53e0bb',
          'inputs' => [
            'heading' => ' Smug in the wooly cotton brains of infancy',
          ],
        ],
      ],
      'expected_messages' => [
        'component_tree.1.slot' => 'Invalid component subtree. This component subtree contains an invalid slot name for component <em class="placeholder">sdc.canvas_test_sdc.props-slots</em>: <em class="placeholder">banana</em>. Valid slot names are: <em class="placeholder">the_body, the_footer, the_colophon</em>.',
      ],
    ];

    yield "invalid label" => [
      'component_tree' => [
        [
          'uuid' => 'e303dd88-9409-4dc7-8a8b-a31602884a94',
          'component_id' => 'sdc.canvas_test_sdc.props-slots',
          'component_version' => '85a5c0c7dd53e0bb',
          'inputs' => [
            'heading' => 'And we laugh like soft, mad children',
          ],
          'label' => Random::string(256),
        ],
      ],
      'expected_messages' => [
        'component_tree.0.label' => 'This value is too long. It should have <em class="placeholder">255</em> characters or less.',
      ],
    ];

    yield "invalid version" => [
      'component_tree' => [
        [
          'uuid' => 'fa9ff0a8-e23a-492a-ab14-5460611fa2c1',
          'component_id' => 'sdc.canvas_test_sdc.props-slots',
          'component_version' => 'abc',
          'inputs' => [
            'heading' => 'And we laugh like soft, mad children',
          ],
        ],
      ],
      'expected_messages' => [
        'component_tree.0.component_version' => "'abc' is not a version that exists on component config entity 'sdc.canvas_test_sdc.props-slots'. Available versions: '85a5c0c7dd53e0bb'.",
      ],
    ];

    yield "not collapsed" => [
      'component_tree' => [
        [
          'uuid' => 'e303dd88-9409-4dc7-8a8b-a31602884a94',
          'component_id' => 'sdc.canvas_test_sdc.props-slots',
          'component_version' => '85a5c0c7dd53e0bb',
          'inputs' => [
            'heading' => [
              'sourceType' => 'static:field_item:string',
              'value' => 'And we laugh like soft, mad children',
              'expression' => 'ℹ︎string␟value',
            ],
          ],
        ],
      ],
      'expected_messages' => [
        'component_tree.0.inputs.e303dd88-9409-4dc7-8a8b-a31602884a94' => 'When using the default static prop source for a component input, you must use the collapsed input syntax.',
      ],
    ];
  }

}
