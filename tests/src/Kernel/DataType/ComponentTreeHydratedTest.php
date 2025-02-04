<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Kernel\DataType;

use Drupal\Core\Access\AccessResultAllowed;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Render\Markup;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\TypedData\TypedDataManagerInterface;
use Drupal\experience_builder\HydratedTree;
use Drupal\experience_builder\Plugin\DataType\ComponentTreeHydrated;
use Drupal\experience_builder\Plugin\DataType\ComponentTreeStructure;
use Drupal\experience_builder\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\experience_builder\Traits\ConstraintViolationsTestTrait;
use Drupal\Tests\experience_builder\Traits\CreateTestJsComponentTrait;
use Drupal\Tests\experience_builder\Traits\GenerateComponentConfigTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;

/**
 * @coversDefaultClass \Drupal\experience_builder\Plugin\DataType\ComponentTreeHydrated
 * @group experience_builder
 */
class ComponentTreeHydratedTest extends KernelTestBase {

  use ConstraintViolationsTestTrait;
  use CreateTestJsComponentTrait;
  use GenerateComponentConfigTrait;
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'experience_builder',
    'xb_test_sdc',
    'block',
    // XB's dependencies (modules providing field types + widgets).
    'datetime',
    'file',
    'image',
    'media',
    'options',
    'path',
    'link',
    'system',
    'user',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->config('system.site')
      ->set('name', 'XB Test Site')
      ->set('slogan', 'Experience Builder Test Site')
      ->save();
    $this->generateComponentConfig();
    $this->createMyCtaComponentFromSdc();
    // Permissions are necessary to view code components (at this time).
    // @see \Drupal\experience_builder\Element\AstroIsland::preRenderIsland()
    $this->setUpCurrentUser(permissions: ['administer code components']);
  }

  /**
   * @dataProvider provider
   */
  public function test(array $tree, array $inputs, array $expected_value, array $expected_renderable, string $expected_html, array $expected_cache_tags): void {
    $typed_data_manager = $this->container->get(TypedDataManagerInterface::class);
    $field_item_definition = $typed_data_manager->createDataDefinition('field_item:component_tree');
    $component_tree_field_item = $typed_data_manager->createInstance('field_item:component_tree', [
      'name' => NULL,
      'parent' => NULL,
      'data_definition' => $field_item_definition,
    ]);
    assert($component_tree_field_item instanceof ComponentTreeItem);
    $component_tree_field_item->setValue([
      'tree' => json_encode($tree, JSON_UNESCAPED_UNICODE | JSON_FORCE_OBJECT),
      'inputs' => json_encode($inputs, JSON_UNESCAPED_UNICODE | JSON_FORCE_OBJECT),
    ]);

    // Every test case must be valid.
    $violations = $component_tree_field_item->validate();
    $this->assertSame([], self::violationsToArray($violations));

    // Assert that the corresponding hydrated component tree is valid, in both
    // representations:
    // 1. raw (`::getValue()`)
    // 2. Drupal renderable (`::toRenderable()`)
    // 3. the resulting HTML markup.assert($node->field_xb_test[0] instanceof ComponentTreeItem);
    $hydrated = $component_tree_field_item->get('hydrated');
    assert($hydrated instanceof ComponentTreeHydrated);
    $hydrated_value = $hydrated->getValue();
    $this->assertInstanceOf(HydratedTree::class, $hydrated_value);
    $this->assertSame($expected_value, $hydrated_value->getTree());
    $renderable = $hydrated->toRenderable();
    $this->assertEquals($expected_renderable, $renderable);
    $html = (string) $this->container->get(RendererInterface::class)->renderInIsolation($renderable);
    // Strip trailing whitespace to make heredocs easier to write.
    $html = preg_replace('/ +$/m', '', $html);
    $this->assertIsString($html);
    // Make it easier to write expectations containing root-relative URLs
    // pointing to XB-owned assets.
    $xb_dir_root_relative_url = base_path() . $this->getModulePath('experience_builder');
    $html = str_replace($xb_dir_root_relative_url, '::XB_DIR_BASE_URL::', $html);
    // Make it easier to write expectations containing root-relative URLs
    // pointing somewhere into the site-specific directory.
    $vfs_site_base_url = base_path() . $this->siteDirectory;
    $html = str_replace($vfs_site_base_url, '::SITE_DIR_BASE_URL::', $html);
    $this->assertSame($expected_html, $html);
    $this->assertSame($expected_cache_tags, array_values(CacheableMetadata::createFromRenderArray($renderable)->getCacheTags()));
  }

  public static function provider(): \Generator {
    $generate_static_prop_source = function (string $label): array {
      return [
        'sourceType' => 'static:field_item:string',
        'value' => "Hello, $label!",
        'expression' => 'ℹ︎string␟value',
      ];
    };

    // @todo Remove when minimum version is Drupal 11.1 following https://www.drupal.org/project/drupal/issues/3379725
    $additional = version_compare(\Drupal::VERSION, '11.1', '>=') ? [] : [
      'status' => TRUE,
      'info' => '',
      'view_mode' => '',
      'context_mapping' => [],
    ];

    yield 'empty component tree' => [
      'tree' => [
        ComponentTreeStructure::ROOT_UUID => [],
      ],
      'inputs' => [],
      'expected_value' => [
        ComponentTreeStructure::ROOT_UUID => [],
      ],
      'expected_renderable' => [],
      'expected_html' => '',
      'expected_cache_tags' => [],
    ];

    yield 'component tree with a single component that has unpopulated slots with default values' => [
      'tree' => [
        ComponentTreeStructure::ROOT_UUID => [
          ['uuid' => 'uuid-in-root', 'component' => 'sdc.xb_test_sdc.props-slots'],
        ],
      ],
      'inputs' => [
        'uuid-in-root' => [
          'heading' => $generate_static_prop_source('world'),
        ],
      ],
      'expected_value' => [
        ComponentTreeStructure::ROOT_UUID => [
          'uuid-in-root' => [
            'component' => 'sdc.xb_test_sdc.props-slots',
            'props' => ['heading' => 'Hello, world!'],
            'slots' => [
              // TRICKY: this is different from the *stored* representation of a
              // component tree (where empty slots must be omitted). Since this
              // is the *hydrated* representation of
              // component tree, each slot merits being explicitly present, and
              // list its default value.
              // @see \Drupal\experience_builder\Plugin\ExperienceBuilder\ComponentSource\SingleDirectoryComponent::hydrateComponent()
              // @see \Drupal\experience_builder\Plugin\Validation\Constraint\ComponentTreeStructureConstraintValidator
              // @see \Drupal\Tests\experience_builder\Kernel\DataType\ComponentTreeStructureTest
              'the_body' => '<p>Example value for <strong>the_body</strong> slot in <strong>prop-slots</strong> component.</p>',
              'the_footer' => 'Example value for <strong>the_footer</strong>.',
              'the_colophon' => '',
            ],
          ],
        ],
      ],
      'expected_renderable' => [
        ComponentTreeStructure::ROOT_UUID => [
          'uuid-in-root' => [
            '#type' => 'component',
            '#cache' => [
              'tags' => ['config:experience_builder.component.sdc.xb_test_sdc.props-slots'],
              'contexts' => [],
              'max-age' => Cache::PERMANENT,
            ],
            '#component' => 'xb_test_sdc:props-slots',
            '#props' => [
              'heading' => 'Hello, world!',
              'xb_uuid' => 'uuid-in-root',
              'xb_slot_ids' => ['the_body', 'the_footer', 'the_colophon'],
            ],
            '#slots' => [
              'the_body' => [
                // This string is the first example value for this slot.
                '#markup' => '<p>Example value for <strong>the_body</strong> slot in <strong>prop-slots</strong> component.</p>',
              ],
              'the_footer' => [
                // This string is the first example value for this slot.
                '#plain_text' => 'Example value for <strong>the_footer</strong>.',
              ],
              'the_colophon' => [
                // This slot has no example value defined.
                '#plain_text' => '',
              ],
            ],
            '#prefix' => Markup::create('<!-- xb-start-uuid-in-root -->'),
            '#suffix' => Markup::create('<!-- xb-end-uuid-in-root -->'),
            '#attached' => [
              'library' => [
                'core/components.xb_test_sdc--props-slots',
              ],
            ],
          ],
        ],
      ],
      'expected_html' => <<<HTML
<!-- xb-start-uuid-in-root --><div  data-component-id="xb_test_sdc:props-slots" style="font-family: Helvetica, Arial, sans-serif; width: 100%; height: 100vh; background-color: #f5f5f5; display: flex; justify-content: center; align-items: center; flex-direction: column; text-align: center; padding: 20px; box-sizing: border-box;">
  <h1 style="font-size: 3em; margin: 0.5em 0; color: #333;"><!-- xb-prop-start-uuid-in-root/heading -->Hello, world!<!-- xb-prop-end-uuid-in-root/heading --></h1>
  <div class="component--props-slots--body">
        <!-- xb-slot-start-uuid-in-root/the_body --><p>Example value for <strong>the_body</strong> slot in <strong>prop-slots</strong> component.</p><!-- xb-slot-end-uuid-in-root/the_body -->
    </div>
  <div class="component--props-slots--footer">
        <!-- xb-slot-start-uuid-in-root/the_footer -->Example value for &lt;strong&gt;the_footer&lt;/strong&gt;.<!-- xb-slot-end-uuid-in-root/the_footer -->
    </div>
  <div class="component--props-slots--colophon">
        <!-- xb-slot-start-uuid-in-root/the_colophon --><!-- xb-slot-end-uuid-in-root/the_colophon -->
    </div>
</div>
<!-- xb-end-uuid-in-root -->
HTML,
      'expected_cache_tags' => [
        'config:experience_builder.component.sdc.xb_test_sdc.props-slots',
      ],
    ];

    yield 'component tree with a single block component' => [
      'tree' => [
        ComponentTreeStructure::ROOT_UUID => [
          ['uuid' => 'uuid-in-root', 'component' => 'block.system_branding_block'],
        ],
      ],
      'inputs' => [
        'uuid-in-root' => [
          'label' => '',
          'label_display' => FALSE,
          'use_site_logo' => TRUE,
          'use_site_name' => TRUE,
          'use_site_slogan' => TRUE,
        ],
      ],
      'expected_value' => [
        ComponentTreeStructure::ROOT_UUID => [
          'uuid-in-root' => [
            'component' => 'block.system_branding_block',
            'settings' => [
              'label' => '',
              'label_display' => FALSE,
              'use_site_logo' => TRUE,
              'use_site_name' => TRUE,
              'use_site_slogan' => TRUE,
            ],
          ],
        ],
      ],
      'expected_renderable' => [
        ComponentTreeStructure::ROOT_UUID => [
          'uuid-in-root' => [
            '#access' => new AccessResultAllowed(),
            '#theme' => 'block',
            '#configuration' => [
              'id' => 'system_branding_block',
              'label' => '',
              'label_display' => FALSE,
              'provider' => 'system',
              'use_site_logo' => TRUE,
              'use_site_name' => TRUE,
              'use_site_slogan' => TRUE,
              'plugin_id' => 'system_branding_block',
              'default_settings' => [
                'id' => 'system_branding_block',
                'label' => 'Site branding',
                'label_display' => '',
                'provider' => 'system',
                'use_site_logo' => TRUE,
                'use_site_name' => TRUE,
                'use_site_slogan' => TRUE,
              ] + $additional,
            ],
            '#plugin_id' => 'system_branding_block',
            '#base_plugin_id' => 'system_branding_block',
            '#derivative_plugin_id' => NULL,
            '#id' => 'uuid-in-root',
            'content' => [
              'site_logo' => [
                '#theme' => "image",
                '#uri' => NULL,
                '#alt' => 'Home',
                '#access' => TRUE,
              ],
              'site_name' => [
                '#markup' => 'XB Test Site',
                '#access' => TRUE,
              ],
              'site_slogan' => [
                '#markup' => 'Experience Builder Test Site',
                '#access' => TRUE,
              ],
            ],
            '#cache' => [
              'tags' => ['config:experience_builder.component.block.system_branding_block'],
              'contexts' => [],
              'max-age' => Cache::PERMANENT,
            ],
            '#prefix' => '',
            '#suffix' => '',
          ],
        ],
      ],
      'expected_html' => <<<HTML
<!-- xb-start-uuid-in-root --><div id="block-uuid-in-root">


          <a href="/" rel="home">XB Test Site</a>
    Experience Builder Test Site
</div>
<!-- xb-end-uuid-in-root -->
HTML,
      'expected_cache_tags' => [
        'config:experience_builder.component.block.system_branding_block',
      ],
    ];

    yield 'simplest component tree without nesting' => [
      'tree' => [
        ComponentTreeStructure::ROOT_UUID => [
          ['uuid' => 'uuid-in-root', 'component' => 'sdc.xb_test_sdc.props-no-slots'],
          ['uuid' => 'uuid-in-root-another', 'component' => 'sdc.xb_test_sdc.props-no-slots'],
        ],
      ],
      'inputs' => [
        'uuid-in-root' => [
          'heading' => $generate_static_prop_source('world'),
        ],
        'uuid-in-root-another' => [
          'heading' => $generate_static_prop_source('another world'),
        ],
      ],
      'expected_value' => [
        ComponentTreeStructure::ROOT_UUID => [
          'uuid-in-root' => [
            'component' => 'sdc.xb_test_sdc.props-no-slots',
            'props' => ['heading' => 'Hello, world!'],
          ],
          'uuid-in-root-another' => [
            'component' => 'sdc.xb_test_sdc.props-no-slots',
            'props' => ['heading' => 'Hello, another world!'],
          ],
        ],
      ],
      'expected_renderable' => [
        ComponentTreeStructure::ROOT_UUID => [
          'uuid-in-root' => [
            '#type' => 'component',
            '#cache' => [
              'tags' => ['config:experience_builder.component.sdc.xb_test_sdc.props-no-slots'],
              'contexts' => [],
              'max-age' => Cache::PERMANENT,
            ],
            '#component' => 'xb_test_sdc:props-no-slots',
            '#props' => [
              'heading' => 'Hello, world!',
              'xb_uuid' => 'uuid-in-root',
              'xb_slot_ids' => [],
            ],
            '#prefix' => Markup::create('<!-- xb-start-uuid-in-root -->'),
            '#suffix' => Markup::create('<!-- xb-end-uuid-in-root -->'),
            '#attached' => [
              'library' => [
                'core/components.xb_test_sdc--props-no-slots',
              ],
            ],
          ],
          'uuid-in-root-another' => [
            '#type' => 'component',
            '#cache' => [
              'tags' => ['config:experience_builder.component.sdc.xb_test_sdc.props-no-slots'],
              'contexts' => [],
              'max-age' => Cache::PERMANENT,
            ],
            '#component' => 'xb_test_sdc:props-no-slots',
            '#props' => [
              'heading' => 'Hello, another world!',
              'xb_uuid' => 'uuid-in-root-another',
              'xb_slot_ids' => [],
            ],
            '#prefix' => Markup::create('<!-- xb-start-uuid-in-root-another -->'),
            '#suffix' => Markup::create('<!-- xb-end-uuid-in-root-another -->'),
            '#attached' => [
              'library' => [
                'core/components.xb_test_sdc--props-no-slots',
              ],
            ],
          ],
        ],
      ],
      'expected_html' => <<<HTML
<!-- xb-start-uuid-in-root --><div  data-component-id="xb_test_sdc:props-no-slots" style="font-family: Helvetica, Arial, sans-serif; width: 100%; height: 100vh; background-color: #f5f5f5; display: flex; justify-content: center; align-items: center; flex-direction: column; text-align: center; padding: 20px; box-sizing: border-box;">
  <h1 style="font-size: 3em; margin: 0.5em 0; color: #333;"><!-- xb-prop-start-uuid-in-root/heading -->Hello, world!<!-- xb-prop-end-uuid-in-root/heading --></h1>
</div>
<!-- xb-end-uuid-in-root --><!-- xb-start-uuid-in-root-another --><div  data-component-id="xb_test_sdc:props-no-slots" style="font-family: Helvetica, Arial, sans-serif; width: 100%; height: 100vh; background-color: #f5f5f5; display: flex; justify-content: center; align-items: center; flex-direction: column; text-align: center; padding: 20px; box-sizing: border-box;">
  <h1 style="font-size: 3em; margin: 0.5em 0; color: #333;"><!-- xb-prop-start-uuid-in-root-another/heading -->Hello, another world!<!-- xb-prop-end-uuid-in-root-another/heading --></h1>
</div>
<!-- xb-end-uuid-in-root-another -->
HTML,
      'expected_cache_tags' => [
        'config:experience_builder.component.sdc.xb_test_sdc.props-no-slots',
      ],
    ];

    yield 'simplest component tree with nesting' => [
      'tree' => [
        ComponentTreeStructure::ROOT_UUID => [
          ['uuid' => 'uuid-in-root', 'component' => 'sdc.xb_test_sdc.props-slots'],
        ],
        'uuid-in-root' => [
          'the_body' => [
            ['uuid' => 'uuid-in-slot', 'component' => 'sdc.xb_test_sdc.props-no-slots'],
          ],
        ],
      ],
      'inputs' => [
        'uuid-in-root' => [
          'heading' => $generate_static_prop_source('world'),
        ],
        'uuid-in-slot' => [
          'heading' => $generate_static_prop_source('from a slot'),
        ],
      ],
      'expected_value' => [
        ComponentTreeStructure::ROOT_UUID => [
          'uuid-in-root' => [
            'component' => 'sdc.xb_test_sdc.props-slots',
            'props' => ['heading' => 'Hello, world!'],
            'slots' => [
              'the_footer' => 'Example value for <strong>the_footer</strong>.',
              'the_colophon' => '',
              'the_body' => [
                'uuid-in-slot' => [
                  'component' => 'sdc.xb_test_sdc.props-no-slots',
                  'props' => ['heading' => 'Hello, from a slot!'],
                ],
              ],
            ],
          ],
        ],
      ],
      'expected_renderable' => [
        ComponentTreeStructure::ROOT_UUID => [
          'uuid-in-root' => [
            '#type' => 'component',
            '#cache' => [
              'tags' => ['config:experience_builder.component.sdc.xb_test_sdc.props-slots'],
              'contexts' => [],
              'max-age' => Cache::PERMANENT,
            ],
            '#component' => 'xb_test_sdc:props-slots',
            '#props' => [
              'heading' => 'Hello, world!',
              'xb_uuid' => 'uuid-in-root',
              'xb_slot_ids' => ['the_body', 'the_footer', 'the_colophon'],
            ],
            '#slots' => [
              'the_footer' => [
                '#plain_text' => 'Example value for <strong>the_footer</strong>.',
              ],
              'the_colophon' => ['#plain_text' => ''],
              'the_body' => [
                'uuid-in-slot' => [
                  '#type' => 'component',
                  '#cache' => [
                    'tags' => ['config:experience_builder.component.sdc.xb_test_sdc.props-no-slots'],
                    'contexts' => [],
                    'max-age' => Cache::PERMANENT,
                  ],
                  '#component' => 'xb_test_sdc:props-no-slots',
                  '#props' => [
                    'heading' => 'Hello, from a slot!',
                    'xb_uuid' => 'uuid-in-slot',
                    'xb_slot_ids' => [],
                  ],
                  '#prefix' => Markup::create('<!-- xb-start-uuid-in-slot -->'),
                  '#suffix' => Markup::create('<!-- xb-end-uuid-in-slot -->'),
                  '#attached' => [
                    'library' => [
                      'core/components.xb_test_sdc--props-no-slots',
                    ],
                  ],
                ],
              ],
            ],
            '#prefix' => Markup::create('<!-- xb-start-uuid-in-root -->'),
            '#suffix' => Markup::create('<!-- xb-end-uuid-in-root -->'),
            '#attached' => [
              'library' => [
                'core/components.xb_test_sdc--props-slots',
              ],
            ],
          ],
        ],
      ],
      'expected_html' => <<<HTML
<!-- xb-start-uuid-in-root --><div  data-component-id="xb_test_sdc:props-slots" style="font-family: Helvetica, Arial, sans-serif; width: 100%; height: 100vh; background-color: #f5f5f5; display: flex; justify-content: center; align-items: center; flex-direction: column; text-align: center; padding: 20px; box-sizing: border-box;">
  <h1 style="font-size: 3em; margin: 0.5em 0; color: #333;"><!-- xb-prop-start-uuid-in-root/heading -->Hello, world!<!-- xb-prop-end-uuid-in-root/heading --></h1>
  <div class="component--props-slots--body">
        <!-- xb-slot-start-uuid-in-root/the_body --><!-- xb-start-uuid-in-slot --><div  data-component-id="xb_test_sdc:props-no-slots" style="font-family: Helvetica, Arial, sans-serif; width: 100%; height: 100vh; background-color: #f5f5f5; display: flex; justify-content: center; align-items: center; flex-direction: column; text-align: center; padding: 20px; box-sizing: border-box;">
  <h1 style="font-size: 3em; margin: 0.5em 0; color: #333;"><!-- xb-prop-start-uuid-in-slot/heading -->Hello, from a slot!<!-- xb-prop-end-uuid-in-slot/heading --></h1>
</div>
<!-- xb-end-uuid-in-slot --><!-- xb-slot-end-uuid-in-root/the_body -->
    </div>
  <div class="component--props-slots--footer">
        <!-- xb-slot-start-uuid-in-root/the_footer -->Example value for &lt;strong&gt;the_footer&lt;/strong&gt;.<!-- xb-slot-end-uuid-in-root/the_footer -->
    </div>
  <div class="component--props-slots--colophon">
        <!-- xb-slot-start-uuid-in-root/the_colophon --><!-- xb-slot-end-uuid-in-root/the_colophon -->
    </div>
</div>
<!-- xb-end-uuid-in-root -->
HTML,
      'expected_cache_tags' => [
        'config:experience_builder.component.sdc.xb_test_sdc.props-slots',
        'config:experience_builder.component.sdc.xb_test_sdc.props-no-slots',
      ],
    ];

    yield 'component tree with complex nesting' => [
      'tree' => [
        // Note how these are NOT sequentially ordered.
        'uuid-in-root' => [
          'the_body' => [
            ['uuid' => 'uuid-level-1', 'component' => 'sdc.xb_test_sdc.props-slots'],
          ],
        ],
        'uuid-level-2' => [
          'the_body' => [
            ['uuid' => 'uuid-level-3', 'component' => 'sdc.xb_test_sdc.props-no-slots'],
            ['uuid' => 'uuid-block', 'component' => 'block.system_branding_block'],
            ['uuid' => 'uuid-js-component', 'component' => 'js.my-cta'],
            ['uuid' => 'uuid-last-in-tree', 'component' => 'sdc.xb_test_sdc.props-no-slots'],
          ],
        ],
        'uuid-level-1' => [
          'the_body' => [
            ['uuid' => 'uuid-level-2', 'component' => 'sdc.xb_test_sdc.props-slots'],
          ],
        ],
        ComponentTreeStructure::ROOT_UUID => [
          ['uuid' => 'uuid-in-root', 'component' => 'sdc.xb_test_sdc.props-slots'],
        ],
      ],
      'inputs' => [
        // Note how these are NOT sequentially ordered, but in a different way.
        'uuid-in-root' => [
          'heading' => $generate_static_prop_source('world'),
        ],
        'uuid-level-3' => ['heading' => $generate_static_prop_source('from slot level 3')],
        'uuid-level-1' => ['heading' => $generate_static_prop_source('from slot level 1')],
        'uuid-last-in-tree' => ['heading' => $generate_static_prop_source('from slot <LAST ONE>')],
        'uuid-level-2' => ['heading' => $generate_static_prop_source('from slot level 2')],
        'uuid-block' => [
          'label' => '',
          'label_display' => FALSE,
          'use_site_logo' => TRUE,
          'use_site_name' => TRUE,
          'use_site_slogan' => TRUE,
        ],
        'uuid-js-component' => ['text' => $generate_static_prop_source('from a "code component"')],
      ],
      'expected_value' => [
        // Note how these are sequentially ordered.
        ComponentTreeStructure::ROOT_UUID => [
          'uuid-in-root' => [
            'component' => 'sdc.xb_test_sdc.props-slots',
            'props' => ['heading' => 'Hello, world!'],
            'slots' => [
              'the_footer' => 'Example value for <strong>the_footer</strong>.',
              'the_colophon' => '',
              'the_body' => [
                'uuid-level-1' => [
                  'component' => 'sdc.xb_test_sdc.props-slots',
                  'props' => ['heading' => 'Hello, from slot level 1!'],
                  'slots' => [
                    'the_footer' => 'Example value for <strong>the_footer</strong>.',
                    'the_colophon' => '',
                    'the_body' => [
                      'uuid-level-2' => [
                        'component' => 'sdc.xb_test_sdc.props-slots',
                        'props' => ['heading' => 'Hello, from slot level 2!'],
                        'slots' => [
                          'the_footer' => 'Example value for <strong>the_footer</strong>.',
                          'the_colophon' => '',
                          'the_body' => [
                            'uuid-level-3' => [
                              'component' => 'sdc.xb_test_sdc.props-no-slots',
                              'props' => ['heading' => 'Hello, from slot level 3!'],
                            ],
                            'uuid-block' => [
                              'component' => 'block.system_branding_block',
                              'settings' => [
                                'label' => '',
                                'label_display' => FALSE,
                                'use_site_logo' => TRUE,
                                'use_site_name' => TRUE,
                                'use_site_slogan' => TRUE,
                              ],
                            ],
                            'uuid-js-component' => [
                              'component' => 'js.my-cta',
                              'props' => ['text' => 'Hello, from a "code component"!'],
                            ],
                            'uuid-last-in-tree' => [
                              'component' => 'sdc.xb_test_sdc.props-no-slots',
                              'props' => ['heading' => 'Hello, from slot <LAST ONE>!'],
                            ],
                          ],
                        ],
                      ],
                    ],
                  ],
                ],
              ],
            ],
          ],
        ],
      ],
      'expected_renderable' => [
        // Note how these are sequentially ordered.
        ComponentTreeStructure::ROOT_UUID => [
          'uuid-in-root' => [
            '#type' => 'component',
            '#cache' => [
              'tags' => ['config:experience_builder.component.sdc.xb_test_sdc.props-slots'],
              'contexts' => [],
              'max-age' => Cache::PERMANENT,
            ],
            '#component' => 'xb_test_sdc:props-slots',
            '#props' => [
              'heading' => 'Hello, world!',
              'xb_uuid' => 'uuid-in-root',
              'xb_slot_ids' => ['the_body', 'the_footer', 'the_colophon'],
            ],
            '#slots' => [
              'the_footer' => [
                '#plain_text' => 'Example value for <strong>the_footer</strong>.',
              ],
              'the_colophon' => ['#plain_text' => ''],
              'the_body' => [
                'uuid-level-1' => [
                  '#type' => 'component',
                  '#cache' => [
                    'tags' => ['config:experience_builder.component.sdc.xb_test_sdc.props-slots'],
                    'contexts' => [],
                    'max-age' => Cache::PERMANENT,
                  ],
                  '#component' => 'xb_test_sdc:props-slots',
                  '#props' => [
                    'heading' => 'Hello, from slot level 1!',
                    'xb_uuid' => 'uuid-level-1',
                    'xb_slot_ids' => ['the_body', 'the_footer', 'the_colophon'],
                  ],
                  '#slots' => [
                    'the_footer' => [
                      // This string is the first example value for this slot.
                      '#plain_text' => 'Example value for <strong>the_footer</strong>.',
                    ],
                    'the_colophon' => [
                      // This slot has no example value defined.
                      '#plain_text' => '',
                    ],
                    'the_body' => [
                      'uuid-level-2' => [
                        '#type' => 'component',
                        '#cache' => [
                          'tags' => ['config:experience_builder.component.sdc.xb_test_sdc.props-slots'],
                          'contexts' => [],
                          'max-age' => Cache::PERMANENT,
                        ],
                        '#component' => 'xb_test_sdc:props-slots',
                        '#props' => [
                          'heading' => 'Hello, from slot level 2!',
                          'xb_uuid' => 'uuid-level-2',
                          'xb_slot_ids' => ['the_body', 'the_footer', 'the_colophon'],
                        ],
                        '#slots' => [
                          'the_footer' => [
                            '#plain_text' => 'Example value for <strong>the_footer</strong>.',
                          ],
                          'the_colophon' => ['#plain_text' => ''],
                          'the_body' => [
                            'uuid-level-3' => [
                              '#type' => 'component',
                              '#cache' => [
                                'tags' => ['config:experience_builder.component.sdc.xb_test_sdc.props-no-slots'],
                                'contexts' => [],
                                'max-age' => Cache::PERMANENT,
                              ],
                              '#component' => 'xb_test_sdc:props-no-slots',
                              '#props' => [
                                'heading' => 'Hello, from slot level 3!',
                                'xb_uuid' => 'uuid-level-3',
                                'xb_slot_ids' => [],
                              ],
                              '#prefix' => Markup::create('<!-- xb-start-uuid-level-3 -->'),
                              '#suffix' => Markup::create('<!-- xb-end-uuid-level-3 -->'),
                              '#attached' => [
                                'library' => [
                                  'core/components.xb_test_sdc--props-no-slots',
                                ],
                              ],
                            ],
                            'uuid-block' => [
                              '#access' => new AccessResultAllowed(),
                              '#theme' => 'block',
                              '#configuration' => [
                                'id' => 'system_branding_block',
                                'label' => '',
                                'label_display' => FALSE,
                                'provider' => 'system',
                                'use_site_logo' => TRUE,
                                'use_site_name' => TRUE,
                                'use_site_slogan' => TRUE,
                                'plugin_id' => 'system_branding_block',
                                'default_settings' => [
                                  'id' => 'system_branding_block',
                                  'label' => 'Site branding',
                                  'label_display' => '',
                                  'provider' => 'system',
                                  'use_site_logo' => TRUE,
                                  'use_site_name' => TRUE,
                                  'use_site_slogan' => TRUE,
                                ] + $additional,
                              ],
                              '#plugin_id' => 'system_branding_block',
                              '#base_plugin_id' => 'system_branding_block',
                              '#derivative_plugin_id' => NULL,
                              '#id' => 'uuid-block',
                              'content' => [
                                'site_logo' => [
                                  '#theme' => 'image',
                                  '#uri' => NULL,
                                  '#alt' => 'Home',
                                  '#access' => TRUE,
                                ],
                                'site_name' => [
                                  '#markup' => 'XB Test Site',
                                  '#access' => TRUE,
                                ],
                                'site_slogan' => [
                                  '#markup' => 'Experience Builder Test Site',
                                  '#access' => TRUE,
                                ],
                              ],
                              '#cache' => [
                                'tags' => ['config:experience_builder.component.block.system_branding_block'],
                                'contexts' => [],
                                'max-age' => Cache::PERMANENT,
                              ],
                              '#prefix' => Markup::create('<!-- xb-start-uuid-block -->'),
                              '#suffix' => Markup::create('<!-- xb-end-uuid-block -->'),
                            ],
                            'uuid-js-component' => [
                              '#type' => 'astro_island',
                              '#cache' => [
                                'tags' => ['config:experience_builder.component.js.my-cta'],
                                'contexts' => [],
                                'max-age' => Cache::PERMANENT,
                              ],
                              '#component' => 'my-cta',
                              '#props' => [
                                'text' => 'Hello, from a "code component"!',
                                'xb_uuid' => 'uuid-js-component',
                                'xb_slot_ids' => [],
                              ],
                              '#prefix' => Markup::create('<!-- xb-start-uuid-js-component -->'),
                              '#suffix' => Markup::create('<!-- xb-end-uuid-js-component -->'),
                              '#uuid' => 'uuid-js-component',
                            ],
                            'uuid-last-in-tree' => [
                              '#type' => 'component',
                              '#cache' => [
                                'tags' => ['config:experience_builder.component.sdc.xb_test_sdc.props-no-slots'],
                                'contexts' => [],
                                'max-age' => Cache::PERMANENT,
                              ],
                              '#component' => 'xb_test_sdc:props-no-slots',
                              '#props' => [
                                'heading' => 'Hello, from slot <LAST ONE>!',
                                'xb_uuid' => 'uuid-last-in-tree',
                                'xb_slot_ids' => [],
                              ],
                              '#prefix' => Markup::create('<!-- xb-start-last-in-tree -->'),
                              '#suffix' => Markup::create('<!-- xb-end-uuid-last-in-tree -->'),
                              '#attached' => [
                                'library' => [
                                  'core/components.xb_test_sdc--props-no-slots',
                                ],
                              ],
                            ],
                          ],
                        ],
                        '#prefix' => Markup::create('<!-- xb-start-uuid-level-2 -->'),
                        '#suffix' => Markup::create('<!-- xb-end-uuid-level-2 -->'),
                        '#attached' => [
                          'library' => [
                            'core/components.xb_test_sdc--props-slots',
                          ],
                        ],
                      ],
                    ],
                  ],
                  '#prefix' => Markup::create('<!-- xb-start-uuid-level-1 -->'),
                  '#suffix' => Markup::create('<!-- xb-end-uuid-level-1 -->'),
                  '#attached' => [
                    'library' => [
                      'core/components.xb_test_sdc--props-slots',
                    ],
                  ],
                ],
              ],
            ],
            '#prefix' => Markup::create('<!-- xb-start-uuid-in-root -->'),
            '#suffix' => Markup::create('<!-- xb-end-uuid-in-root -->'),
            '#attached' => [
              'library' => [
                'core/components.xb_test_sdc--props-slots',
              ],
            ],
          ],
        ],
      ],
      'expected_html' => <<<HTML
<!-- xb-start-uuid-in-root --><div  data-component-id="xb_test_sdc:props-slots" style="font-family: Helvetica, Arial, sans-serif; width: 100%; height: 100vh; background-color: #f5f5f5; display: flex; justify-content: center; align-items: center; flex-direction: column; text-align: center; padding: 20px; box-sizing: border-box;">
  <h1 style="font-size: 3em; margin: 0.5em 0; color: #333;"><!-- xb-prop-start-uuid-in-root/heading -->Hello, world!<!-- xb-prop-end-uuid-in-root/heading --></h1>
  <div class="component--props-slots--body">
        <!-- xb-slot-start-uuid-in-root/the_body --><!-- xb-start-uuid-level-1 --><div  data-component-id="xb_test_sdc:props-slots" style="font-family: Helvetica, Arial, sans-serif; width: 100%; height: 100vh; background-color: #f5f5f5; display: flex; justify-content: center; align-items: center; flex-direction: column; text-align: center; padding: 20px; box-sizing: border-box;">
  <h1 style="font-size: 3em; margin: 0.5em 0; color: #333;"><!-- xb-prop-start-uuid-level-1/heading -->Hello, from slot level 1!<!-- xb-prop-end-uuid-level-1/heading --></h1>
  <div class="component--props-slots--body">
        <!-- xb-slot-start-uuid-level-1/the_body --><!-- xb-start-uuid-level-2 --><div  data-component-id="xb_test_sdc:props-slots" style="font-family: Helvetica, Arial, sans-serif; width: 100%; height: 100vh; background-color: #f5f5f5; display: flex; justify-content: center; align-items: center; flex-direction: column; text-align: center; padding: 20px; box-sizing: border-box;">
  <h1 style="font-size: 3em; margin: 0.5em 0; color: #333;"><!-- xb-prop-start-uuid-level-2/heading -->Hello, from slot level 2!<!-- xb-prop-end-uuid-level-2/heading --></h1>
  <div class="component--props-slots--body">
        <!-- xb-slot-start-uuid-level-2/the_body --><!-- xb-start-uuid-level-3 --><div  data-component-id="xb_test_sdc:props-no-slots" style="font-family: Helvetica, Arial, sans-serif; width: 100%; height: 100vh; background-color: #f5f5f5; display: flex; justify-content: center; align-items: center; flex-direction: column; text-align: center; padding: 20px; box-sizing: border-box;">
  <h1 style="font-size: 3em; margin: 0.5em 0; color: #333;"><!-- xb-prop-start-uuid-level-3/heading -->Hello, from slot level 3!<!-- xb-prop-end-uuid-level-3/heading --></h1>
</div>
<!-- xb-end-uuid-level-3 --><!-- xb-start-uuid-block --><div id="block-uuid-block">


          <a href="/" rel="home">XB Test Site</a>
    Experience Builder Test Site
</div>
<!-- xb-end-uuid-block --><!-- xb-start-uuid-js-component --><astro-island uid="uuid-js-component"
        component-url="::SITE_DIR_BASE_URL::/files/astro-island/STNRn46UCAs1xJCb2kgPiEOEZp0R24B5qjtHOsyYT-g.js"
        component-export="default"
        renderer-url="::XB_DIR_BASE_URL::/ui/lib/astro-hydration/dist/client.js"
        props="{&quot;text&quot;:&quot;Hello, from a \&quot;code component\&quot;!&quot;}"
        ssr="" client="only"
        opts="{&quot;name&quot;:&quot;My First Code Component&quot;,&quot;value&quot;:&quot;preact&quot;}"></astro-island><!-- xb-end-uuid-js-component --><!-- xb-start-uuid-last-in-tree --><div  data-component-id="xb_test_sdc:props-no-slots" style="font-family: Helvetica, Arial, sans-serif; width: 100%; height: 100vh; background-color: #f5f5f5; display: flex; justify-content: center; align-items: center; flex-direction: column; text-align: center; padding: 20px; box-sizing: border-box;">
  <h1 style="font-size: 3em; margin: 0.5em 0; color: #333;"><!-- xb-prop-start-uuid-last-in-tree/heading -->Hello, from slot &lt;LAST ONE&gt;!<!-- xb-prop-end-uuid-last-in-tree/heading --></h1>
</div>
<!-- xb-end-uuid-last-in-tree --><!-- xb-slot-end-uuid-level-2/the_body -->
    </div>
  <div class="component--props-slots--footer">
        <!-- xb-slot-start-uuid-level-2/the_footer -->Example value for &lt;strong&gt;the_footer&lt;/strong&gt;.<!-- xb-slot-end-uuid-level-2/the_footer -->
    </div>
  <div class="component--props-slots--colophon">
        <!-- xb-slot-start-uuid-level-2/the_colophon --><!-- xb-slot-end-uuid-level-2/the_colophon -->
    </div>
</div>
<!-- xb-end-uuid-level-2 --><!-- xb-slot-end-uuid-level-1/the_body -->
    </div>
  <div class="component--props-slots--footer">
        <!-- xb-slot-start-uuid-level-1/the_footer -->Example value for &lt;strong&gt;the_footer&lt;/strong&gt;.<!-- xb-slot-end-uuid-level-1/the_footer -->
    </div>
  <div class="component--props-slots--colophon">
        <!-- xb-slot-start-uuid-level-1/the_colophon --><!-- xb-slot-end-uuid-level-1/the_colophon -->
    </div>
</div>
<!-- xb-end-uuid-level-1 --><!-- xb-slot-end-uuid-in-root/the_body -->
    </div>
  <div class="component--props-slots--footer">
        <!-- xb-slot-start-uuid-in-root/the_footer -->Example value for &lt;strong&gt;the_footer&lt;/strong&gt;.<!-- xb-slot-end-uuid-in-root/the_footer -->
    </div>
  <div class="component--props-slots--colophon">
        <!-- xb-slot-start-uuid-in-root/the_colophon --><!-- xb-slot-end-uuid-in-root/the_colophon -->
    </div>
</div>
<!-- xb-end-uuid-in-root -->
HTML,
      'expected_cache_tags' => [
        'config:experience_builder.component.sdc.xb_test_sdc.props-slots',
        'config:experience_builder.component.sdc.xb_test_sdc.props-no-slots',
        'config:experience_builder.component.js.my-cta',
        'config:experience_builder.component.block.system_branding_block',
      ],
    ];
  }

}
