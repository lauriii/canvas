<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Kernel\Config;

use Drupal\Core\Extension\ThemeInstallerInterface;
use Drupal\experience_builder\Entity\PageTemplate;
use Drupal\experience_builder\Exception\ConstraintViolationException;
use Drupal\experience_builder\Plugin\DataType\ComponentTreeStructure;
use Drupal\KernelTests\Core\Config\ConfigEntityValidationTestBase;
use Drupal\Tests\experience_builder\Traits\BetterConfigDependencyManagerTrait;
use Drupal\Tests\experience_builder\Traits\ConstraintViolationsTestTrait;
use Drupal\Tests\experience_builder\Traits\GenerateComponentConfigTrait;
use Drupal\Tests\experience_builder\Traits\TestDataUtilitiesTrait;

class PageTemplateValidationTest extends ConfigEntityValidationTestBase {

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
      'editable' => [
        'sidebar_first' => TRUE,
        'sidebar_second' => TRUE,
        'content' => TRUE,
        'header' => TRUE,
        'primary_menu' => TRUE,
        'secondary_menu' => TRUE,
        'footer' => TRUE,
        'highlighted' => FALSE,
        'help' => FALSE,
        'page_top' => TRUE,
        'page_bottom' => TRUE,
        'breadcrumb' => TRUE,
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
    // If we don't also change the `component_trees` and `editable` values here,
    // we will get additional validation errors, because `theme` determines what
    // key-value are expected. Given that this config entity type has only a
    // single immutable property (`theme`), setting a valid corresponding values
    // prevents that distraction.
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
      ],
      'content_above' => NULL,
      'content' => NULL,
      'sidebar' => NULL,
      'content_below' => NULL,
      'footer_top' => NULL,
      'footer_bottom' => NULL,
    ]);
    $this->entity->set('editable', [
      'header' => TRUE,
      'primary_menu' => TRUE,
      'secondary_menu' => TRUE,
      'hero' => TRUE,
      'highlighted' => TRUE,
      'breadcrumb' => TRUE,
      'social' => TRUE,
      'content_above' => TRUE,
      'content' => TRUE,
      'sidebar' => TRUE,
      'content_below' => TRUE,
      'footer_top' => TRUE,
      'footer_bottom' => TRUE,
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
              ['uuid' => 'uuid-messages', 'component' => 'block.system_messages_block'],
            ],
          ]),
          'inputs' => self::encodeXBData(
            [
              'uuid-messages' => [
                'label' => '',
                'label_display' => FALSE,
              ],
            ],
          ),
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
        'component_trees' => "The 'Drupal\Core\Block\MainContentBlockPluginInterface' component interface must be present.",
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
          'inputs' => self::encodeXBData(
            [
              'uuid-messages' => [
                'label' => '',
                'label_display' => FALSE,
              ],
              'uuid-title' => [
                'label' => '',
                'label_display' => FALSE,
              ],
            ],
          ),
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
          'inputs' => self::encodeXBData(
            [
              'uuid-messages' => [
                'label' => '',
                'label_display' => FALSE,
              ],
              'uuid-title' => [
                'label' => '',
                'label_display' => FALSE,
              ],
            ],
          ),
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
          'inputs' => self::encodeXBData(
            [
              'uuid-messages' => [
                'label' => '',
                'label_display' => FALSE,
              ],
              'uuid-title' => [
                'label' => '',
                'label_display' => FALSE,
              ],
            ],
          ),
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
          'inputs' => self::encodeXBData(
            [
              'uuid-messages' => [
                'label' => '',
                'label_display' => FALSE,
              ],
              'uuid-title' => [
                'label' => '',
                'label_display' => FALSE,
              ],
            ],
          ),
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
          'inputs' => self::encodeXBData([
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
   * @dataProvider providerInvalidEditable
   * @covers \Drupal\experience_builder\Plugin\Validation\Constraint\ThemeRegionKeysConstraintValidator
   */
  public function testInvalidEditable(array $editable, array $expected_messages): void {
    $this->entity->set('editable', $editable);
    $this->assertValidationErrors($expected_messages);
  }

  public static function providerInvalidEditable(): \Generator {
    yield "missing `content` and `highlighted` regions" => [
      'editable' => [
        'sidebar_first' => TRUE,
        'sidebar_second' => TRUE,
        'header' => FALSE,
        'primary_menu' => FALSE,
        'secondary_menu' => FALSE,
        'footer' => TRUE,
        'help' => FALSE,
        'page_top' => FALSE,
        'page_bottom' => FALSE,
        'breadcrumb' => FALSE,
      ],
      'expected_messages' => [
        'editable' => [
          'Configuration for the region "<em class="placeholder">content</em>" (<em class="placeholder">content</em>) is missing.',
          'Configuration for the region "<em class="placeholder">highlighted</em>" (<em class="placeholder">highlighted</em>) is missing.',
        ],
      ],
    ];

    // @todo Add validation constraint to tighten this. Not urgent because \Drupal\experience_builder\Controller\ApiLayoutController::__invoke() ignores `content: false`, and pretends it's `content: true`.
    yield "🐛 required `content` region marked as non-editable" => [
      'editable' => [
        'sidebar_first' => TRUE,
        'sidebar_second' => TRUE,
        'header' => FALSE,
        'primary_menu' => FALSE,
        'secondary_menu' => FALSE,
        'footer' => TRUE,
        'help' => FALSE,
        'page_top' => FALSE,
        'page_bottom' => FALSE,
        'breadcrumb' => FALSE,
        'highlighted' => FALSE,
        'content' => FALSE,
      ],
      'expected_messages' => [],
    ];

    yield "invalid value" => [
      'editable' => [
        'sidebar_first' => TRUE,
        'sidebar_second' => TRUE,
        'header' => FALSE,
        'primary_menu' => FALSE,
        'secondary_menu' => FALSE,
        'footer' => TRUE,
        'help' => FALSE,
        'page_top' => FALSE,
        'page_bottom' => FALSE,
        'breadcrumb' => FALSE,
        'highlighted' => NULL,
        'content' => FALSE,
      ],
      'expected_messages' => [
        'editable.highlighted' => 'This value should not be null.',
      ],
    ];
  }

  /**
   * .
   *
   * @dataProvider providerForAutoSaveData
   */
  public function testForAutoSaveData(array $autoSaveData, array $expected_errors): void {
    try {
      assert($this->entity instanceof PageTemplate);
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
      [
        '[a548b48d-58a8-4077-aa04-da9405a6f418][0][component]' => 'This field is missing.',
      ],
    ];
    yield 'INVALID: missing component' => [
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
      [
        '[a548b48d-58a8-4077-aa04-da9405a6f418][0][uuid]' => 'This field is missing.',
      ],
    ];
    yield 'VALID: single valid region node; other regions missing — these are restored automatically from the stored PageTemplate' => [
      [
        'layout' => [[
          "components" => [
            [
              "nodeType" => "component",
              "slots" => [],
              "type" => "block.page_title_block",
              "uuid" => "c3f3c22c-c22e-4bb6-ad16-635f069148e4",
            ],
          ],
          "name" => "Header",
          "nodeType" => "region",
          "id" => "header",
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
