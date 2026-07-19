<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\Controller;

// cspell:ignore entiteit Nederlands

use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\ContentTemplate;
use Drupal\canvas\Entity\Page;
use Drupal\canvas\Entity\PageRegion;
use Drupal\canvas\Entity\Pattern;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Entity\Entity\EntityViewMode;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Render\HtmlResponse;
use Drupal\Core\Url;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\language\ConfigurableLanguageManagerInterface;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\canvas\Kernel\Traits\PageTrait;
use Drupal\Tests\canvas\Kernel\Traits\RequestTrait;
use Drupal\Tests\canvas\Traits\DataProviderWithComponentTreeTrait;
use Drupal\Tests\canvas\Traits\GenerateComponentConfigTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests the Component Audit Controller UI.
 */
#[RunTestsInSeparateProcesses]
#[Group('canvas')]
final class ComponentAuditControllerTest extends CanvasKernelTestBase {

  use DataProviderWithComponentTreeTrait;
  use PageTrait;
  use RequestTrait;
  use UserCreationTrait;
  use GenerateComponentConfigTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'node',
    ...self::PAGE_TEST_MODULES,
  ];

  protected function setUp(): void {
    parent::setUp();
    $this->generateComponentConfig();

    $this->installEntitySchema('node');
    $this->installSchema('node', ['node_access']);
    $this->installEntitySchema('path_alias');
    $this->installEntitySchema('field_storage_config');
    $this->installEntitySchema('field_config');
    $this->installEntitySchema(Page::ENTITY_TYPE_ID);

    NodeType::create([
      'type' => 'article',
      'name' => 'Article',
    ])->save();

    FieldStorageConfig::create([
      'entity_type' => 'node',
      'field_name' => 'field_canvas_test',
      'type' => 'component_tree',
      'cardinality' => FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED,
    ])->save();

    FieldConfig::create([
      'entity_type' => 'node',
      'bundle' => 'article',
      'field_name' => 'field_canvas_test',
      'label' => 'Canvas Test Field',
      'required' => TRUE,
    ])->setDefaultValue([
      [
        'uuid' => 'bd4ae317-3f4d-4b82-a3ca-452d916ae715',
        'component_id' => 'sdc.canvas_test_sdc.druplicon',
        'component_version' => '8fe3be948e0194e1',
        'inputs' => [],
      ],
    ])->save();
  }

  /**
   * Tests controller output when config and content entity translations exist.
   */
  public function testControllerWithTranslations(): void {
    $this->enableModules(['language', 'locale', 'config_translation', 'content_translation']);
    $this->installSchema('locale', ['locales_location', 'locales_source', 'locales_target']);
    $this->installConfig(['language']);
    ConfigurableLanguage::createFromLangcode('nl')->save();

    $this->setUpCurrentUser(permissions: [
      'administer themes',
      Component::ADMIN_PERMISSION,
      Page::CREATE_PERMISSION,
      Page::EDIT_PERMISSION,
    ]);

    $uuid = $this->container->get('uuid');
    $heading_component_instance_uuid = $uuid->generate();
    $component_tree = self::populateActiveComponentVersionPlaceholders([
      [
        'uuid' => $heading_component_instance_uuid,
        'component_id' => 'sdc.canvas_test_sdc.heading',
        'component_version' => '::ACTIVE_VERSION_IN_SUT::',
        'inputs' => [
          'text' => 'Hello in English!',
          'element' => 'h1',
        ],
      ],
    ]);

    // Create one config entity per type, each containing the `heading` SDC.
    $page_region = PageRegion::create([
      'theme' => 'stark',
      'region' => 'sidebar_first',
      'component_tree' => $component_tree,
    ]);
    $page_region->save();

    $pattern = Pattern::create([
      'id' => 'test_pattern',
      'label' => 'Test Pattern',
      'component_tree' => $component_tree,
    ]);
    $pattern->save();

    EntityViewMode::create([
      'id' => 'node.teaser',
      'label' => 'Teaser',
      'targetEntityType' => 'node',
    ])->save();
    $content_template = ContentTemplate::create([
      'id' => 'node.article.teaser',
      'content_entity_type_id' => 'node',
      'content_entity_type_bundle' => 'article',
      'content_entity_type_view_mode' => 'teaser',
      'component_tree' => $component_tree,
    ]);
    $content_template->save();

    // Write an nl override to each Canvas config entity: translate the `text`
    // prop of their `sdc.canvas_test_sdc.heading` component instance.
    $language_manager = $this->container->get(LanguageManagerInterface::class);
    \assert($language_manager instanceof ConfigurableLanguageManagerInterface);
    foreach ([$page_region, $pattern, $content_template] as $config_entity) {
      $nl_form_values = $config_entity->toArray();
      NestedArray::setValue($nl_form_values, ['component_tree', $heading_component_instance_uuid, 'inputs', 'text'], [0 => ['value' => 'Hallo in het Nederlands!']]);
      // Note: $nl_form_values is the complete raw data of the config entity,
      // with only a single (deeply nested!) key-value pair changed
      // (translated). The config translation system ensures only the actually
      // translated values are saved.
      $this->saveConfigEntityTranslation($config_entity, 'nl', $nl_form_values);
      self::assertSame(
        [
          'component_tree' => [
            $heading_component_instance_uuid => [
              'inputs' => [
                'text' => 'Hallo in het Nederlands!',
              ],
            ],
          ],
        ],
        $language_manager->getLanguageConfigOverride('nl', $config_entity->getConfigDependencyName())->getRawData()
      );
    }

    // Create a content entity (node) with an nl translation.
    $entity_type_manager = $this->container->get('entity_type.manager');
    $node_storage = $entity_type_manager->getStorage('node');
    $node = $node_storage->create([
      'title' => 'Test entity',
      'status' => TRUE,
      'type' => 'article',
      'field_canvas_test' => $component_tree,
    ]);
    $node->save();
    $node->addTranslation('nl', [
      'title' => 'Test entiteit',
      'status' => TRUE,
      'field_canvas_test' => $node->get('field_canvas_test')->getValue(),
    ])->save();

    $audit_url = Url::fromRoute('entity.component.audit', ['component' => 'sdc.canvas_test_sdc.heading'])->toString();
    $response = $this->request(Request::create($audit_url));
    \assert($response instanceof HtmlResponse);
    // Config entity tables with only a title column: language is column 2.
    foreach (['table-page-region', 'table-pattern'] as $table) {
      $this->assertTableCellContains($table, 1, 2, 'Default (en)');
      $this->assertTableCellContains($table, 2, 2, 'Translation (nl)');
    }
    // Content template table: title, entity type, bundle, mode, language.
    $this->assertTableCellContains('table-content-template', 1, 5, 'Default (en)');
    $this->assertTableCellContains('table-content-template', 2, 5, 'Translation (nl)');

    // The content table must show a Default row and a Translation row.
    // Columns: title, entity type, bundle, id, revision id, in latest,
    // in default, language.
    $this->assertTableCellContains('table-content', 1, 8, 'Default (en)');
    $this->assertTableCellContains('table-content', 2, 8, 'Translation (nl)');
  }

  /**
   * Tests controller output when adding or editing an entity.
   */
  public function testController(): void {
    $this->setUpCurrentUser(permissions: [
      'administer themes',
      Component::ADMIN_PERMISSION,
      Page::CREATE_PERMISSION,
      Page::EDIT_PERMISSION,
    ]);

    $entity_data = $this->entityData();

    $entity_type_manager = $this->container->get('entity_type.manager');
    $storages = [];

    foreach ($entity_data as $entity_type_id => $bundle_data) {
      $storages[$entity_type_id] = $entity_type_manager->getStorage($entity_type_id);
      foreach ($bundle_data as $entities_data) {
        foreach ($entities_data as $values) {
          $entity = $storages[$entity_type_id]->create($values);
          $entity->save();
        }
      }
    }
    $page1 = $storages[Page::ENTITY_TYPE_ID]->load(1);
    \assert($page1 instanceof Page);
    $page1->get('components')->setValue([
      [
        'uuid' => 'component-sdc',
        'component_id' => 'sdc.canvas_test_sdc.druplicon',
        'inputs' => [],
      ],
    ]);
    $page1->setUnpublished()
      ->setNewRevision(TRUE);
    $page1->save();

    $node1 = $storages['node']->load(1);
    \assert($node1 instanceof NodeInterface);
    $node1->get('field_canvas_test')->setValue([
      [
        'uuid' => 'component-sdc',
        'component_id' => 'sdc.canvas_test_sdc.druplicon',
        'inputs' => [],
      ],
    ]);
    $node1->setNewRevision(TRUE);
    $node1->save();

    $audit_url = Url::fromRoute('entity.component.audit', ['component' => 'sdc.canvas_test_sdc.props-slots'])->toString();
    $response = $this->request(Request::create($audit_url));
    \assert($response instanceof HtmlResponse);
    $expected_cache_contexts = [
      'theme',
      'languages:language_interface',
      'user.permissions',
      'url.query_args:_wrapper_format',
      // @see \Drupal\canvas\Hook\ComponentSourceHooks::pageAttachments()
      'route.name',
    ];
    self::assertEqualsCanonicalizing($expected_cache_contexts, $response->getCacheableMetadata()->getCacheContexts());
    self::assertEqualsCanonicalizing([
      'rendered',
      'http_response',
      // Cache tag bubbled by the conditional attaching of the asset library for
      // tracking which page to navigate to when exiting the Canvas UI.
      // @see \Drupal\canvas\Hook\ModuleHooks::pageAttachments()
      // @see \Drupal\canvas\Access\CanvasUiAccessCheck
      'test_create_access_cache_tag',
      // @see \Drupal\block\Plugin\DisplayVariant\BlockPageVariant
      'config:block_list',
    ], $response->getCacheableMetadata()->getCacheTags());

    $this->assertTitle('Audit of Canvas test SDC with props and slots usages | ');

    $this->assertTableCellContains('table-content', 1, 1, 'Test page');
    $this->assertTableCellContains('table-content', 1, 2, 'Page');
    $this->assertTableCellContains('table-content', 1, 3, 'Page');
    $this->assertTableCellContains('table-content', 1, 4, '1');
    $this->assertTableCellContains('table-content', 1, 5, '1');
    $this->assertTableCellContains('table-content', 1, 6, '❌');
    $this->assertTableCellContains('table-content', 1, 7, '❌');

    $this->assertTableCellContains('table-content', 2, 1, 'Another test page');
    $this->assertTableCellContains('table-content', 2, 2, 'Page');
    $this->assertTableCellContains('table-content', 2, 3, 'Page');
    $this->assertTableCellContains('table-content', 2, 4, '2');
    $this->assertTableCellContains('table-content', 2, 5, '2');
    $this->assertTableCellContains('table-content', 2, 6, '✔');
    $this->assertTableCellContains('table-content', 2, 7, '✔');

    $this->assertTableCellContains('table-content', 3, 1, 'Test entity');
    $this->assertTableCellContains('table-content', 3, 2, 'Content');
    $this->assertTableCellContains('table-content', 3, 3, 'Article');
    $this->assertTableCellContains('table-content', 3, 4, '1');
    $this->assertTableCellContains('table-content', 3, 5, '1');
    $this->assertTableCellContains('table-content', 3, 6, '❌');
    $this->assertTableCellContains('table-content', 3, 7, '❌');

    $audit_url = Url::fromRoute('entity.component.audit', ['component' => 'sdc.canvas_test_sdc.druplicon'])->toString();
    $response = $this->request(Request::create($audit_url));
    \assert($response instanceof HtmlResponse);
    self::assertEqualsCanonicalizing($expected_cache_contexts, $response->getCacheableMetadata()->getCacheContexts());
    self::assertEqualsCanonicalizing([
      'rendered',
      'http_response',
      // Cache tag bubbled by the conditional attaching of the asset library for
      // tracking which page to navigate to when exiting the Canvas UI.
      // @see \Drupal\canvas\Hook\ModuleHooks::pageAttachments()
      // @see \Drupal\canvas\Access\CanvasUiAccessCheck
      'test_create_access_cache_tag',
      // @see \Drupal\block\Plugin\DisplayVariant\BlockPageVariant
      'config:block_list',
    ], $response->getCacheableMetadata()->getCacheTags());

    $this->assertTitle('Audit of Druplicon usages | ');

    $this->assertTableCellContains('table-content', 1, 1, 'Test page');
    $this->assertTableCellContains('table-content', 1, 2, 'Page');
    $this->assertTableCellContains('table-content', 1, 3, 'Page');
    $this->assertTableCellContains('table-content', 1, 4, '1');
    $this->assertTableCellContains('table-content', 1, 5, '3');
    $this->assertTableCellContains('table-content', 1, 6, '✔');
    $this->assertTableCellContains('table-content', 1, 7, '✔');

    $this->assertTableCellContains('table-content', 2, 1, 'Test entity');
    $this->assertTableCellContains('table-content', 2, 2, 'Content');
    $this->assertTableCellContains('table-content', 2, 3, 'Article');
    $this->assertTableCellContains('table-content', 2, 4, '1');
    $this->assertTableCellContains('table-content', 2, 5, '2');
    $this->assertTableCellContains('table-content', 2, 6, '✔');
    $this->assertTableCellContains('table-content', 2, 7, '✔');
  }

  private function assertTableCellContains(string $table_name, int $row_index, int $column_index, string $needle): void {
    $xpath_element = $this->xpath("//table[@name=\"$table_name\"]//tr[$row_index]//td[$column_index]");
    \assert(\is_array($xpath_element) && \array_key_exists(0, $xpath_element));
    $this->assertStringContainsString($needle, trim((string) $xpath_element[0]->asXML()));
  }

  private static function entityData(): array {
    return [
      Page::ENTITY_TYPE_ID => [
        Page::ENTITY_TYPE_ID => [
          [
            'title' => 'Test page',
            'description' => 'This is a test page.',
            'status' => TRUE,
            'components' => [
              [
                'uuid' => 'component-sdc',
                'component_id' => 'sdc.canvas_test_sdc.props-slots',
                'inputs' => [
                  'heading' => [
                    'sourceType' => 'static:field_item:string',
                    'value' => 'This is my header',
                    'expression' => 'ℹ︎string␟value',
                  ],
                ],
              ],
            ],
          ],
          [
            'title' => 'Another test page',
            'description' => 'This is another test page.',
            'status' => TRUE,
            'components' => [
              [
                'uuid' => 'component-sdc',
                'component_id' => 'sdc.canvas_test_sdc.props-slots',
                'inputs' => [
                  'heading' => [
                    'sourceType' => 'static:field_item:string',
                    'value' => 'This is my header',
                    'expression' => 'ℹ︎string␟value',
                  ],
                ],
              ],
            ],
          ],
        ],
      ],
      'node' => [
        'article' => [
          [
            'title' => 'Test entity',
            'status' => TRUE,
            'type' => 'article',
            'field_canvas_test' => [
              [
                'uuid' => 'component-sdc',
                'component_id' => 'sdc.canvas_test_sdc.props-slots',
                'inputs' => [
                  'heading' => [
                    'sourceType' => 'static:field_item:string',
                    'value' => 'This is my header',
                    'expression' => 'ℹ︎string␟value',
                  ],
                ],
              ],
            ],
          ],
        ],
      ],
    ];
  }

}
