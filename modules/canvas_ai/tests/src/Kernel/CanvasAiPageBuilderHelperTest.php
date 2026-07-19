<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_ai\Kernel;

use Drupal\canvas\ComponentSource\ComponentSourceManager;
use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\JavaScriptComponent;
use Drupal\canvas\Entity\PageVariant;
use Drupal\canvas\Plugin\Canvas\ComponentSource\BlockComponent;
use Drupal\canvas\Plugin\Canvas\ComponentSource\JsComponent;
use Drupal\canvas\Plugin\Canvas\ComponentSource\Marker;
use Drupal\canvas\Plugin\Canvas\ComponentSource\SingleDirectoryComponent;
use Drupal\canvas_ai\CanvasAiPageBuilderHelper;
use Drupal\canvas_personalization\Plugin\Canvas\ComponentSource\Personalization;
use Drupal\Component\Serialization\Json;
use Drupal\Core\Block\BlockManagerInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Yaml\Yaml;

/**
 * Tests for the CanvasAiPageBuilderHelper.
 */
#[Group('canvas_ai')]
final class CanvasAiPageBuilderHelperTest extends CanvasKernelTestBase {

  use UserCreationTrait;

  /**
   * The CanvasAiPageBuilderHelper service.
   *
   * @var \Drupal\canvas_ai\CanvasAiPageBuilderHelper
   */
  protected CanvasAiPageBuilderHelper $canvasAiPageBuilderHelper;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'ai',
    'canvas_ai',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['system']);
    $this->installEntitySchema('path_alias');
    $this->installEntitySchema('user');
    $privileged_user = $this->createUser(['create canvas_page']);
    if (!$privileged_user instanceof User) {
      throw new \Exception('Failed to create test user');
    }
    $this->container->get('current_user')->setAccount($privileged_user);
    $this->canvasAiPageBuilderHelper = $this->container->get('canvas_ai.page_builder_helper');
  }

  /**
   * Tests the convertCurrentLayoutToTree method.
   */
  public function testConvertCurrentLayoutToTree(): void {
    $input = [
      "regions" => [
        "header" => [
          "nodePathPrefix" => [0],
          "components" => [
            [
              "name" => "sdc.starshot_demo.starshot-heading",
              "uuid" => "678e9ee1-dc49-4495-b7cb-9bdd5625a59b",
              "nodePath" => [0, 0],
            ],
          ],
        ],
        "content" => [
          "nodePathPrefix" => [1],
          "components" => [
            [
              "name" => "sdc.canvas_test_sdc.two_column",
              "uuid" => "2f957795-e30a-46a0-acfe-868adc0685bf",
              "nodePath" => [1, 0],
              "slots" => [
                "2f957795-e30a-46a0-acfe-868adc0685bf/column_one" => [
                  "components" => [
                    [
                      "name" => "sdc.canvas_test_sdc.image",
                      "uuid" => "837173ae-5940-4c48-a304-31c6d81901b5",
                      "nodePath" => [1, 0, 0, 0],
                    ],
                  ],
                ],
                "2f957795-e30a-46a0-acfe-868adc0685bf/column_two" => [
                  "components" => [
                    [
                      "name" => "sdc.canvas_test_sdc.druplicon",
                      "uuid" => "4e45ef4c-501c-4612-b02b-1911e88a4592",
                      "nodePath" => [1, 0, 1, 0],
                    ],
                  ],
                ],
              ],
            ],
          ],
        ],
        "footer" => [
          "nodePathPrefix" => [2],
          "components" => [],
        ],
      ],
    ];

    $expected_output = [
      "header" => [
        "678e9ee1-dc49-4495-b7cb-9bdd5625a59b" => [],
      ],
      "content" => [
        "2f957795-e30a-46a0-acfe-868adc0685bf" => [
          "column_one" => [
            "837173ae-5940-4c48-a304-31c6d81901b5" => [],
          ],
          "column_two" => [
            "4e45ef4c-501c-4612-b02b-1911e88a4592" => [],
          ],
        ],
      ],
      "footer" => [],
    ];

    $result = $this->canvasAiPageBuilderHelper->convertCurrentLayoutToTree($input);
    $this->assertEquals($expected_output, $result);
  }

  /**
   * Tests that admin-entered page variant descriptions reach the agent prompt.
   *
   * The settings form saves descriptions under `variant_descriptions`, keyed by
   * page variant id. getAvailableRegions() must surface the resolved variant's
   * description as the `info` for the content region the agent fills.
   */
  public function testGetAvailableRegionsSurfacesVariantDescription(): void {
    // The stored active version of the "Page content" marker component. Mirrors
    // config/install/canvas.component.marker.page_content.yml.
    $marker_version = '3b12c0b99a6caecc';

    // The marker component ships in config/install (not auto-discovered); make
    // it available so a valid variant can be seeded with the content marker.
    if (Component::load(Marker::PAGE_CONTENT_COMPONENT_ID) === NULL) {
      Component::create([
        'id' => Marker::PAGE_CONTENT_COMPONENT_ID,
        'label' => 'Page content',
        'provider' => 'canvas',
        'source' => Marker::SOURCE_PLUGIN_ID,
        'source_local_id' => Marker::PAGE_CONTENT_LOCAL_ID,
        'active_version' => $marker_version,
        'versioned_properties' => [
          'active' => [
            'settings' => [],
            'fallback_metadata' => ['slot_definitions' => []],
          ],
        ],
        'dependencies' => ['enforced' => ['module' => ['canvas']]],
      ])->save();
    }

    PageVariant::create([
      'id' => 'homepage',
      'label' => 'Homepage',
      'description' => 'Fallback description from the variant itself.',
      'component_tree' => [
        [
          'uuid' => \Drupal::service('uuid')->generate(),
          'component_id' => Marker::PAGE_CONTENT_COMPONENT_ID,
          'component_version' => $marker_version,
          'inputs' => [],
        ],
      ],
    ])->save();

    $layout = Json::encode([
      'regions' => [
        'content' => [
          'nodePathPrefix' => [0],
          'components' => [],
        ],
      ],
    ]);

    // Editing the variant directly, before any AI guidance is configured, falls
    // back to the variant's own description.
    $regions = $this->canvasAiPageBuilderHelper->getAvailableRegions($layout, PageVariant::ENTITY_TYPE_ID, 'homepage');
    $this->assertSame('Fallback description from the variant itself.', $regions['content']['info']);

    // Guidance saved through the settings form is what the agent sees.
    $guidance = 'Homepage variant: lead with a hero, then feature sections.';
    $this->config('canvas_ai.theme_region.settings')
      // The descriptions are translatable, so the config object needs a
      // langcode, exactly as ConfigFormBase sets when the settings form saves.
      ->set('langcode', 'en')
      ->set('variant_descriptions', [
        'homepage' => [
          'name' => 'Homepage',
          'description' => $guidance,
        ],
      ])
      ->save();

    $regions = $this->canvasAiPageBuilderHelper->getAvailableRegions($layout, PageVariant::ENTITY_TYPE_ID, 'homepage');
    $this->assertSame($guidance, $regions['content']['info']);
    $this->assertSame(0, $regions['content']['nodePathPrefix']);
  }

  /**
   * Tests the createExpectedPageLayout method.
   */
  public function testCreateExpectedPageLayout(): void {
    // Build the full current layout (not the tree), as expected by
    // createExpectedPageLayout().
    $current_layout = [
      "regions" => [
        "header" => [
          "nodePathPrefix" => [0],
          "components" => [
            [
              "name" => "sdc.starshot_demo.starshot-heading",
              "uuid" => "678e9ee1-dc49-4495-b7cb-9bdd5625a59b",
              "nodePath" => [0, 0],
            ],
          ],
        ],
        "content" => [
          "nodePathPrefix" => [1],
          "components" => [
            [
              "name" => "sdc.canvas_test_sdc.two_column",
              "uuid" => "2f957795-e30a-46a0-acfe-868adc0685bf",
              "nodePath" => [1, 0],
              "slots" => [
                "2f957795-e30a-46a0-acfe-868adc0685bf/column_one" => [
                  "components" => [
                    [
                      "name" => "sdc.canvas_test_sdc.image",
                      "uuid" => "837173ae-5940-4c48-a304-31c6d81901b5",
                      "nodePath" => [1, 0, 0, 0],
                      "slots" => [
                        "837173ae-5940-4c48-a304-31c6d81901b5/inner_slot" => [
                          "components" => [
                            [
                              "name" => "sdc.canvas_test_sdc.druplicon",
                              "uuid" => "7fd447a9-f1b3-4b9c-ae23-ee4b174f7b84",
                              "nodePath" => [1, 0, 1, 0, 0, 0],
                            ],
                          ],
                        ],
                      ],
                    ],
                  ],
                ],
                "2f957795-e30a-46a0-acfe-868adc0685bf/column_two" => [
                  "components" => [
                    [
                      "name" => "sdc.canvas_test_sdc.druplicon",
                      "uuid" => "4e45ef4c-501c-4612-b02b-1911e88a4592",
                      "nodePath" => [1, 0, 1, 0],
                    ],
                  ],
                ],
              ],
            ],
          ],
        ],
        "footer" => [
          "nodePathPrefix" => [2],
          "components" => [],
        ],
      ],
    ];

    $yaml = <<<YAML
operations:
  - target: 'content'
    reference_uuid: '7fd447a9-f1b3-4b9c-ae23-ee4b174f7b84'
    placement: 'above'
    components:
      - sdc.canvas_test_sdc.heading:
          uuid: '6a2f1ad8-0a1d-4fcb-9b7f-93fb3c1b9a7f'
          props:
            text: "Above existing component"
            element: "h2"

  - target: 'content'
    reference_uuid: '7fd447a9-f1b3-4b9c-ae23-ee4b174f7b84'
    placement: 'below'
    components:
      - sdc.canvas_test_sdc.heading:
          uuid: 'c42ef28c-86c4-4704-81d7-62d234d98b95'
          props:
            text: "Below existing component"
            element: "h2"

  - target: 'footer'
    reference_uuid: ''
    placement: 'inside'
    components:
      - sdc.canvas_test_sdc.heading:
          uuid: 'bb3c1b59-ff84-4e33-bfc4-2abbd1cb4d8f'
          props:
            text: "Some text"
            element: "h1"
YAML;

    $operations = Yaml::parse($yaml);

    $result = $this->canvasAiPageBuilderHelper->createExpectedPageLayout($current_layout, $operations);

    $expected = [
      "header" => [
        "678e9ee1-dc49-4495-b7cb-9bdd5625a59b" => [],
      ],
      "content" => [
        "2f957795-e30a-46a0-acfe-868adc0685bf" => [
          "column_one" => [
            "837173ae-5940-4c48-a304-31c6d81901b5" => [
              "inner_slot" => [
                "6a2f1ad8-0a1d-4fcb-9b7f-93fb3c1b9a7f" => [],
                "7fd447a9-f1b3-4b9c-ae23-ee4b174f7b84" => [],
                "c42ef28c-86c4-4704-81d7-62d234d98b95" => [],
              ],
            ],
          ],
          "column_two" => [
            "4e45ef4c-501c-4612-b02b-1911e88a4592" => [],
          ],
        ],
      ],
      "footer" => [
        "bb3c1b59-ff84-4e33-bfc4-2abbd1cb4d8f" => [],
      ],
    ];

    $this->assertEquals($expected, $result);
  }

  /**
   * Data provider for testing generateVerboseContextForOrchestrator.
   */
  public static function generateVerboseContextProvider(): array {
    return [
      'empty values' => [
        [
          'entity_type' => '',
          'selected_component' => '',
          'page_title' => 'Untitled page',
          'page_description' => '',
        ],
        'User has not created any entities',
      ],
      'node with component' => [
        [
          'entity_type' => 'node',
          'selected_component' => 'hero_banner',
          'page_title' => 'What is drupal canvas?',
          'page_description' => 'Drupal canvas is a visual page-builder tool for Drupal CMS',
        ],
        'User is now in the code component editor, viewing a code component with id hero_banner',
      ],
      'node without component' => [
        [
          'entity_type' => 'node',
          'selected_component' => '',
          'page_title' => 'What is drupal canvas?',
          'page_description' => 'Drupal canvas is a visual page-builder tool for Drupal CMS',
        ],
        'The user is currently working on a \'node\' entity',
      ],
      'canvas page' => [
        [
          'entity_type' => 'canvas_page',
          'selected_component' => '',
          'page_title' => 'What is drupal canvas?',
          'page_description' => 'Drupal canvas is a visual page-builder tool for Drupal CMS',
        ],
        'The user is currently working on a canvas_page entity. User has not selected any particular component from the page. Page title: What is drupal canvas?. Page description: Drupal canvas is a visual page-builder tool for Drupal CMS',
      ],
      'canvas page with selected component and empty fields' => [
        [
          'entity_type' => 'canvas_page',
          'selected_component' => '',
          'active_component_uuid' => 'f47ac10b-58cc-4372-a567-0e02b2c3d479',
          'page_title' => 'Untitled page',
          'page_description' => '',
        ],
        'The user is currently working on a canvas_page entity. User has selected a component in the page with uuid f47ac10b-58cc-4372-a567-0e02b2c3d479. Page title is empty. GENERATE THE TITLE FOR THE PAGE using canvas_title_generation_agent. This is a **CRITICAL** step to ensure that request is successful. Page description is empty. GENERATE THE DESCRIPTION FOR THE PAGE using canvas_metadata_generation_agent. This is a **CRITICAL** step to ensure that request is successful.',
      ],
    ];
  }

  /**
   * Tests the generateVerboseContextForOrchestrator method.
   */
  #[DataProvider('generateVerboseContextProvider')]
  public function testGenerateVerboseContextForOrchestrator(array $prompt, string $expected): void {
    $result = $this->canvasAiPageBuilderHelper->generateVerboseContextForOrchestrator($prompt);
    $this->assertEquals($expected, $result);
  }

  /**
   * Tests the formatMessageWithContext method.
   */
  public function testFormatMessageWithContext(): void {
    $context = 'This is system context';
    $userMessage = 'User wants to add a heading component';

    $expected = <<<XML
<context>
This is system context
</context>

<user_message>
User wants to add a heading component
</user_message>
XML;

    $result = $this->canvasAiPageBuilderHelper->formatMessageWithContext($context, $userMessage);
    $this->assertEquals($expected, $result);
  }

  /**
   * Tests that getAllComponentsKeyedBySource returns only sdc, block and js.
   *
   * @see \Drupal\canvas_dev_mode\Hook\UsePrivateApis::configSchemaInfoAlter()
   * @todo Remove canvas_dev_mode once ComponentSourceInterface is a public API,
   *   i.e. after https://www.drupal.org/i/3520484#stable is done.
   */
  public function testGetAllComponentsKeyedBySourceContainsOnlySdcBlockAndJs(): void {
    // canvas_dev_mode removes the static Choice constraint on canvas.component.*
    // source fields, allowing third-party sources like p13n to pass schema
    // validation.
    $this->enableModules(['canvas_dev_mode', 'canvas_personalization']);
    $this->installConfig(['canvas_personalization']);

    // Create a JavaScript component.
    JavaScriptComponent::create([
      'machineName' => 'test_js_component',
      'name' => 'Test JS Component',
      'status' => TRUE,
      'props' => [],
      'required' => [],
      'slots' => [],
      'js' => ['original' => 'console.log("test");', 'compiled' => 'console.log("test");'],
      'css' => ['original' => '', 'compiled' => ''],
      'dataDependencies' => [],
    ])->save();

    // Generate SDC component config entities.
    $this->container->get(ComponentSourceManager::class)->generateComponents();
    $helper = $this->container->get('canvas_ai.page_builder_helper');
    $result = $helper->getAllComponentsKeyedBySource();

    $this->assertArrayHasKey(SingleDirectoryComponent::SOURCE_PLUGIN_ID, $result);
    $this->assertArrayHasKey(BlockComponent::SOURCE_PLUGIN_ID, $result);
    $this->assertArrayHasKey(JsComponent::SOURCE_PLUGIN_ID, $result);
    $this->assertArrayNotHasKey(Personalization::SOURCE_PLUGIN_ID, $result);
  }

  /**
   * Tests that getAllComponentsKeyedBySource uses cache correctly.
   */
  public function testGetAllComponentsKeyedBySourceCaching(): void {
    $variation_cache = $this->container->get('variation_cache_factory')->get('default');
    $memory_variation_cache = $this->container->get('variation_cache_factory')->get('canvas_ai_memory');
    $cache_keys = [CanvasAiPageBuilderHelper::CACHE_KEY_ALL_COMPONENTS_BY_SOURCE];
    $component_entity_type = $this->container->get('entity_type.manager')->getDefinition(Component::ENTITY_TYPE_ID);
    $initial_cacheability = new CacheableMetadata();
    $initial_cacheability->setCacheContexts($component_entity_type->getListCacheContexts());
    $initial_cacheability->setCacheTags($component_entity_type->getListCacheTags());
    $initial_cacheability->addCacheContexts(['user.permissions']);

    // Ensure both caches are empty initially.
    $variation_cache->delete($cache_keys, $initial_cacheability);
    $memory_variation_cache->delete($cache_keys, $initial_cacheability);
    $this->assertFalse($variation_cache->get($cache_keys, $initial_cacheability), 'Persistent cache is empty before first call');
    $this->assertFalse($memory_variation_cache->get($cache_keys, $initial_cacheability), 'Memory cache is empty before first call');

    // First call - should fetch fresh data and populate both caches.
    $this->canvasAiPageBuilderHelper->getAllComponentsKeyedBySource();

    $cached_persistent = $variation_cache->get($cache_keys, $initial_cacheability);
    $cached_memory = $memory_variation_cache->get($cache_keys, $initial_cacheability);
    $this->assertIsObject($cached_persistent, 'Persistent cache populated after first call');
    $this->assertNotEmpty($cached_persistent->data, 'Persistent cache data is not empty');
    $this->assertIsObject($cached_memory, 'Memory cache populated after first call');
    $this->assertSame($cached_persistent->data, $cached_memory->data, 'Memory cache matches the persistent cache after first call');

    // Update a component to force cache invalidation. Tag invalidation must
    // clear both bins, including the memory bin within the same request.
    $page_title_component = Component::load('block.page_title_block');
    $this->assertNotNull($page_title_component, 'Page title block component exists');
    $page_title_component->set('label', $page_title_component->label() . ' (updated)')->save();
    $this->assertFalse($variation_cache->get($cache_keys, $initial_cacheability), 'Persistent cache is invalidated after component update');
    $this->assertFalse($memory_variation_cache->get($cache_keys, $initial_cacheability), 'Memory cache is invalidated after component update');
  }

  /**
   * Tests the processCanvasPageFields method.
   */
  public function testProcessCanvasPageFields(): void {
    $response = [
      'created_content' => 'New Title',
      'other_key' => 'stay',
      'metadata' => [
        'metatag_description' => 'New Description',
      ],
    ];

    $expected = [
      'other_key' => 'stay',
      'canvas_page_data' => [
        'title[0][value]' => 'New Title',
        'description[0][value]' => 'New Description',
      ],
    ];

    $result = $this->canvasAiPageBuilderHelper->processCanvasPageFields($response);
    $this->assertEquals($expected, $result);

    // Test with refined_text (edit content).
    $response = [
      'refined_text' => 'Edited Title',
    ];
    $expected = [
      'canvas_page_data' => [
        'title[0][value]' => 'Edited Title',
      ],
    ];
    $result = $this->canvasAiPageBuilderHelper->processCanvasPageFields($response);
    $this->assertEquals($expected, $result);

    // When the AI returns both title keys, created_content wins and
    // refined_text is dropped.
    $response = [
      'created_content' => 'Created Title',
      'refined_text' => 'Refined Title',
    ];
    $expected = [
      'canvas_page_data' => [
        'title[0][value]' => 'Created Title',
      ],
    ];
    $result = $this->canvasAiPageBuilderHelper->processCanvasPageFields($response);
    $this->assertEquals($expected, $result);

    // A sibling key under metadata is preserved; only metatag_description is
    // consumed.
    $response = [
      'metadata' => [
        'metatag_description' => 'New Description',
        'other_metadata' => 'keep',
      ],
    ];
    $expected = [
      'metadata' => [
        'other_metadata' => 'keep',
      ],
      'canvas_page_data' => [
        'description[0][value]' => 'New Description',
      ],
    ];
    $result = $this->canvasAiPageBuilderHelper->processCanvasPageFields($response);
    $this->assertEquals($expected, $result);

    // When metatag_description is empty, metadata is left untouched and no
    // canvas_page_data is produced.
    $response = [
      'metadata' => [
        'metatag_description' => '',
        'other_metadata' => 'keep',
      ],
    ];
    $result = $this->canvasAiPageBuilderHelper->processCanvasPageFields($response);
    $this->assertEquals($response, $result);
  }

  /**
   * Tests that block components expose prop metadata in context.
   */
  public function testBlockComponentPropsInContext(): void {
    // Create an admin user.
    $privileged_user = $this->createUser([], '', TRUE);
    \assert($privileged_user !== FALSE);
    $this->container->get('current_user')->setAccount($privileged_user);
    $this->container->get(BlockManagerInterface::class)->getDefinitions();
    $this->container->get(ComponentSourceManager::class)
      ->generateComponents(BlockComponent::SOURCE_PLUGIN_ID, ['system_branding_block']);

    $components = $this->canvasAiPageBuilderHelper->getAllComponentsKeyedBySource();
    $this->assertArrayHasKey(BlockComponent::SOURCE_PLUGIN_ID, $components);
    $component_id = BlockComponent::SOURCE_PLUGIN_ID . '.system_branding_block';
    $block_component = $components[BlockComponent::SOURCE_PLUGIN_ID]['components'][$component_id] ?? NULL;
    $this->assertIsArray($block_component);
    $props = $block_component['props'] ?? NULL;
    $this->assertIsArray($props);
    $this->assertArrayHasKey('label', $props);
    $this->assertArrayHasKey('label_display', $props);
    $this->assertArrayHasKey('use_site_logo', $props);
    $this->assertArrayHasKey('use_site_slogan', $props);
    $this->assertTrue($props['label_display']['required']);
  }

  /**
   * Tests that block storage data matches the access-checked listing.
   *
   * The 0007 post_update hook runs via `drush updb` as the anonymous user, for
   * which getAllComponentsKeyedBySource() returns nothing.
   * getEnabledBlockComponentsFromStorage() must reproduce the block data an
   * admin would see there.
   *
   * @see \Drupal\canvas_ai\CanvasAiPageBuilderHelper::getEnabledBlockComponentsFromStorage()
   */
  public function testGetEnabledBlockComponentsFromStorageMatchesListing(): void {
    $this->container->get(BlockManagerInterface::class)->getDefinitions();
    $this->container->get(ComponentSourceManager::class)
      ->generateComponents(BlockComponent::SOURCE_PLUGIN_ID, ['system_branding_block']);

    // As an admin, capture the block components from the access-checked listing.
    $admin = $this->createUser([], '', TRUE);
    \assert($admin !== FALSE);
    $this->container->get('current_user')->setAccount($admin);
    $listed = $this->canvasAiPageBuilderHelper->getAllComponentsKeyedBySource();
    $this->assertArrayHasKey(BlockComponent::SOURCE_PLUGIN_ID, $listed);
    $listed_blocks = $listed[BlockComponent::SOURCE_PLUGIN_ID]['components'];
    // Ensure that block data exists.
    $this->assertNotEmpty($listed_blocks);

    // As the anonymous user, the storage path must return the same blocks.
    $this->container->get('current_user')->setAccount(new AnonymousUserSession());
    $stored_blocks = $this->canvasAiPageBuilderHelper->getEnabledBlockComponentsFromStorage();

    $this->assertEquals($listed_blocks, $stored_blocks);
  }

  /**
   * Tests dynamic_prompt_parts.yml exists with non-empty prompts at every key.
   */
  public function testComponentAgentDynamicPromptParts(): void {
    $reflection = new \ReflectionClass(CanvasAiPageBuilderHelper::class);
    $class_file = $reflection->getFileName();
    $this->assertIsString($class_file);
    $file = dirname($class_file) . '/DynamicPrompts/canvas_component_agent/dynamic_prompt_parts.yml';
    $this->assertFileExists($file);

    $prompt_parts = Yaml::parseFile($file);

    // Single-string section.
    $this->assertArrayHasKey('selected_component_required_props', $prompt_parts);
    $this->assertIsString($prompt_parts['selected_component_required_props']);
    $this->assertNotEmpty(trim($prompt_parts['selected_component_required_props']));

    // Grouped sections keyed by state value.
    $expected_sections = [
      'selected_component' => ['empty', 'present'],
      'json_api_module_status' => ['disabled', 'enabled'],
      'menu_fetch_source' => [
        'linkset_not_configured',
        'menu_fetching_functionality_not_available',
        'jsonapi_menu_items',
        'linkset',
      ],
    ];
    foreach ($expected_sections as $section => $keys) {
      $this->assertArrayHasKey($section, $prompt_parts);
      foreach ($keys as $key) {
        $this->assertArrayHasKey($key, $prompt_parts[$section], "Missing '$section.$key' prompt.");
        $this->assertIsString($prompt_parts[$section][$key], "'$section.$key' prompt is not a string.");
        $this->assertNotEmpty(trim($prompt_parts[$section][$key]), "'$section.$key' prompt is empty.");
      }
    }
  }

}
