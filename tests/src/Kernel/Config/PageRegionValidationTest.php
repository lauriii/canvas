<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Kernel\Config;

use Drupal\Core\Extension\ThemeInstallerInterface;
use Drupal\experience_builder\Entity\PageRegion;
use Drupal\experience_builder\Exception\ConstraintViolationException;
use Drupal\experience_builder\Plugin\DataType\ComponentTreeStructure;
use Drupal\KernelTests\Core\Config\ConfigEntityValidationTestBase;
use Drupal\Tests\experience_builder\Traits\BetterConfigDependencyManagerTrait;
use Drupal\Tests\experience_builder\Traits\ConstraintViolationsTestTrait;
use Drupal\Tests\experience_builder\Traits\GenerateComponentConfigTrait;
use Drupal\Tests\experience_builder\Traits\TestDataUtilitiesTrait;

class PageRegionValidationTest extends ConfigEntityValidationTestBase {

  use BetterConfigDependencyManagerTrait;
  use GenerateComponentConfigTrait;
  use TestDataUtilitiesTrait;
  use ConstraintViolationsTestTrait;

  /**
   * {@inheritdoc}
   */
  protected bool $hasLabel = FALSE;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'block',
    'experience_builder',
    'xb_test_sdc',
    // XB's dependencies (modules providing field types + widgets).
    'datetime',
    'file',
    'image',
    'options',
    'path',
    'link',
  ];

  /**
   * {@inheritdoc}
   *
   * @phpstan-ignore property.defaultValue
   */
  protected static array $propertiesWithRequiredKeys = [
    'component_tree' => [
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
    $this->generateComponentConfig();
    $generate_static_prop_source = function (string $label): array {
      return [
        'sourceType' => 'static:field_item:string',
        'value' => "Hello, $label!",
        'expression' => 'ℹ︎string␟value',
      ];
    };
    $this->entity = PageRegion::create([
      'theme' => 'stark',
      'region' => 'sidebar_first',
      'component_tree' => [
        'tree' => self::encodeXBData([
          ComponentTreeStructure::ROOT_UUID => [
            ['uuid' => 'uuid-in-root', 'component' => 'sdc.xb_test_sdc.props-no-slots'],
            ['uuid' => 'uuid-in-root-another', 'component' => 'sdc.xb_test_sdc.props-no-slots'],
            ['uuid' => 'uuid-title', 'component' => 'block.page_title_block'],
            ['uuid' => 'uuid-messages', 'component' => 'block.system_messages_block'],
          ],
        ]),
        'inputs' => self::encodeXBData([
          'uuid-in-root' => [
            'heading' => $generate_static_prop_source('world'),
          ],
          'uuid-in-root-another' => [
            'heading' => $generate_static_prop_source('another world'),
          ],
          'uuid-messages' => [
            'label' => '',
            'label_display' => FALSE,
          ],
          'uuid-title' => [
            'label' => '',
            'label_display' => FALSE,
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

    $this->assertSame('stark.sidebar_first', $this->entity->id());

    // Also validate config dependencies are computed correctly.
    $this->assertSame(
      [
        'config' => [
          'experience_builder.component.block.page_title_block',
          'experience_builder.component.block.system_messages_block',
          'experience_builder.component.sdc.xb_test_sdc.props-no-slots',
        ],
        'theme' => ['stark'],
      ],
      $this->entity->getDependencies()
    );
    $this->assertSame([
      'config' => [
        'experience_builder.component.block.page_title_block',
        'experience_builder.component.block.system_messages_block',
        'experience_builder.component.sdc.xb_test_sdc.props-no-slots',
      ],
      'module' => [
        'experience_builder',
        'xb_test_sdc',
      ],
      'theme' => ['stark'],
    ], $this->getAllDependencies($this->entity));
  }

  /**
   * {@inheritdoc}
   */
  public function testInvalidTheme(): void {
    $this->entity->set('theme', 'non_existent_theme');
    $this->assertValidationErrors([
      'theme' => "Theme 'non_existent_theme' is not installed.",
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function testInvalidRegion(): void {
    $this->entity->set('region', 'non_existent_region');
    $this->assertValidationErrors([
      'region' => "Region 'non_existent_region' does not exist in theme 'stark'.",
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function testImmutableProperties(array $valid_values = []): void {
    $this->container->get(ThemeInstallerInterface::class)->install([
      'olivero',
    ]);
    $this->entity->set('id', 'social');
    // If we don't also change the `component_trees` and `editable` values here,
    // we will get additional validation errors, because `theme` determines what
    // key-value are expected. Given that this config entity type has only a
    // single immutable property (`theme`), setting a valid corresponding values
    // prevents that distraction.
    // @see core/themes/olivero/olivero.info.yml
    $this->entity->set('component_tree', [
      'tree' => self::encodeXBData([
        ComponentTreeStructure::ROOT_UUID => [
          ['uuid' => 'uuid-title', 'component' => 'block.page_title_block'],
          ['uuid' => 'uuid-messages', 'component' => 'block.system_messages_block'],
        ],
      ]),
      'inputs' => self::encodeXBData([
        'uuid-messages' => [
          'label' => '',
          'label_display' => FALSE,
        ],
        'uuid-title' => [
          'label' => '',
          'label_display' => FALSE,
        ],
      ]),
    ]);
    $this->entity->disable();
    parent::testImmutableProperties([
      'theme' => 'olivero',
    ]);
  }

  /**
   * @dataProvider providerInvalidComponentTree
   */
  public function testInvalidComponentTree(array $component_trees, array $expected_messages): void {
    $this->entity->set('component_tree', $component_trees);
    $this->assertValidationErrors($expected_messages);
  }

  public static function providerInvalidComponentTree(): \Generator {
    yield "using DynamicPropSource" => [
      'component_tree' => [
        'tree' => self::encodeXBData([
          ComponentTreeStructure::ROOT_UUID => [
            ['uuid' => 'uuid-in-root', 'component' => 'sdc.xb_test_sdc.props-no-slots'],
          ],
        ]),
        'inputs' => self::encodeXBData([
          'uuid-in-root' => [
            'heading' => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:article␝title␞␟value',
            ],
          ],
        ]),
      ],
      'expected_messages' => [
        'component_tree' => "The 'dynamic' prop source type must be absent.",
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function testRequiredPropertyValuesMissing(?array $additional_expected_validation_errors_when_missing = NULL): void {
    // @phpstan-ignore-next-line
    parent::testRequiredPropertyValuesMissing([
      'theme' => [
        'id' => 'This validation constraint is configured to inspect the properties <em class="placeholder">%parent.theme, %parent.region</em>, but some do not exist: <em class="placeholder">%parent.theme</em>.',
      ],
      'region' => [
        'id' => 'This validation constraint is configured to inspect the properties <em class="placeholder">%parent.theme, %parent.region</em>, but some do not exist: <em class="placeholder">%parent.region</em>.',
      ],
    ]);
  }

  /**
   * .
   *
   * @dataProvider providerForAutoSaveData
   */
  public function testForAutoSaveData(array $autoSaveData, array $expected_errors): void {
    try {
      assert($this->entity instanceof PageRegion);
      $this->entity->forAutoSaveData($autoSaveData);
      $this->assertSame([], $expected_errors);
    }
    catch (ConstraintViolationException $e) {
      $this->assertSame($expected_errors, self::violationsToArray($e->getConstraintViolationList()));
    }
  }

  public static function providerForAutoSaveData(): iterable {
    yield 'INVALID: missing component type' => [
      [
        'layout' => [
          [
            "nodeType" => "component",
            "slots" => [],
            "uuid" => "c3f3c22c-c22e-4bb6-ad16-635f069148e4",
          ],
        ],
        'model' => [],
      ],
      [
        '[a548b48d-58a8-4077-aa04-da9405a6f418][0][component]' => 'This field is missing.',
      ],
    ];
    yield 'INVALID: missing component' => [
      [
        'layout' => [
          [
            "nodeType" => "component",
            "slots" => [],
            "type" => "block.page_title_block",
          ],
        ],
        'model' => [],
      ],
      [
        '[a548b48d-58a8-4077-aa04-da9405a6f418][0][uuid]' => 'This field is missing.',
      ],
    ];
    yield 'VALID: single valid region node; other regions missing — these are restored automatically from the stored Page Regions' => [
      [
        'layout' => [
          [
            "nodeType" => "component",
            "slots" => [],
            "type" => "block.page_title_block",
            "uuid" => "c3f3c22c-c22e-4bb6-ad16-635f069148e4",
          ],
        ],
        'model' => [
          'c3f3c22c-c22e-4bb6-ad16-635f069148e4' => [
            'label' => '',
            'label_display' => FALSE,
          ],
        ],
      ],
      [],
    ];
  }

}
