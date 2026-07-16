<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel;

use ColinODell\PsrTestLogger\TestLogger;
use Drupal\canvas\Controller\ApiContentTemplateSlotFieldController;
use Drupal\canvas\Entity\ContentTemplate;
use Drupal\canvas\EntityHandlers\ContentTemplateAwareViewBuilder;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\canvas\PropSource\PropSource;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Entity\Entity\EntityViewMode;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\filter\Entity\FilterFormat;
use Drupal\node\NodeInterface;
use Drupal\Tests\canvas\Traits\CanvasFieldCreationTrait;
use Drupal\Tests\canvas\Traits\CrawlerTrait;
use Drupal\Tests\canvas\Traits\GenerateComponentConfigTrait;
use Drupal\Tests\canvas\Traits\SingleDirectoryComponentTreeTestTrait;
use Drupal\Tests\node\Traits\ContentTypeCreationTrait;
use Drupal\Tests\node\Traits\NodeCreationTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

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
    $node = $this->container->get(EntityTypeManagerInterface::class)->getStorage('node')->loadUnchanged($node->id());
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
          'component_id' => 'canvas_slot_empty.marker',
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
    $controller = $this->container->get('class_resolver')
      ->getInstanceFromDefinition(ApiContentTemplateSlotFieldController::class);
    // Three articles override the slot (two filled, one empty marker); the two
    // inheriting articles and the page node (different bundle, same field) are
    // not counted.
    $data = \json_decode((string) $controller->usage($template, 'canvas_slot_custom')->getContent(), TRUE);
    self::assertSame(['overridden' => 3], $data);

    // An unknown field is a 404.
    $this->expectException(NotFoundHttpException::class);
    $controller->usage($template, 'canvas_slot_missing');
  }

  /**
   * A same-named storage of another field type cannot back a slot field.
   *
   * @legacy-covers \Drupal\canvas\Controller\ApiContentTemplateSlotFieldController::create
   */
  public function testSlotFieldCreateRejectsForeignStorageType(): void {
    FieldStorageConfig::create([
      'field_name' => 'canvas_slot_taken',
      'entity_type' => 'node',
      'type' => 'string',
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
    ])->setStatus(TRUE)->save();
    $template = ContentTemplate::load('node.article.full');
    self::assertInstanceOf(ContentTemplate::class, $template);

    $controller = $this->container->get('class_resolver')
      ->getInstanceFromDefinition(ApiContentTemplateSlotFieldController::class);
    $request = Request::create('/', 'POST', content: (string) \json_encode(['fieldName' => 'canvas_slot_taken', 'label' => 'Taken']));
    $this->expectException(ConflictHttpException::class);
    $this->expectExceptionMessage('is not a component_tree field');
    $controller->create($request, $template);
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
    $controller = $this->container->get('class_resolver')
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
          'inputs' => [
            'heading' => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:article␝title␞␟value',
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
  }

  /**
   * Tests the empty-override marker is pinned to sole-root-of-slot-field usage.
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
          'component_id' => 'canvas_slot_empty.marker',
        ],
      ],
    ]);
    self::assertEntityIsValid($node);

    // The marker alongside a second row (no longer the sole row) is rejected.
    $node->get('canvas_slot_custom')->appendItem([
      'uuid' => 'eeeeeeee-0000-4000-8000-000000000005',
      'component_id' => 'canvas_slot_empty.marker',
    ]);
    $messages = \array_map(
      static fn ($violation): string => \strip_tags((string) $violation->getMessage()),
      \iterator_to_array($node->validate()),
    );
    self::assertContains('The canvas_slot_empty.marker component may only be used as the sole, empty override of an exposed slot.', $messages);
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
    $this->container->get('entity_field.manager')->clearCachedFieldDefinitions();
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

}
