<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Kernel\Config;

use Drupal\Core\Extension\ThemeInstallerInterface;
use Drupal\experience_builder\Entity\PageTemplate;
use Drupal\experience_builder\Exception\ConstraintViolationException;
use Drupal\experience_builder\Plugin\DataType\ComponentTreeStructure;
use Drupal\KernelTests\Core\Config\ConfigEntityValidationTestBase;
use Drupal\Tests\experience_builder\Traits\BetterConfigDependencyManagerTrait;
use Drupal\Tests\experience_builder\Traits\GenerateComponentConfigTrait;
use Drupal\Tests\experience_builder\Traits\TestDataUtilitiesTrait;

class PageTemplateValidationTest extends ConfigEntityValidationTestBase {

  use BetterConfigDependencyManagerTrait;
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
    $this->entity = PageTemplate::create([
      'theme' => 'stark',
      'component_trees' => [
        'sidebar_first' => NULL,
        'sidebar_second' => NULL,
        'content' => [
          'tree' => self::encodeXBData([
            ComponentTreeStructure::ROOT_UUID => [
              ['uuid' => 'uuid-in-root', 'component' => 'sdc.xb_test_sdc.props-no-slots'],
              ['uuid' => 'uuid-in-root-another', 'component' => 'sdc.xb_test_sdc.props-no-slots'],
              ['uuid' => 'uuid-main', 'component' => 'block.system_main_block'],
              ['uuid' => 'uuid-title', 'component' => 'block.page_title_block'],
              ['uuid' => 'uuid-messages', 'component' => 'block.system_messages_block'],
            ],
          ]),
          'props' => self::encodeXBData([
            'uuid-in-root' => [
              'heading' => $generate_static_prop_source('world'),
            ],
            'uuid-in-root-another' => [
              'heading' => $generate_static_prop_source('another world'),
            ],
          ]),
        ],
        'header' => NULL,
        'primary_menu' => NULL,
        'secondary_menu' => NULL,
        'footer' => NULL,
        'highlighted' => NULL,
        'help' => NULL,
        'page_top' => NULL,
        'page_bottom' => NULL,
        'breadcrumb' => NULL,
      ],
    ]);
    $this->entity->save();
  }

  /**
   * {@inheritdoc}
   */
  public function testEntityIsValid(): void {
    parent::testEntityIsValid();

    // Beyond validity, verify that the `orderby: key` in the config schema was
    // respected when saving the config entity.
    $saved_region_key_order = array_keys($this->entity->get('component_trees'));
    $sorted_region_key_order = $saved_region_key_order;
    sort($sorted_region_key_order);
    $this->assertSame($sorted_region_key_order, $saved_region_key_order);

    // Also validate config dependencies are computed correctly.
    $this->assertSame(
      [
        'config' => [
          'experience_builder.component.block.page_title_block',
          'experience_builder.component.block.system_main_block',
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
        'experience_builder.component.block.system_main_block',
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
      '' => "The 'theme' property cannot be changed.",
      'theme' => "Theme 'non_existent_theme' is not installed.",
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function testImmutableProperties(array $valid_values = []): void {
    $this->container->get(ThemeInstallerInterface::class)->install([
      'olivero',
    ]);
    // If we don't also change the `component_trees` value here, we will get
    // additional validation errors, because `theme` determines what key-value
    // pairs are expected under `component_trees`.
    // Given that this config entity type has only a single immutable property
    // (`theme`), setting a valid corresponding `component_trees` value prevents
    // that distraction.
    // @see core/themes/olivero/olivero.info.yml
    $this->entity->set('component_trees', [
      'header' => NULL,
      'primary_menu' => NULL,
      'secondary_menu' => NULL,
      'hero' => NULL,
      'highlighted' => NULL,
      'breadcrumb' => NULL,
      'social' => [
        'tree' => self::encodeXBData([
          ComponentTreeStructure::ROOT_UUID => [
            ['uuid' => 'uuid-main', 'component' => 'block.system_main_block'],
            ['uuid' => 'uuid-title', 'component' => 'block.page_title_block'],
            ['uuid' => 'uuid-messages', 'component' => 'block.system_messages_block'],
          ],
        ]),
        'props' => '{}',
      ],
      'content_above' => NULL,
      'content' => NULL,
      'sidebar' => NULL,
      'content_below' => NULL,
      'footer_top' => NULL,
      'footer_bottom' => NULL,
    ]);
    parent::testImmutableProperties([
      'theme' => 'olivero',
    ]);
  }

  /**
   * @dataProvider providerInvalidComponentTrees
   * @covers \Drupal\experience_builder\Plugin\Validation\Constraint\ThemeRegionKeysConstraintValidator
   */
  public function testInvalidComponentTrees(array $component_trees, array $expected_messages): void {
    $this->entity->set('component_trees', $component_trees);
    $this->assertValidationErrors($expected_messages);
  }

  public static function providerInvalidComponentTrees(): \Generator {
    yield "missing `content` region and essential blocks" => [
      'component_trees' => [
        'sidebar_first' => NULL,
        'sidebar_second' => NULL,
        'header' => NULL,
        'primary_menu' => NULL,
        'secondary_menu' => NULL,
        'footer' => NULL,
        'highlighted' => NULL,
        'help' => NULL,
        'page_top' => NULL,
        'page_bottom' => NULL,
        'breadcrumb' => NULL,
      ],
      'expected_messages' => [
        'component_trees' => [
          'Configuration for the region "<em class="placeholder">content</em>" (<em class="placeholder">content</em>) is missing.',
          "The 'Drupal\Core\Block\MainContentBlockPluginInterface' component interface must be present.",
          "The 'Drupal\Core\Block\TitleBlockPluginInterface' component interface must be present.",
          "The 'Drupal\Core\Block\MessagesBlockPluginInterface' component interface must be present.",
        ],
      ],
    ];

    yield "missing one essential block" => [
      'component_trees' => [
        'sidebar_first' => NULL,
        'sidebar_second' => [
          'tree' => self::encodeXBData([
            ComponentTreeStructure::ROOT_UUID => [
              ['uuid' => 'uuid-main', 'component' => 'block.system_main_block'],
              ['uuid' => 'uuid-messages', 'component' => 'block.system_messages_block'],
            ],
          ]),
          'props' => '{}',
        ],
        'header' => NULL,
        'content' => NULL,
        'primary_menu' => NULL,
        'secondary_menu' => NULL,
        'footer' => NULL,
        'highlighted' => NULL,
        'help' => NULL,
        'page_top' => NULL,
        'page_bottom' => NULL,
        'breadcrumb' => NULL,
      ],
      'expected_messages' => [
        'component_trees' => "The 'Drupal\Core\Block\TitleBlockPluginInterface' component interface must be present.",
      ],
    ];

    yield "missing `content` region" => [
      'component_trees' => [
        'sidebar_first' => NULL,
        'sidebar_second' => [
          'tree' => self::encodeXBData([
            ComponentTreeStructure::ROOT_UUID => [
              ['uuid' => 'uuid-main', 'component' => 'block.system_main_block'],
              ['uuid' => 'uuid-title', 'component' => 'block.page_title_block'],
              ['uuid' => 'uuid-messages', 'component' => 'block.system_messages_block'],
            ],
          ]),
          'props' => '{}',
        ],
        'header' => NULL,
        'primary_menu' => NULL,
        'secondary_menu' => NULL,
        'footer' => NULL,
        'highlighted' => NULL,
        'help' => NULL,
        'page_top' => NULL,
        'page_bottom' => NULL,
        'breadcrumb' => NULL,
      ],
      'expected_messages' => [
        'component_trees' => 'Configuration for the region "<em class="placeholder">content</em>" (<em class="placeholder">content</em>) is missing.',
      ],
    ];

    yield "missing `content` and `highlighted` regions" => [
      'component_trees' => [
        'sidebar_first' => NULL,
        'sidebar_second' => [
          'tree' => self::encodeXBData([
            ComponentTreeStructure::ROOT_UUID => [
              ['uuid' => 'uuid-main', 'component' => 'block.system_main_block'],
              ['uuid' => 'uuid-title', 'component' => 'block.page_title_block'],
              ['uuid' => 'uuid-messages', 'component' => 'block.system_messages_block'],
            ],
          ]),
          'props' => '{}',
        ],
        'header' => NULL,
        'primary_menu' => NULL,
        'secondary_menu' => NULL,
        'footer' => NULL,
        'help' => NULL,
        'page_top' => NULL,
        'page_bottom' => NULL,
        'breadcrumb' => NULL,
      ],
      'expected_messages' => [
        'component_trees' => [
          'Configuration for the region "<em class="placeholder">content</em>" (<em class="placeholder">content</em>) is missing.',
          'Configuration for the region "<em class="placeholder">highlighted</em>" (<em class="placeholder">highlighted</em>) is missing.',
        ],
      ],
    ];

    yield "non-existent `foobar` region" => [
      'component_trees' => [
        'sidebar_first' => NULL,
        'sidebar_second' => [
          'tree' => self::encodeXBData([
            ComponentTreeStructure::ROOT_UUID => [
              ['uuid' => 'uuid-main', 'component' => 'block.system_main_block'],
              ['uuid' => 'uuid-title', 'component' => 'block.page_title_block'],
              ['uuid' => 'uuid-messages', 'component' => 'block.system_messages_block'],
            ],
          ]),
          'props' => '{}',
        ],
        'content' => NULL,
        'header' => NULL,
        'primary_menu' => NULL,
        'secondary_menu' => NULL,
        'footer' => NULL,
        'highlighted' => NULL,
        'help' => NULL,
        'page_top' => NULL,
        'page_bottom' => NULL,
        'foobar' => NULL,
        'breadcrumb' => NULL,
      ],
      'expected_messages' => [
        'component_trees.foobar' => '<em class="placeholder">foobar</em> is an unknown region.',
      ],
    ];

    yield "using DynamicPropSource" => [
      'component_trees' => [
        'sidebar_first' => NULL,
        'sidebar_second' => [
          'tree' => self::encodeXBData([
            ComponentTreeStructure::ROOT_UUID => [
              ['uuid' => 'uuid-main', 'component' => 'block.system_main_block'],
              ['uuid' => 'uuid-title', 'component' => 'block.page_title_block'],
              ['uuid' => 'uuid-messages', 'component' => 'block.system_messages_block'],
            ],
          ]),
          'props' => '{}',
        ],
        'content' => NULL,
        'header' => NULL,
        'primary_menu' => NULL,
        'secondary_menu' => NULL,
        'footer' => NULL,
        'highlighted' => NULL,
        'help' => NULL,
        'page_top' => NULL,
        'page_bottom' => NULL,
        'breadcrumb' => [
          'tree' => self::encodeXBData([
            ComponentTreeStructure::ROOT_UUID => [
              ['uuid' => 'uuid-in-root', 'component' => 'sdc.xb_test_sdc.props-no-slots'],
            ],
          ]),
          'props' => self::encodeXBData([
            'uuid-in-root' => [
              'heading' => [
                'sourceType' => 'dynamic',
                'expression' => 'ℹ︎␜entity:node:article␝title␞␟value',
              ],
            ],
          ]),
        ],
      ],
      'expected_messages' => [
        'component_trees' => "The 'dynamic' prop source type must be absent.",
      ],
    ];
  }

  /**
   * .
   *
   * @dataProvider providerInvalid
   */
  public function testInvalidAutoSave(array $autoSaveData): void {
    $this->expectException(ConstraintViolationException::class);
    $entity = PageTemplate::create([
      'theme' => 'stark',
    ]);
    $entity->forAutoSaveData($autoSaveData);
  }

  public static function providerInvalid(): iterable {
    yield 'missing component type' => [
      [
        'layout' => [[
          "components" => [
          [
            "nodeType" => "component",
            "slots" => [],
            "uuid" => "c3f3c22c-c22e-4bb6-ad16-635f069148e4",
          ],
          ],
          "name" => "Header",
          "nodeType" => "region",
          "id" => "header",
        ],
        ],
        'model' => [],
      ],
    ];
    yield 'missing component uuid' => [
      [
        'layout' => [[
          "components" => [
          [
            "nodeType" => "component",
            "slots" => [],
            "type" => "block.page_title_block",
          ],
          ],
          "name" => "Header",
          "nodeType" => "region",
          "id" => "header",
        ],
        ],
        'model' => [],
      ],
    ];
  }

}
