<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel;

use ColinODell\PsrTestLogger\TestLogger;
use Drupal\canvas\Controller\ApiContentTemplateSlotFieldController;
use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\ContentTemplate;
use Drupal\canvas\Entity\Page;
use Drupal\canvas\EntityHandlers\ContentTemplateAwareViewBuilder;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\canvas\PropSource\PropSource;
use Drupal\canvas\Storage\ComponentTreeLoader;
use Drupal\Core\Cache\Cache;
use Drupal\Core\DependencyInjection\ClassResolverInterface;
use Drupal\Core\Entity\Entity\EntityViewMode;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\RfcLogLevel;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\file\Entity\File;
use Drupal\filter\Entity\FilterFormat;
use Drupal\node\NodeInterface;
use Drupal\Tests\canvas\Traits\CanvasFieldCreationTrait;
use Drupal\Tests\canvas\Traits\CrawlerTrait;
use Drupal\Tests\canvas\Traits\GenerateComponentConfigTrait;
use Drupal\Tests\canvas\Traits\SingleDirectoryComponentTreeTestTrait;
use Drupal\Tests\image\Kernel\ImageFieldCreationTrait;
use Drupal\Tests\node\Traits\ContentTypeCreationTrait;
use Drupal\Tests\node\Traits\NodeCreationTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Tests Node Templates.
 *
 * @legacy-covers \Drupal\canvas\EntityHandlers\ContentTemplateAwareViewBuilder
 */
#[RunTestsInSeparateProcesses]
#[Group('canvas')]
final class NodeTemplatesTest extends CanvasKernelTestBase {

  use SingleDirectoryComponentTreeTestTrait;
  use GenerateComponentConfigTrait;
  use ContentTypeCreationTrait;
  use CanvasFieldCreationTrait;
  use NodeCreationTrait;
  use CrawlerTrait;
  use ImageFieldCreationTrait;
  use UserCreationTrait;

  /**
   * @see core.services.yml
   */
  private const REQUIRED_CACHE_CONTEXTS = [
    'languages:language_interface',
    'theme',
    'user.permissions',
  ];

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'node',
    'field',
    'canvas_test_rendering',
  ];

  /**
   * Callbacks that save an entity in "parallel", keyed by entity type ID.
   *
   * Each runs once, when the next entity of that type is created.
   *
   * @var array<string, callable(): void>
   *
   * @see self::simulateParallelSave()
   */
  private array $parallelSaves = [];

  /**
   * Implements hook_entity_create().
   *
   * Simulates a parallel request that saves between the existence check and
   * the save of the entity being created.
   */
  #[Hook('entity_create')]
  public function simulateParallelSave(EntityInterface $entity): void {
    $callback = $this->parallelSaves[$entity->getEntityTypeId()] ?? NULL;
    if ($callback !== NULL) {
      unset($this->parallelSaves[$entity->getEntityTypeId()]);
      $callback();
    }
  }

  /**
   * Whether the next field config save fails.
   *
   * @see self::failFieldConfigSave()
   */
  private bool $failNextFieldConfigSave = FALSE;

  /**
   * Implements hook_ENTITY_TYPE_presave() for field_config.
   */
  #[Hook('field_config_presave')]
  public function failFieldConfigSave(): void {
    if ($this->failNextFieldConfigSave) {
      $this->failNextFieldConfigSave = FALSE;
      throw new \RuntimeException('Simulated field config save failure.');
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('media');
    $this->installEntitySchema('node');
    $this->installSchema('node', ['node_access']);
    $this->installEntitySchema('path_alias');
    $this->installConfig(['node', 'filter']);
    $this->installConfig(['canvas']);
    $this->createContentType(['type' => 'article']);
    $this->generateComponentConfig();
    FilterFormat::create([
      'format' => 'basic_html',
      'name' => 'Basic HTML',
      'filters' => [
        'filter_html' => [
          'module' => 'filter',
          'status' => TRUE,
          'weight' => 10,
          'settings' => [
            'allowed_html' => '<p>',
          ],
        ],
      ],
    ])->save();
    $this->setUpCurrentUser(permissions: ['access content']);
  }

  #[TestWith([
    TRUE,
    'full',
    TRUE,
    [
      // Components in the component tree.
      'config:canvas.component.sdc.canvas_test_sdc.my-hero',
      'config:canvas.component.sdc.canvas_test_sdc.props-no-slots',
      // Cacheability of resolved props.
      'node:1',
      'config:filter.format.basic_html',
    ],
  ])]
  #[TestWith([
    TRUE,
    'card',
    TRUE,
    [
      // Components in the component tree.
      'config:canvas.component.sdc.canvas_test_sdc.my-hero',
      'config:canvas.component.sdc.canvas_test_sdc.props-no-slots',
      // Cacheability of resolved props.
      'node:1',
      'config:filter.format.basic_html',
    ],
  ])]
  #[TestWith([
    FALSE,
    'full',
    FALSE,
    [
      // Components in the component tree — minus the ones whose props failed to
      // resolve because they were inaccessible: EntityFieldPropSources populated by
      // the host entity.
      'config:canvas.component.sdc.canvas_test_sdc.my-hero',
      // @todo Stop expecting this cache tag in https://www.drupal.org/i/3559820
      'config:canvas.component.sdc.canvas_test_sdc.props-no-slots',
      // @see \Drupal\node\NodeAccessControlHandler::checkViewAccess()
      'node:1',
    ],
  ])]
  public function testOptContentTypeIntoCanvas(bool $node_is_published, string $view_mode, bool $expected_entity_data_is_accessible, array $expected_node_component_tree_cache_tags): void {
    // Create an alternate view mode, so we can test that content templates work
    // for more than just the full view mode.
    EntityViewMode::create([
      'id' => 'node.card',
      'label' => 'Card',
      'targetEntityType' => 'node',
    ])->save();

    ContentTemplate::create([
      'id' => "node.article.$view_mode",
      'content_entity_type_id' => 'node',
      'content_entity_type_bundle' => 'article',
      'content_entity_type_view_mode' => $view_mode,
      'component_tree' => [
        // A static marker so we can easily tell if we're rendering with Canvas,
        // but simultaneously tests all currently supported dynamic ways of
        // populating props.
        [
          'uuid' => 'e1f6fbca-e331-4506-9dba-5734194c1e59',
          'component_id' => 'sdc.canvas_test_sdc.my-hero',
          'component_version' => 'a681ae184a8f6b7f',
          'inputs' => [
            // Tests static prop source end-to-end.
            // @see \Drupal\canvas\PropSource\StaticPropSource
            'heading' => 'Canvas is large and in charge!',
            // Tests adapted entity field prop source end-to-end.
            // @see \Drupal\canvas\PropSource\EntityFieldPropSource::__construct(adapter)
            'subheading' => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:article␝created␞␟value',
              'adapter' => 'unix_to_date',
            ],
            // Tests entity field prop source end-to-end.
            // @see \Drupal\canvas\PropSource\EntityFieldPropSource
            'cta1' => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:article␝title␞␟value',
            ],
            // Tests host entity URL prop source end-to-end.
            // @see \Drupal\canvas\PropSource\HostEntityUrlPropSource
            'cta1href' => [
              'sourceType' => PropSource::HostEntityUrl->value,
            ],
          ],
        ],
        // The node body, which needs to be using a entity field prop source
        // because all content templates require at least one entity field prop
        // source.
        [
          'uuid' => '6cf8297a-fc60-4019-be81-c336fd828c39',
          'component_id' => 'sdc.canvas_test_sdc.props-no-slots',
          'inputs' => [
            'heading' => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:article␝body␞␟processed',
            ],
          ],
        ],
      ],
    ])->save();
    $body = <<<HTML
<p>Hey this is allowed</p>
<script>alert('hi mum')</script>
HTML;

    $node = $this->createNode([
      'type' => 'article',
      'title' => 'This is a node whose structured data is rendered using a Canvas content template!',
      'created' => 1764872657,
      'body' => [
        'value' => $body,
        'format' => 'basic_html',
      ],
      'status' => $node_is_published,
      'uid' => 1,
    ]);
    self::assertSame($node_is_published, $node->isPublished());
    self::assertFalse($node->isNew());
    $viewBuilder = $this->container->get(EntityTypeManagerInterface::class)->getViewBuilder('node');
    self::assertInstanceOf(ContentTemplateAwareViewBuilder::class, $viewBuilder);
    $build = $viewBuilder->view($node, $view_mode);
    $crawler = $this->crawlerForRenderArray($build);
    // The content type has not been opted into Canvas, so it should not be using
    // Canvas for rendering.
    self::assertCount(0, $crawler->filter('h1.my-hero__heading:contains("Canvas is large and in charge!")'));
    self::assertCount(0, $crawler->filter('div.my-hero__container > p.my-hero__subheading:contains("2025-12-04")'));
    self::assertCount(0, $crawler->filter(\sprintf('div.my-hero__container > div.my-hero__actions > a[href="%s/node/1"]:contains("%s")', $GLOBALS['base_url'], $node->getTitle())));
    self::assertCount(1, $crawler->filter('p:contains("Hey this is allowed")'));
    self::assertCount(0, $crawler->filter('script'));
    self::assertEqualsCanonicalizing([
      'config:filter.format.basic_html',
      'user:1',
      'user_view',
      'node:1',
      'node_view',
      'config:content_template_list',
      // TRICKY: this cache tag is present because the config entity does exist,
      // but is disabled. It was assessed whether it should be used, hence its
      // cache tag is present.
      "config:canvas.content_template.node.article.$view_mode",
      // The auto-save cache tag is NOT present because we're not on a preview
      // route. It's only added on preview routes to avoid invalidating all
      // rendered nodes on the live site when auto-saves change.
    ], $build['#cache']['tags']);
    self::assertEqualsCanonicalizing([
      ...self::REQUIRED_CACHE_CONTEXTS,
      'timezone',
      'route.name.is_canvas_editor_ui',
    ], $build['#cache']['contexts']);
    self::assertSame(Cache::PERMANENT, $build['#cache']['max-age']);
    self::assertSame([
      'entity_view',
      'node',
      (string) $node->id(),
      $view_mode,
      'without-canvas',
    ], $build['#cache']['keys']);

    // Confirm although we've opted in the status of the template is false so
    // will not be used.
    $template = ContentTemplate::load("node.article.$view_mode");

    // ContentTemplate component trees with prop sources that need a host
    // entity cannot resolve inputs without one: inputs_resolved returns NULL.
    \assert($template instanceof ContentTemplate);
    $no_hosted_entity_component_tree = $template->getComponentTree()->get(0);
    \assert($no_hosted_entity_component_tree instanceof ComponentTreeItem);
    self::assertNull($no_hosted_entity_component_tree->get('inputs_resolved')->getValue());
    // With a host entity, inputs_resolved returns the resolved values.
    $hosted_entity_component_tree = $template->getComponentTree($node)->get(0);
    \assert($hosted_entity_component_tree instanceof ComponentTreeItem);
    $resolved = $hosted_entity_component_tree->get('inputs_resolved')->getValue();
    self::assertIsArray($resolved);
    self::assertSame('Canvas is large and in charge!', $resolved['heading']);
    self::assertFalse($template->status());
    self::assertCount(0, $crawler->filter('h1.my-hero__heading:contains("Canvas is large and in charge!")'));
    self::assertCount(0, $crawler->filter('div.my-hero__container > p.my-hero__subheading:contains("2025-12-04")'));
    self::assertCount(0, $crawler->filter(\sprintf('div.my-hero__container > div.my-hero__actions > a[href="%s/node/1"]:contains("%s")', $GLOBALS['base_url'], $node->getTitle())));
    self::assertCount(1, $crawler->filter('p:contains("Hey this is allowed")'));
    self::assertCount(0, $crawler->filter('script'));

    // Updated the status of the template to true.
    $template->setStatus(TRUE)->save();

    // Reload the node now that the field definitions have changed.
    self::assertNotNull($node->id());
    $node = $this->container->get(EntityTypeManagerInterface::class)->getStorage('node')->loadUnchanged((int) $node->id());
    \assert($node instanceof NodeInterface);
    // Set up a logger so we can tell if
    // canvas_test_rendering_entity_display_build_alter() gets invoked.
    $logger = new TestLogger();
    $this->container->get(LoggerChannelFactoryInterface::class)
      ->get('canvas_test')
      ->addLogger($logger);
    $build = $viewBuilder->view($node, $view_mode);
    $crawler = $this->crawlerForRenderArray($build);
    $html = $crawler->html();

    self::assertTrue($template->status());
    self::assertStringContainsString('Canvas is large and in charge!', $html);
    self::assertCount(1, $crawler->filter('h1.my-hero__heading:contains("Canvas is large and in charge!")'));
    self::assertCount($expected_entity_data_is_accessible ? 1 : 0, $crawler->filter('div.my-hero__container > p.my-hero__subheading:contains("2025-12-04")'));
    self::assertCount($expected_entity_data_is_accessible ? 1 : 0, $crawler->filter(\sprintf('div.my-hero__container > div.my-hero__actions > a[href="%s/node/1"]:contains("%s")', $GLOBALS['base_url'], $node->getTitle())));
    self::assertCount($expected_entity_data_is_accessible ? 1 : 0, $crawler->filter('p:contains("Hey this is allowed")'));
    self::assertCount(0, $crawler->filter('script'));
    // Note: AutoSaveManager::CACHE_TAG is NOT present because we're not on a
    // preview route. It's only added on preview routes to avoid invalidating
    // all rendered nodes on the live site when auto-saves change.
    // @see \Drupal\canvas\EntityHandlers\ContentTemplateAwareViewBuilder::getBuildDefaults()
    self::assertEqualsCanonicalizing([
      "config:canvas.content_template.node.article.$view_mode",
      'node_view',
      ...$expected_node_component_tree_cache_tags,
    ], $build['#cache']['tags']);
    self::assertEqualsCanonicalizing([
      ...self::REQUIRED_CACHE_CONTEXTS,
      'url.site',
      'route.name.is_canvas_editor_ui',
    ], $build['#cache']['contexts']);
    self::assertSame(Cache::PERMANENT, $build['#cache']['max-age']);
    self::assertSame([
      'entity_view',
      'node',
      (string) $node->id(),
      $view_mode,
      'with-canvas',
    ], $build['#cache']['keys']);

    // Confirm that hook_entity_display_build_alter() was not invoked.
    // @see canvas_test_rendering_entity_display_build_alter()
    $this->assertFalse($logger->hasRecordThatContains("hook_entity_display_build_alter for node {$node->id()} in full view mode"));

    $output = $viewBuilder->view($node, 'teaser');
    $crawler = $this->crawlerForRenderArray($output);
    // Confirm that the template is NOT used when viewing the node as a teaser,
    // even though the content type is opted into Canvas.
    self::assertCount(0, $crawler->filter(\sprintf('a[href="%s/node/1"]:contains("Canvas is large and in charge!")', $GLOBALS['base_url'])));
    // TRICKY: note that entity access is NOT checked by the EntityViewBuilder,
    // that is up to the caller! The above is specifically testing Canvas
    // ContentTemplates' render arrays. Those are populated by field properties
    // on the host entity, which is why for ContentTemplates, this test can
    // expect access to be denied when needed.
    self::assertCount(1, $crawler->filter('p:contains("Hey this is allowed")'));
    self::assertCount(0, $crawler->filter('script'));
    $this->assertTrue($logger->hasRecordThatContains("hook_entity_display_build_alter for node {$node->id()} in teaser view mode"));
  }

  /**
   * Tests an exposed slot is filled from its backing field on the entity.
   *
   * @legacy-covers \Drupal\canvas\Entity\ContentTemplate::build
   * @legacy-covers \Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItemList::injectSlotContent
   */
  public function testExposedSlotsAreFilledByEntity(): void {
    // The exposed slot is backed by its own component_tree field on the bundle.
    $this->createComponentTreeField('node', 'article', 'canvas_slot_custom');

    ContentTemplate::create([
      'content_entity_type_id' => 'node',
      'content_entity_type_bundle' => 'article',
      'content_entity_type_view_mode' => 'full',
      'component_tree' => [
        // A simple SDC that will show the node's title, and has a slot
        // we can expose.
        [
          'uuid' => '2842cc6f-9e2b-42a5-8400-e7d6363e08bf',
          'component_id' => 'sdc.canvas_test_sdc.props-slots',
          'component_version' => '0e79e884426a53ae',
          'inputs' => [
            'heading' => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:article␝title␞␟value',
            ],
          ],
        ],
      ],
      // Keyed by the backing field machine name.
      'exposed_slots' => [
        'canvas_slot_custom' => [
          'component_uuid' => '2842cc6f-9e2b-42a5-8400-e7d6363e08bf',
          'slot_name' => 'the_body',
          'label' => 'Custom content area',
        ],
      ],
    ])->setStatus(TRUE)->save();

    // Create an article whose slot field holds an ordinary tree; the merge
    // nests it under the template's real (component, slot).
    $node = $this->createNode([
      'type' => 'article',
      'title' => 'The Real Deal',
      'canvas_slot_custom' => [
        [
          'uuid' => '6ea0de84-858a-4f00-9ef5-de02525c8865',
          'component_id' => 'sdc.canvas_test_sdc.props-no-slots',
          'inputs' => [
            'heading' => [
              'sourceType' => 'static:field_item:string',
              'value' => "Now we're cooking with gas!",
              'expression' => 'ℹ︎string␟value',
            ],
          ],
        ],
      ],
    ]);
    $viewBuilder = $this->container->get(EntityTypeManagerInterface::class)->getViewBuilder('node');
    self::assertInstanceOf(ContentTemplateAwareViewBuilder::class, $viewBuilder);
    $build = $viewBuilder->view($node);
    $crawler = $this->crawlerForRenderArray($build);
    self::assertCount(1, $crawler->filter('h1:contains("The Real Deal")'));
    self::assertCount(1, $crawler->filter('h1:contains("Now we\'re cooking with gas!")'));
    // Note: AutoSaveManager::CACHE_TAG is NOT present because we're not on a
    // preview route. It's only added on preview routes to avoid invalidating
    // all rendered nodes on the live site when auto-saves change.
    // @see \Drupal\canvas\EntityHandlers\ContentTemplateAwareViewBuilder::getBuildDefaults()
    self::assertEqualsCanonicalizing([
      'config:canvas.content_template.node.article.full',
      'node_view',
      // Components in the component tree.
      'config:canvas.component.sdc.canvas_test_sdc.props-slots',
      'config:canvas.component.sdc.canvas_test_sdc.props-no-slots',
      // Entity field prop sources should propagate the entity's cache tags.
      'node:1',
    ], $build['#cache']['tags']);
    self::assertEqualsCanonicalizing([
      ...self::REQUIRED_CACHE_CONTEXTS,
      'route.name.is_canvas_editor_ui',
    ], $build['#cache']['contexts']);
    self::assertSame(Cache::PERMANENT, $build['#cache']['max-age']);
    self::assertSame([
      'entity_view',
      'node',
      '1',
      'full',
      'with-canvas',
    ], $build['#cache']['keys']);

    // The slot field stores an ordinary tree; the entity is valid.
    $node->save();
    $tree = $node->get('canvas_slot_custom');
    self::assertCount(1, $tree);
    $root = $tree->get(0);
    self::assertInstanceOf(ComponentTreeItem::class, $root);
    self::assertNull($root->getSlot());
    self::assertEntityIsValid($node);
  }

  /**
   * Tests slot usage statistics: how many entities override an exposed slot.
   *
   * @legacy-covers \Drupal\canvas\Controller\ApiContentTemplateSlotFieldController::usage
   */
  public function testSlotUsageStatistics(): void {
    $this->createComponentTreeField('node', 'article', 'canvas_slot_custom');
    // The same field storage is attached to a second bundle (fields can be
    // shared across bundles), so its rows live in the same table. A page entity
    // that fills the slot must not be counted for the article template.
    $this->createContentType(['type' => 'page']);
    FieldConfig::create([
      'field_storage' => FieldStorageConfig::loadByName('node', 'canvas_slot_custom'),
      'bundle' => 'page',
    ])->save();

    ContentTemplate::create([
      'content_entity_type_id' => 'node',
      'content_entity_type_bundle' => 'article',
      'content_entity_type_view_mode' => 'full',
      'component_tree' => [
        [
          'uuid' => '2842cc6f-9e2b-42a5-8400-e7d6363e08bf',
          'component_id' => 'sdc.canvas_test_sdc.props-slots',
          'component_version' => '0e79e884426a53ae',
          'inputs' => [
            'heading' => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:article␝title␞␟value',
            ],
          ],
        ],
      ],
      'exposed_slots' => [
        'canvas_slot_custom' => [
          'component_uuid' => '2842cc6f-9e2b-42a5-8400-e7d6363e08bf',
          'slot_name' => 'the_body',
          'label' => 'Custom content area',
        ],
      ],
    ])->setStatus(TRUE)->save();

    $filled = [
      [
        'uuid' => '6ea0de84-858a-4f00-9ef5-de02525c8865',
        'component_id' => 'sdc.canvas_test_sdc.props-no-slots',
        'inputs' => [
          'heading' => [
            'sourceType' => 'static:field_item:string',
            'value' => 'Filled',
            'expression' => 'ℹ︎string␟value',
          ],
        ],
      ],
    ];
    // Two articles override the slot with content. B holds two root components
    // (a two-row field), so if the count were per-row instead of per-entity it
    // would be inflated.
    $this->createNode(['type' => 'article', 'title' => 'A', 'canvas_slot_custom' => $filled]);
    $this->createNode([
      'type' => 'article',
      'title' => 'B',
      'canvas_slot_custom' => [
        $filled[0],
        [
          'uuid' => '7b1f0e00-0000-4000-8000-000000000002',
          'component_id' => 'sdc.canvas_test_sdc.props-no-slots',
          'inputs' => [
            'heading' => [
              'sourceType' => 'static:field_item:string',
              'value' => 'Second row',
              'expression' => 'ℹ︎string␟value',
            ],
          ],
        ],
      ],
    ]);
    // ...one overrides it empty (the render-nothing marker counts as overridden)...
    $this->createNode([
      'type' => 'article',
      'title' => 'C',
      'canvas_slot_custom' => [
        [
          'uuid' => 'dddddddd-0000-4000-8000-000000000004',
          'component_id' => 'marker.empty_slot',
        ],
      ],
    ]);
    // ...two inherit the default (no slot content)...
    $this->createNode(['type' => 'article', 'title' => 'D']);
    $this->createNode(['type' => 'article', 'title' => 'E']);
    // ...and a page entity fills the shared field: it must not be counted for
    // the article template (proves the count is bundle-specific, not just
    // field-specific).
    $this->createNode(['type' => 'page', 'title' => 'P', 'canvas_slot_custom' => $filled]);

    $template = ContentTemplate::load('node.article.full');
    self::assertInstanceOf(ContentTemplate::class, $template);
    $controller = $this->container->get(ClassResolverInterface::class)
      ->getInstanceFromDefinition(ApiContentTemplateSlotFieldController::class);
    // Three articles override the slot (two filled, one empty marker); the two
    // inheriting articles and the page node (different bundle, same field) are
    // not counted.
    $data = \json_decode((string) $controller->usage($template, 'canvas_slot_custom')->getContent(), TRUE);
    self::assertSame(['overridden' => 3], $data);

    // A field of another type is a 404 too, not a crash: the count query
    // addresses a `uuid` property only `component_tree` field tables have.
    try {
      $controller->usage($template, 'body');
      self::fail('Expected NotFoundHttpException for a non-component_tree field.');
    }
    catch (NotFoundHttpException) {
      // The bundle has no such slot field.
    }

    // An unknown field is a 404.
    $this->expectException(NotFoundHttpException::class);
    $controller->usage($template, 'canvas_slot_missing');
  }

  /**
   * A same-named field of another type cannot back a slot field.
   *
   * Both with only the storage and with the field also on the bundle.
   *
   * @legacy-covers \Drupal\canvas\Controller\ApiContentTemplateSlotFieldController::create
   */
  #[TestWith([FALSE])]
  #[TestWith([TRUE])]
  public function testSlotFieldCreateRejectsForeignStorageType(bool $field_on_bundle): void {
    FieldStorageConfig::create([
      'field_name' => 'canvas_slot_taken',
      'entity_type' => 'node',
      'type' => 'string',
    ])->save();
    if ($field_on_bundle) {
      FieldConfig::create([
        'field_name' => 'canvas_slot_taken',
        'entity_type' => 'node',
        'bundle' => 'article',
      ])->save();
    }
    ContentTemplate::create([
      'content_entity_type_id' => 'node',
      'content_entity_type_bundle' => 'article',
      'content_entity_type_view_mode' => 'full',
      'component_tree' => [
        [
          'uuid' => '2842cc6f-9e2b-42a5-8400-e7d6363e08bf',
          'component_id' => 'sdc.canvas_test_sdc.props-slots',
          'component_version' => '0e79e884426a53ae',
          'inputs' => [
            'heading' => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:article␝title␞␟value',
            ],
          ],
        ],
      ],
    ])->setStatus(TRUE)->save();
    $template = ContentTemplate::load('node.article.full');
    self::assertInstanceOf(ContentTemplate::class, $template);

    $controller = $this->container->get(ClassResolverInterface::class)
      ->getInstanceFromDefinition(ApiContentTemplateSlotFieldController::class);
    $request = Request::create('/', 'POST', content: (string) \json_encode(['fieldName' => 'canvas_slot_taken', 'label' => 'Taken']));
    // A 422, not the 409 "already exists" that the CLI treats as success.
    $this->expectException(UnprocessableEntityHttpException::class);
    $this->expectExceptionMessage('is not a component_tree field');
    $controller->create($request, $template);
  }

  /**
   * Only a `full` view mode template can create a slot field.
   *
   * @legacy-covers \Drupal\canvas\Controller\ApiContentTemplateSlotFieldController::create
   */
  public function testSlotFieldCreateRejectsOtherViewModes(): void {
    $template = ContentTemplate::create([
      'content_entity_type_id' => 'node',
      'content_entity_type_bundle' => 'article',
      'content_entity_type_view_mode' => 'teaser',
      'component_tree' => [],
    ]);
    $controller = $this->container->get(ClassResolverInterface::class)
      ->getInstanceFromDefinition(ApiContentTemplateSlotFieldController::class);
    $request = Request::create('/', 'POST', content: (string) \json_encode(['fieldName' => 'canvas_slot_teaser', 'label' => 'Teaser']));
    try {
      $controller->create($request, $template);
      $this->fail('Expected an UnprocessableEntityHttpException.');
    }
    catch (UnprocessableEntityHttpException $e) {
      self::assertSame('Exposed slots are only allowed in the full view mode.', $e->getMessage());
    }
    // No unused field is left behind.
    self::assertNull(FieldStorageConfig::loadByName('node', 'canvas_slot_teaser'));
  }

  /**
   * Tests creating a slot field: 201, 409, storage reuse, translation, 400.
   *
   * @legacy-covers \Drupal\canvas\Controller\ApiContentTemplateSlotFieldController::create
   */
  public function testSlotFieldCreate(): void {
    $article_template = ContentTemplate::create([
      'content_entity_type_id' => 'node',
      'content_entity_type_bundle' => 'article',
      'content_entity_type_view_mode' => 'full',
    ]);
    // Resolved per call: enabling modules below rebuilds the container.
    $create = fn (ContentTemplate $template, string $field_name, string $label) => $this->container->get(ClassResolverInterface::class)
      ->getInstanceFromDefinition(ApiContentTemplateSlotFieldController::class)
      ->create(
        Request::create('/', 'POST', content: (string) \json_encode(['fieldName' => $field_name, 'label' => $label])),
        $template,
      );

    // 201: a new storage and field, without content_translation settings.
    $response = $create($article_template, 'canvas_slot_sidebar', ' Sidebar ');
    self::assertSame(201, $response->getStatusCode());
    self::assertSame(['fieldName' => 'canvas_slot_sidebar', 'label' => 'Sidebar'], \json_decode((string) $response->getContent(), TRUE));
    $field_storage = FieldStorageConfig::loadByName('node', 'canvas_slot_sidebar');
    self::assertInstanceOf(FieldStorageConfig::class, $field_storage);
    self::assertSame(ComponentTreeItem::PLUGIN_ID, $field_storage->getType());
    self::assertSame(FieldStorageConfig::CARDINALITY_UNLIMITED, $field_storage->getCardinality());
    $field_config = FieldConfig::loadByName('node', 'article', 'canvas_slot_sidebar');
    self::assertInstanceOf(FieldConfig::class, $field_config);
    self::assertSame('Sidebar', $field_config->label());
    self::assertSame([], $field_config->getThirdPartySettings('content_translation'));
    self::assertEntityIsValid($field_config);
    // The new field is in the field map within the same request.
    self::assertArrayHasKey('canvas_slot_sidebar', $this->container->get(EntityFieldManagerInterface::class)->getFieldMapByFieldType(ComponentTreeItem::PLUGIN_ID)['node']);

    // 409 on a repeat request; the existing field is unchanged.
    try {
      $create($article_template, 'canvas_slot_sidebar', 'Other label');
      self::fail('Expected a ConflictHttpException.');
    }
    catch (ConflictHttpException) {
      // The CLI treats a 409 as "already created".
    }
    self::assertSame('Sidebar', FieldConfig::loadByName('node', 'article', 'canvas_slot_sidebar')?->label());

    // 201 on another bundle: the existing storage is reused.
    $this->createContentType(['type' => 'page']);
    $this->enableModules(['language', 'content_translation']);
    $page_template = ContentTemplate::create([
      'content_entity_type_id' => 'node',
      'content_entity_type_bundle' => 'page',
      'content_entity_type_view_mode' => 'full',
    ]);
    self::assertSame(201, $create($page_template, 'canvas_slot_sidebar', 'Page sidebar')->getStatusCode());
    self::assertSame($field_storage->uuid(), FieldStorageConfig::loadByName('node', 'canvas_slot_sidebar')?->uuid());
    // With content_translation, the field is translated symmetrically.
    $page_field = FieldConfig::loadByName('node', 'page', 'canvas_slot_sidebar');
    self::assertInstanceOf(FieldConfig::class, $page_field);
    self::assertSame('Page sidebar', $page_field->label());
    self::assertTrue($page_field->isTranslatable());
    self::assertSame(['inputs' => 'inputs', 'tree' => '0'], $page_field->getThirdPartySetting('content_translation', 'translation_sync'));
    self::assertEntityIsValid($page_field);

    // 400 for invalid machine names.
    $invalid_names = [
      'no prefix' => 'sidebar',
      'uppercase' => 'canvas_slot_Sidebar',
      'trailing underscore' => 'canvas_slot_sidebar_',
      'too long' => 'canvas_slot_' . \str_repeat('a', 21),
    ];
    foreach ($invalid_names as $case => $field_name) {
      try {
        $create($article_template, $field_name, 'Invalid');
        self::fail("Expected a BadRequestHttpException for: $case.");
      }
      catch (BadRequestHttpException $e) {
        self::assertSame(\sprintf('"%s" is not a valid slot field machine name.', $field_name), $e->getMessage());
      }
      self::assertNull(FieldStorageConfig::loadByName('node', $field_name));
    }
  }

  /**
   * Parallel requests creating the same slot field do not cause a 500.
   *
   * @legacy-covers \Drupal\canvas\Controller\ApiContentTemplateSlotFieldController::create
   */
  public function testSlotFieldCreateParallelRequests(): void {
    $this->createContentType(['type' => 'page']);
    $template = ContentTemplate::create([
      'content_entity_type_id' => 'node',
      'content_entity_type_bundle' => 'article',
      'content_entity_type_view_mode' => 'full',
    ]);
    $controller = $this->container->get(ClassResolverInterface::class)
      ->getInstanceFromDefinition(ApiContentTemplateSlotFieldController::class);
    $request = static fn (string $field_name) => Request::create('/', 'POST', content: (string) \json_encode(['fieldName' => $field_name, 'label' => 'Article']));

    // A parallel request for another bundle saves the shared storage first:
    // the storage is reused, 201.
    $this->parallelSaves['field_storage_config'] = function (): void {
      $this->createComponentTreeField('node', 'page', 'canvas_slot_storage_race');
    };
    self::assertSame(201, $controller->create($request('canvas_slot_storage_race'), $template)->getStatusCode());
    self::assertSame([], $this->parallelSaves);
    self::assertInstanceOf(FieldConfig::class, FieldConfig::loadByName('node', 'article', 'canvas_slot_storage_race'));
    self::assertInstanceOf(FieldConfig::class, FieldConfig::loadByName('node', 'page', 'canvas_slot_storage_race'));

    // A parallel request for the same bundle saves the field first: 409, and
    // the parallel request's field is kept.
    $this->parallelSaves['field_config'] = function (): void {
      FieldConfig::create([
        'field_name' => 'canvas_slot_field_race',
        'entity_type' => 'node',
        'bundle' => 'article',
        'label' => 'Parallel',
      ])->save();
    };
    try {
      $controller->create($request('canvas_slot_field_race'), $template);
      self::fail('Expected a ConflictHttpException.');
    }
    catch (ConflictHttpException) {
      // The CLI treats a 409 as "already created".
    }
    self::assertSame([], $this->parallelSaves);
    self::assertSame('Parallel', FieldConfig::loadByName('node', 'article', 'canvas_slot_field_race')?->label());
  }

  /**
   * A failed field save does not leave behind a storage without fields.
   *
   * @legacy-covers \Drupal\canvas\Controller\ApiContentTemplateSlotFieldController::create
   */
  public function testSlotFieldCreateFailureRemovesNewStorage(): void {
    $this->createContentType(['type' => 'page']);
    $this->createComponentTreeField('node', 'page', 'canvas_slot_shared');
    $template = ContentTemplate::create([
      'content_entity_type_id' => 'node',
      'content_entity_type_bundle' => 'article',
      'content_entity_type_view_mode' => 'full',
    ]);
    $controller = $this->container->get(ClassResolverInterface::class)
      ->getInstanceFromDefinition(ApiContentTemplateSlotFieldController::class);
    $request = static fn (string $field_name) => Request::create('/', 'POST', content: (string) \json_encode(['fieldName' => $field_name, 'label' => 'Article']));

    // A storage created in this request is deleted.
    $this->failNextFieldConfigSave = TRUE;
    try {
      $controller->create($request('canvas_slot_new'), $template);
      self::fail('Expected a RuntimeException.');
    }
    catch (\RuntimeException $e) {
      self::assertSame('Simulated field config save failure.', $e->getMessage());
    }
    self::assertNull(FieldStorageConfig::loadByName('node', 'canvas_slot_new'));

    // An existing storage, used by another bundle, is kept.
    $this->failNextFieldConfigSave = TRUE;
    try {
      $controller->create($request('canvas_slot_shared'), $template);
      self::fail('Expected a RuntimeException.');
    }
    catch (\RuntimeException $e) {
      self::assertSame('Simulated field config save failure.', $e->getMessage());
    }
    self::assertInstanceOf(FieldStorageConfig::class, FieldStorageConfig::loadByName('node', 'canvas_slot_shared'));
    self::assertInstanceOf(FieldConfig::class, FieldConfig::loadByName('node', 'page', 'canvas_slot_shared'));
    self::assertNull(FieldConfig::loadByName('node', 'article', 'canvas_slot_shared'));
  }

  /**
   * Tests "use existing slot" candidates include slot fields of other bundles.
   *
   * @legacy-covers \Drupal\canvas\Controller\ApiContentTemplateSlotFieldController::candidates
   */
  public function testSlotFieldCandidatesIncludeOtherBundles(): void {
    // A slot field defined only on 'article'.
    $this->createComponentTreeField('node', 'article', 'canvas_slot_shared');
    // A non-slot component_tree field on 'article' must not be offered elsewhere.
    $this->createComponentTreeField('node', 'article', 'legacy_tree');
    $this->createContentType(['type' => 'page']);
    // A slot field on 'page' with content in one page entity.
    $this->createComponentTreeField('node', 'page', 'canvas_slot_page');
    $this->createNode([
      'type' => 'page',
      'title' => 'A page',
      'canvas_slot_page' => [
        [
          'uuid' => 'aaaaaaaa-0000-4000-8000-000000000001',
          'component_id' => 'sdc.canvas_test_sdc.props-no-slots',
          'inputs' => [
            'heading' => [
              'sourceType' => 'static:field_item:string',
              'value' => 'Page content',
              'expression' => 'ℹ︎string␟value',
            ],
          ],
        ],
      ],
    ]);

    // candidates() reads only the template's target type + bundle (no save
    // needed, avoiding template validation).
    $page_template = ContentTemplate::create([
      'content_entity_type_id' => 'node',
      'content_entity_type_bundle' => 'page',
      'content_entity_type_view_mode' => 'full',
    ]);
    $controller = $this->container->get(ClassResolverInterface::class)
      ->getInstanceFromDefinition(ApiContentTemplateSlotFieldController::class);
    $data = \json_decode((string) $controller->candidates($page_template)->getContent(), TRUE);
    $by_name = \array_column($data['fields'], NULL, 'fieldName');

    // The page's own slot field is on this bundle and has one entity's content.
    self::assertTrue($by_name['canvas_slot_page']['onThisBundle']);
    self::assertSame(1, $by_name['canvas_slot_page']['contentCount']);
    // The article slot field is offered as an attachable (cross-bundle) option;
    // the page bundle has no content in it yet.
    self::assertFalse($by_name['canvas_slot_shared']['onThisBundle']);
    self::assertSame(0, $by_name['canvas_slot_shared']['contentCount']);
    // A non-slot component_tree field on another bundle is not offered.
    self::assertArrayNotHasKey('legacy_tree', $by_name);
    // Content-first: the field with content is listed before the empty one.
    self::assertSame('canvas_slot_page', $data['fields'][0]['fieldName']);
  }

  /**
   * Tests that multiple exposed slots on one template fill independently.
   *
   * @legacy-covers \Drupal\canvas\Entity\ContentTemplate::build
   * @legacy-covers \Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItemList::injectSlotContent
   */
  public function testMultipleExposedSlotsAreIndependentlyFilled(): void {
    // Each exposed slot is backed by its own component_tree field.
    $this->createComponentTreeField('node', 'article', 'canvas_slot_a');
    $this->createComponentTreeField('node', 'article', 'canvas_slot_b');
    $template_component_uuid = '2842cc6f-9e2b-42a5-8400-e7d6363e08bf';

    ContentTemplate::create([
      'content_entity_type_id' => 'node',
      'content_entity_type_bundle' => 'article',
      'content_entity_type_view_mode' => 'full',
      'component_tree' => [
        [
          'uuid' => $template_component_uuid,
          'component_id' => 'sdc.canvas_test_sdc.props-slots',
          'component_version' => '0e79e884426a53ae',
          // A static heading: the slot fields are the only entity data shown.
          'inputs' => [
            'heading' => [
              'sourceType' => 'static:field_item:string',
              'value' => 'Template heading',
              'expression' => 'ℹ︎string␟value',
            ],
          ],
        ],
      ],
      // Two fields targeting two different slots of the same component.
      'exposed_slots' => [
        'canvas_slot_a' => [
          'component_uuid' => $template_component_uuid,
          'slot_name' => 'the_body',
          'label' => 'Slot A',
        ],
        'canvas_slot_b' => [
          'component_uuid' => $template_component_uuid,
          'slot_name' => 'the_footer',
          'label' => 'Slot B',
        ],
      ],
    ])->setStatus(TRUE)->save();

    $node = $this->createNode([
      'type' => 'article',
      'title' => 'Two slots',
      'canvas_slot_a' => [
        [
          'uuid' => 'aaaaaaaa-0000-4000-8000-000000000001',
          'component_id' => 'sdc.canvas_test_sdc.props-no-slots',
          'inputs' => [
            'heading' => [
              'sourceType' => 'static:field_item:string',
              'value' => 'Content A',
              'expression' => 'ℹ︎string␟value',
            ],
          ],
        ],
      ],
      'canvas_slot_b' => [
        [
          'uuid' => 'bbbbbbbb-0000-4000-8000-000000000002',
          'component_id' => 'sdc.canvas_test_sdc.props-no-slots',
          'inputs' => [
            'heading' => [
              'sourceType' => 'static:field_item:string',
              'value' => 'Content B',
              'expression' => 'ℹ︎string␟value',
            ],
          ],
        ],
      ],
    ]);
    $viewBuilder = $this->container->get(EntityTypeManagerInterface::class)->getViewBuilder('node');
    self::assertInstanceOf(ContentTemplateAwareViewBuilder::class, $viewBuilder);
    $build = $viewBuilder->view($node);
    $crawler = $this->crawlerForRenderArray($build);
    // Each slot renders its own content in its own region, independently.
    self::assertCount(1, $crawler->filter('.component--props-slots--body h1:contains("Content A")'));
    self::assertCount(1, $crawler->filter('.component--props-slots--footer h1:contains("Content B")'));
    self::assertCount(0, $crawler->filter('.component--props-slots--body h1:contains("Content B")'));
    self::assertCount(0, $crawler->filter('.component--props-slots--footer h1:contains("Content A")'));
    self::assertEntityIsValid($node);

    // The slot content is the entity's own data, so the render cache entry
    // must be invalidated when the entity is saved.
    self::assertContains('node:' . $node->id(), $build['#cache']['tags']);
    $slot_a = $node->get('canvas_slot_a')->getValue();
    $slot_a[0]['inputs'] = str_replace('Content A', 'Content A, updated', $slot_a[0]['inputs']);
    $node->set('canvas_slot_a', $slot_a)->save();
    $build = $viewBuilder->view($node);
    $crawler = $this->crawlerForRenderArray($build);
    self::assertCount(1, $crawler->filter('.component--props-slots--body h1:contains("Content A, updated")'));
  }

  /**
   * Tests the empty slot marker is only valid as the only item in a slot field.
   *
   * @legacy-covers \Drupal\canvas\Plugin\Validation\Constraint\ComponentTreeStructureConstraintValidator
   */
  public function testEmptyOverrideMarkerValidation(): void {
    $this->createComponentTreeField('node', 'article', 'canvas_slot_custom');
    $template_component_uuid = '2842cc6f-9e2b-42a5-8400-e7d6363e08bf';

    ContentTemplate::create([
      'content_entity_type_id' => 'node',
      'content_entity_type_bundle' => 'article',
      'content_entity_type_view_mode' => 'full',
      'component_tree' => [
        [
          'uuid' => $template_component_uuid,
          'component_id' => 'sdc.canvas_test_sdc.props-slots',
          'component_version' => '0e79e884426a53ae',
          'inputs' => [
            'heading' => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:article␝title␞␟value',
            ],
          ],
        ],
      ],
      'exposed_slots' => [
        'canvas_slot_custom' => [
          'component_uuid' => $template_component_uuid,
          'slot_name' => 'the_body',
          'label' => 'Custom content area',
        ],
      ],
    ])->setStatus(TRUE)->save();

    // The marker as the sole root of the slot field is a valid empty override.
    $node = $this->createNode([
      'type' => 'article',
      'title' => 'Empty override',
      'canvas_slot_custom' => [
        [
          'uuid' => 'dddddddd-0000-4000-8000-000000000004',
          'component_id' => 'marker.empty_slot',
        ],
      ],
    ]);
    self::assertEntityIsValid($node);
    // Also when validation starts at the field instead of the entity.
    self::assertCount(0, $node->get('canvas_slot_custom')->validate());

    $expected_message = 'The marker.empty_slot component may only be used as the sole, empty override of an exposed slot.';
    $violation_messages = static fn (iterable $violations): array => \array_map(
      static fn ($violation): string => \strip_tags((string) $violation->getMessage()),
      \iterator_to_array($violations),
    );

    // The marker next to a regular component is rejected.
    $node->get('canvas_slot_custom')->appendItem([
      'uuid' => 'eeeeeeee-0000-4000-8000-000000000005',
      'component_id' => 'sdc.canvas_test_sdc.props-no-slots',
      'inputs' => [
        'heading' => [
          'sourceType' => 'static:field_item:string',
          'value' => 'Sibling',
          'expression' => 'ℹ︎string␟value',
        ],
      ],
    ]);
    self::assertContains($expected_message, $violation_messages($node->validate()));

    // The marker nested in another component's slot is rejected.
    $node->set('canvas_slot_custom', [
      [
        'uuid' => 'ffffffff-0000-4000-8000-000000000006',
        'component_id' => 'sdc.canvas_test_sdc.props-slots',
        'inputs' => [
          'heading' => [
            'sourceType' => 'static:field_item:string',
            'value' => 'Parent',
            'expression' => 'ℹ︎string␟value',
          ],
        ],
      ],
      [
        'uuid' => 'ffffffff-0000-4000-8000-000000000007',
        'parent_uuid' => 'ffffffff-0000-4000-8000-000000000006',
        'slot' => 'the_body',
        'component_id' => 'marker.empty_slot',
        'inputs' => [],
      ],
    ]);
    self::assertContains($expected_message, $violation_messages($node->validate()));

    // The marker in a content template's own tree is rejected.
    $template = ContentTemplate::create([
      'content_entity_type_id' => 'node',
      'content_entity_type_bundle' => 'article',
      'content_entity_type_view_mode' => 'full',
      'component_tree' => [
        [
          'uuid' => 'ffffffff-0000-4000-8000-000000000008',
          'component_id' => 'marker.empty_slot',
          'component_version' => '3b12c0b99a6caecc',
          'inputs' => [],
        ],
      ],
    ]);
    self::assertContains($expected_message, $violation_messages($template->getTypedData()->validate()));

    // The marker as the sole root of a canvas_page's tree is rejected: pages
    // have no exposed slots.
    $this->installEntitySchema(Page::ENTITY_TYPE_ID);
    $page = Page::create([
      'title' => 'Page',
      'components' => [
        [
          'uuid' => 'ffffffff-0000-4000-8000-000000000009',
          'component_id' => 'marker.empty_slot',
        ],
      ],
    ]);
    self::assertContains($expected_message, $violation_messages($page->validate()));
  }

  /**
   * Tests when a slot renders the template default instead of its field.
   *
   * Two cases: the slot content shares a UUID with the template or another
   * slot, or the current user may not view the slot field.
   *
   * @legacy-covers \Drupal\canvas\Entity\ContentTemplate::getMergedComponentTree
   * @legacy-covers \Drupal\canvas\Plugin\Validation\Constraint\ExposedSlotContentIsMergeableConstraintValidator
   */
  public function testSlotFallsBackToTemplateDefault(): void {
    $this->enableModules(['canvas_test_field_access']);
    $this->createComponentTreeField('node', 'article', 'canvas_slot_a');
    $this->createComponentTreeField('node', 'article', 'canvas_slot_view_denied');
    $template_component_uuid = '2842cc6f-9e2b-42a5-8400-e7d6363e08bf';
    $static_heading = fn (string $value): array => [
      'heading' => [
        'sourceType' => 'static:field_item:string',
        'value' => $value,
        'expression' => 'ℹ︎string␟value',
      ],
    ];
    ContentTemplate::create([
      'content_entity_type_id' => 'node',
      'content_entity_type_bundle' => 'article',
      'content_entity_type_view_mode' => 'full',
      'component_tree' => [
        [
          'uuid' => $template_component_uuid,
          'component_id' => 'sdc.canvas_test_sdc.props-slots',
          'component_version' => '0e79e884426a53ae',
          'inputs' => $static_heading('Template heading'),
        ],
        // Default content of both slots.
        [
          'uuid' => 'dddddddd-0000-4000-8000-000000000001',
          'component_id' => 'sdc.canvas_test_sdc.props-no-slots',
          'parent_uuid' => $template_component_uuid,
          'slot' => 'the_body',
          'inputs' => $static_heading('Default A'),
        ],
        [
          'uuid' => 'dddddddd-0000-4000-8000-000000000002',
          'component_id' => 'sdc.canvas_test_sdc.props-no-slots',
          'parent_uuid' => $template_component_uuid,
          'slot' => 'the_footer',
          'inputs' => $static_heading('Default B'),
        ],
      ],
      'exposed_slots' => [
        'canvas_slot_a' => [
          'component_uuid' => $template_component_uuid,
          'slot_name' => 'the_body',
          'label' => 'Slot A',
        ],
        'canvas_slot_view_denied' => [
          'component_uuid' => $template_component_uuid,
          'slot_name' => 'the_footer',
          'label' => 'Slot B',
        ],
      ],
    ])->setStatus(TRUE)->save();
    $viewBuilder = $this->container->get(EntityTypeManagerInterface::class)->getViewBuilder('node');
    $logger = new TestLogger();
    $this->container->get(LoggerChannelFactoryInterface::class)
      ->get('canvas')
      ->addLogger($logger);

    // Slot A reuses the UUID of slot B's default content: validation rejects
    // it, and rendering falls back to slot A's default and logs the collision.
    $node = $this->createNode([
      'type' => 'article',
      'title' => 'Fallback',
      'canvas_slot_a' => [
        [
          'uuid' => 'dddddddd-0000-4000-8000-000000000002',
          'component_id' => 'sdc.canvas_test_sdc.props-no-slots',
          'inputs' => $static_heading('Content A'),
        ],
      ],
    ]);
    $violations = $node->validate();
    self::assertCount(1, $violations);
    self::assertSame('canvas_slot_a', $violations->get(0)->getPropertyPath());
    self::assertSame('The <em class="placeholder">canvas_slot_a</em> exposed slot contains component instances that already exist in the template or in another exposed slot.', (string) $violations->get(0)->getMessage());
    $build = $viewBuilder->view($node);
    $crawler = $this->crawlerForRenderArray($build);
    self::assertCount(1, $crawler->filter('.component--props-slots--body h1:contains("Default A")'));
    self::assertCount(0, $crawler->filter('h1:contains("Content A")'));
    self::assertTrue($logger->hasRecordThatPasses(
      fn (array $record): bool => ($record['context']['@message'] ?? '') === 'Cannot inject subtree because some of its components are already in the final tree.',
      RfcLogLevel::ERROR,
    ));

    // Slot content the current user may not view renders the slot's default,
    // and the access result's cacheability is in the render array.
    $node = $this->createNode([
      'type' => 'article',
      'title' => 'Denied',
      'canvas_slot_view_denied' => [
        [
          'uuid' => 'bbbbbbbb-0000-4000-8000-000000000002',
          'component_id' => 'sdc.canvas_test_sdc.props-no-slots',
          'inputs' => $static_heading('Content B'),
        ],
      ],
    ]);
    self::assertEntityIsValid($node);
    $build = $viewBuilder->view($node);
    $crawler = $this->crawlerForRenderArray($build);
    self::assertCount(1, $crawler->filter('.component--props-slots--footer h1:contains("Default B")'));
    self::assertCount(0, $crawler->filter('h1:contains("Content B")'));
    self::assertContains('canvas_test_field_access:view_denied', $build['#cache']['tags']);
  }

  /**
   * Tests deleting a slot's backing field detaches it and rendering degrades.
   *
   * Deleting the field config through the standard field API triggers the
   * template's onDependencyRemoval(), which drops the exposed slot definition
   * (a non-destructive detach). Rendering then falls back to the template's own
   * tree instead of failing.
   *
   * @legacy-covers \Drupal\canvas\Entity\ContentTemplate::build
   * @legacy-covers \Drupal\canvas\Entity\ContentTemplate::onDependencyRemoval
   */
  public function testDeletingSlotFieldDetachesAndDegrades(): void {
    $this->createComponentTreeField('node', 'article', 'canvas_slot_custom');
    $template_component_uuid = '2842cc6f-9e2b-42a5-8400-e7d6363e08bf';
    ContentTemplate::create([
      'content_entity_type_id' => 'node',
      'content_entity_type_bundle' => 'article',
      'content_entity_type_view_mode' => 'full',
      'component_tree' => [
        [
          'uuid' => $template_component_uuid,
          'component_id' => 'sdc.canvas_test_sdc.props-slots',
          'component_version' => '0e79e884426a53ae',
          'inputs' => [
            'heading' => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:article␝title␞␟value',
            ],
          ],
        ],
      ],
      'exposed_slots' => [
        'canvas_slot_custom' => [
          'component_uuid' => $template_component_uuid,
          'slot_name' => 'the_body',
          'label' => 'Custom content area',
        ],
      ],
    ])->setStatus(TRUE)->save();

    // Deleting the backing field detaches the slot from the template.
    FieldStorageConfig::loadByName('node', 'canvas_slot_custom')?->delete();
    $this->container->get(EntityFieldManagerInterface::class)->clearCachedFieldDefinitions();
    $template = ContentTemplate::load('node.article.full');
    self::assertInstanceOf(ContentTemplate::class, $template);
    self::assertSame([], $template->getExposedSlots());

    $node = $this->createNode(['type' => 'article', 'title' => 'No field here']);
    $viewBuilder = $this->container->get(EntityTypeManagerInterface::class)->getViewBuilder('node');
    $build = $viewBuilder->view($node);
    $crawler = $this->crawlerForRenderArray($build);
    // The template still renders (its heading is the node title); no exception.
    self::assertCount(1, $crawler->filter('h1:contains("No field here")'));
  }

  /**
   * Tests that pruning a stale exposed slot also drops its field dependency.
   *
   * Without that, deleting the pruned slot's field would cascade-delete the
   * whole template instead of leaving it untouched.
   *
   * @legacy-covers \Drupal\canvas\Entity\ContentTemplate::preSave
   * @legacy-covers \Drupal\canvas\Entity\ContentTemplate::onDependencyRemoval
   */
  public function testPrunedSlotDoesNotKeepFieldDependency(): void {
    $this->createComponentTreeField('node', 'article', 'canvas_slot_a');
    $this->createComponentTreeField('node', 'article', 'canvas_slot_b');
    $component_a = '2842cc6f-9e2b-42a5-8400-e7d6363e08bf';
    $component_b = '5f7b0a54-2b8a-4c1e-9d3f-6a1e2c4b8d90';
    $component = static fn (string $uuid): array => [
      'uuid' => $uuid,
      'component_id' => 'sdc.canvas_test_sdc.props-slots',
      'component_version' => '0e79e884426a53ae',
      'inputs' => [
        'heading' => [
          'sourceType' => PropSource::EntityField->value,
          'expression' => 'ℹ︎␜entity:node:article␝title␞␟value',
        ],
      ],
    ];
    $template = ContentTemplate::create([
      'content_entity_type_id' => 'node',
      'content_entity_type_bundle' => 'article',
      'content_entity_type_view_mode' => 'full',
      'component_tree' => [$component($component_a), $component($component_b)],
      'exposed_slots' => [
        'canvas_slot_a' => [
          'component_uuid' => $component_a,
          'slot_name' => 'the_body',
          'label' => 'Slot A',
        ],
        'canvas_slot_b' => [
          'component_uuid' => $component_b,
          'slot_name' => 'the_body',
          'label' => 'Slot B',
        ],
      ],
    ]);
    $template->setStatus(TRUE)->save();
    self::assertContains('field.field.node.article.canvas_slot_b', $template->getDependencies()['config']);

    // Remove the component hosting slot B: saving prunes slot B.
    $template->setComponentTree([$component($component_a)]);
    $template->save();
    self::assertSame(['canvas_slot_a'], \array_keys($template->getExposedSlots()));
    self::assertNotContains('field.field.node.article.canvas_slot_b', $template->getDependencies()['config']);

    // Deleting slot B's field must leave the template alone.
    FieldStorageConfig::loadByName('node', 'canvas_slot_b')?->delete();
    $template = ContentTemplate::load('node.article.full');
    self::assertInstanceOf(ContentTemplate::class, $template);
    self::assertSame(['canvas_slot_a'], \array_keys($template->getExposedSlots()));
  }

  /**
   * Tests that detaching a slot keeps entity content and re-exposing restores it.
   *
   * Detaching only drops the template's `exposed_slots` entry; the backing
   * field and every entity's content in it survive, invisible until the slot
   * is exposed again.
   *
   * @legacy-covers \Drupal\canvas\Entity\ContentTemplate::build
   * @legacy-covers \Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItemList::injectSlotContent
   */
  public function testDetachKeepsContentAndReExposeRestoresIt(): void {
    $this->createComponentTreeField('node', 'article', 'canvas_slot_main');
    $template_component_uuid = '2842cc6f-9e2b-42a5-8400-e7d6363e08bf';
    $exposed_slots = [
      'canvas_slot_main' => [
        'component_uuid' => $template_component_uuid,
        'slot_name' => 'the_body',
        'label' => 'Main',
      ],
    ];
    $template = ContentTemplate::create([
      'content_entity_type_id' => 'node',
      'content_entity_type_bundle' => 'article',
      'content_entity_type_view_mode' => 'full',
      'component_tree' => [
        [
          'uuid' => $template_component_uuid,
          'component_id' => 'sdc.canvas_test_sdc.props-slots',
          'component_version' => '0e79e884426a53ae',
          'inputs' => [
            'heading' => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:article␝title␞␟value',
            ],
          ],
        ],
      ],
      'exposed_slots' => $exposed_slots,
    ]);
    $template->setStatus(TRUE)->save();

    $node = $this->createNode([
      'type' => 'article',
      'title' => 'Detach me',
      'canvas_slot_main' => [
        [
          'uuid' => 'aaaaaaaa-0000-4000-8000-000000000001',
          'component_id' => 'sdc.canvas_test_sdc.props-no-slots',
          'inputs' => [
            'heading' => [
              'sourceType' => 'static:field_item:string',
              'value' => 'Entity content',
              'expression' => 'ℹ︎string␟value',
            ],
          ],
        ],
      ],
    ]);
    $render = function () use ($node): Crawler {
      $storage = $this->container->get(EntityTypeManagerInterface::class)->getStorage('node');
      $storage->resetCache([(int) $node->id()]);
      $fresh = $storage->load((int) $node->id());
      self::assertInstanceOf(NodeInterface::class, $fresh);
      $view_builder = $this->container->get(EntityTypeManagerInterface::class)->getViewBuilder('node');
      $view_builder->resetCache();
      $build = $view_builder->view($fresh);
      return $this->crawlerForRenderArray($build);
    };
    self::assertCount(1, $render()->filter('.component--props-slots--body h1:contains("Entity content")'));

    // Detach: the template stops exposing the slot, the field is kept.
    $template->set('exposed_slots', [])->save();
    self::assertNotNull(FieldConfig::loadByName('node', 'article', 'canvas_slot_main'));
    self::assertCount(0, $render()->filter('h1:contains("Entity content")'));
    $stored = $this->container->get(EntityTypeManagerInterface::class)->getStorage('node')->loadUnchanged((int) $node->id());
    self::assertInstanceOf(NodeInterface::class, $stored);
    self::assertCount(1, $stored->get('canvas_slot_main'));

    // Re-expose: the preserved content renders again.
    $template->set('exposed_slots', $exposed_slots)->save();
    self::assertCount(1, $render()->filter('.component--props-slots--body h1:contains("Entity content")'));
  }

  /**
   * Tests a multiple-cardinality image field that populates a list of images.
   *
   * @param array<string, string> $screenshots
   *   The image field items to create: alternative texts, keyed by file name.
   * @param string|null $expected_error
   *   The error that rendering the component logs, if any.
   *
   * @legacy-covers \Drupal\canvas\Entity\ContentTemplate::build
   * @legacy-covers \Drupal\canvas\PropExpressions\StructuredData\Evaluator
   *
   * @see tests/modules/canvas_test_sdc/components/image-gallery/image-gallery.component.yml
   * @see \Drupal\canvas\Element\RenderSafeComponentContainer
   */
  #[DataProvider('providerMultipleCardinalityImageFieldPopulatesListOfImages')]
  public function testMultipleCardinalityImageFieldPopulatesListOfImages(array $screenshots, ?string $expected_error): void {
    $this->installEntitySchema('file');
    $this->installSchema('file', ['file_usage']);
    $this->createImageField('field_screenshots', 'node', 'article', ['cardinality' => FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED]);

    $component = Component::load('sdc.canvas_test_sdc.image-gallery');
    self::assertInstanceOf(Component::class, $component);
    $template = ContentTemplate::create([
      'content_entity_type_id' => 'node',
      'content_entity_type_bundle' => 'article',
      'content_entity_type_view_mode' => 'full',
      'component_tree' => [
        [
          'uuid' => '2842cc6f-9e2b-42a5-8400-e7d6363e08bf',
          'component_id' => 'sdc.canvas_test_sdc.image-gallery',
          'component_version' => $component->getActiveVersion(),
          'inputs' => [
            'images' => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:article␝field_screenshots␞␟{src↠src_with_alternate_widths,alt↠alt,width↠width,height↠height}',
            ],
          ],
        ],
      ],
    ])->setStatus(TRUE);
    self::assertEntityIsValid($template);
    $template->save();

    $field_items = [];
    $file_ids = [];
    foreach ($screenshots as $filename => $alt) {
      $file = File::create(['uri' => 'public://' . $filename, 'filename' => $filename]);
      $file->save();
      $file_ids[$filename] = $file->id();
      $field_items[] = ['target_id' => $file->id(), 'alt' => $alt, 'width' => 10, 'height' => 11];
    }
    $node = $this->createNode([
      'type' => 'article',
      'title' => 'Screenshots',
      'field_screenshots' => $field_items,
    ]);

    $logger = new TestLogger();
    $this->container->get(LoggerChannelFactoryInterface::class)
      ->get('canvas')
      ->addLogger($logger);
    $build = $this->container->get(EntityTypeManagerInterface::class)->getViewBuilder('node')->view($node);
    $crawler = $this->crawlerForRenderArray($build);
    self::assertCount(\count($screenshots), $crawler->filter('figure img'));
    foreach ($screenshots as $filename => $alt) {
      self::assertCount(1, $crawler->filter(\sprintf('figure img[alt="%s"][src*="%s"]', $alt, $filename)));
      // The `src` of every image depends on its file entity.
      self::assertContains('file:' . $file_ids[$filename], $build['#cache']['tags']);
    }

    if ($expected_error === NULL) {
      self::assertFalse($logger->hasRecords(RfcLogLevel::ERROR));
      return;
    }
    // Only this component instance fails to render.
    self::assertCount(1, $crawler->filter('[data-component-uuid="2842cc6f-9e2b-42a5-8400-e7d6363e08bf"]:contains("Oops, something went wrong!")'));
    self::assertTrue($logger->hasRecordThatContains($expected_error, RfcLogLevel::ERROR));
  }

  /**
   * @return iterable<string, array{array<string, string>, string|null}>
   */
  public static function providerMultipleCardinalityImageFieldPopulatesListOfImages(): iterable {
    yield 'two images' => [
      ['a.png' => 'Screenshot A', 'b.png' => 'Screenshot B'],
      NULL,
    ];
    // The `images` prop is required, so it requires at least 1 image.
    yield 'no images' => [
      [],
      '[canvas_test_sdc:image-gallery/images] There must be a minimum of 1 items in the array, 0 found.',
    ];
  }

  /**
   * Tests that saving a template updates the exposed-slot bundles at once.
   *
   * @legacy-covers \Drupal\canvas\Storage\ComponentTreeLoader::getBundlesWithExposedSlots
   * @legacy-covers \Drupal\canvas\Hook\ContentTemplateHooks::resetExposedSlotBundles
   */
  public function testExposedSlotBundlesFollowTemplateSaves(): void {
    $loader = $this->container->get(ComponentTreeLoader::class);
    self::assertSame([], $loader->getBundlesWithExposedSlots('node'));

    $this->createComponentTreeField('node', 'article', 'canvas_slot_main');
    $template = ContentTemplate::create([
      'content_entity_type_id' => 'node',
      'content_entity_type_bundle' => 'article',
      'content_entity_type_view_mode' => 'full',
      'component_tree' => [
        [
          'uuid' => '2842cc6f-9e2b-42a5-8400-e7d6363e08bf',
          'component_id' => 'sdc.canvas_test_sdc.props-slots',
          'component_version' => '0e79e884426a53ae',
          'inputs' => [
            'heading' => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:article␝title␞␟value',
            ],
          ],
        ],
      ],
      'exposed_slots' => [
        'canvas_slot_main' => [
          'component_uuid' => '2842cc6f-9e2b-42a5-8400-e7d6363e08bf',
          'slot_name' => 'the_body',
          'label' => 'Main',
        ],
      ],
    ]);
    $template->setStatus(TRUE)->save();
    // Same request: the memo reflects the new template.
    self::assertSame(['article'], $loader->getBundlesWithExposedSlots('node'));

    $template->set('exposed_slots', [])->save();
    self::assertSame([], $loader->getBundlesWithExposedSlots('node'));
  }

}
