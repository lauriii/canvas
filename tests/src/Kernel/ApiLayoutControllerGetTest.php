<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel;

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\CanvasUriDefinitions;
use Drupal\canvas\Controller\ApiLayoutController;
use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\ContentTemplate;
use Drupal\canvas\Entity\Page;
use Drupal\canvas\Entity\PageRegion;
use Drupal\canvas\Plugin\DisplayVariant\CanvasPageVariant;
use Drupal\canvas\PropSource\PropSource;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\Entity\EntityViewMode;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleInstallerInterface;
use Drupal\Core\Extension\ThemeInstallerInterface;
use Drupal\Core\Http\Exception\CacheableAccessDeniedHttpException;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\ParamConverter\ParamNotConvertedException;
use Drupal\Core\Theme\ThemeManagerInterface;
use Drupal\Core\Url;
use Drupal\language\ConfigurableLanguageManagerInterface;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\link\LinkItemInterface;
use Drupal\link\LinkTitleVisibility;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;
use Drupal\Tests\canvas\TestSite\CanvasTestSetup;
use Drupal\Tests\canvas\Traits\ConstraintViolationsTestTrait;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Tests Api Layout Controller Get.
 *
 * @legacy-covers \Drupal\canvas\Controller\ApiLayoutController::get
 */
#[RunTestsInSeparateProcesses]
#[Group('canvas')]
#[Group('#slow')]
class ApiLayoutControllerGetTest extends ApiLayoutControllerTestBase {

  private const string PUBLISHED_PAGE_TITLE = 'Published version';
  private const string AUTO_SAVE_PAGE_TITLE = 'Auto-save version';
  private const string LAYOUT_GET_WITH_QUERY_ARGUMENT_URI_PATTERN = '%s?%s=%s';

  use ConstraintViolationsTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    // Allows format=uri to be stored using URI field type.
    'canvas_test_storable_prop_shape_alter',
    'sdc_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->container->get(ModuleInstallerInterface::class)->install(['system']);
    (new CanvasTestSetup())->setup(TRUE);
    $this->setUpCurrentUser([], ['edit any article content']);
  }

  /**
 * Tests empty.
 */
  #[DataProvider('providerEntityTypes')]
  public function testEmpty(string $entity_type): void {
    $entity = $this->getTestEntity($entity_type);
    $this->setUpCurrentUser([], [self::getAdminPermission($entity)]);
    // Enable global regions.
    $regions = $this->enableGlobalRegions();
    foreach ($regions as $region) {
      // But let's make sure none of them have a component tree so we have an
      // empty model.
      $region->setComponentTree([])->save();
    }
    $url = $this->getLayoutUrl($entity);
    $response = $this->request(Request::create($url->toString()));
    self::assertEquals(Response::HTTP_OK, $response->getStatusCode());
    $this->assertResponseAutoSaves($response, [$entity]);
  }

  /**
   * @see \Drupal\canvas\Entity\ContentTemplate
   */
  public function testRenderDynamic(): void {
    $contentTemplate = $this->getTestEntity(ContentTemplate::ENTITY_TYPE_ID);
    \assert($contentTemplate instanceof ContentTemplate);

    $top_level_component_uuid = '5f71027b-d9d3-4f3d-8990-a6502c0ba676';
    $nested_component_uuid = '8caf6e23-8fb4-4524-bdb6-f57a2a6e7859';
    // Add a heading populated by an entity field prop source using the `title`
    // field.
    $components = [
      [
        'uuid' => $top_level_component_uuid,
        'component_id' => 'sdc.canvas_test_sdc.props-slots',
        'component_version' => '0e79e884426a53ae',
        'inputs' => [
          'heading' => [
            'sourceType' => PropSource::EntityField->value,
            'expression' => 'ℹ︎␜entity:node:article␝title␞␟value',
          ],
        ],
      ],
      [
        'uuid' => $nested_component_uuid,
        'component_id' => 'sdc.canvas_test_sdc.props-no-slots',
        'component_version' => 'd34b93534777207a',
        'inputs' => [
          'heading' => [
            'sourceType' => PropSource::EntityField->value,
            'expression' => 'ℹ︎␜entity:node:article␝title␞␟value',
          ],
        ],
        'slot' => 'the_body',
        'parent_uuid' => $top_level_component_uuid,
      ],
    ];
    $contentTemplate->setComponentTree($components)->save();
    // @todo Remove this in favor of using ContribStrictConfigSchemaTestTrait in https://www.drupal.org/project/canvas/issues/3531679
    self::assertCount(0, $contentTemplate->getTypedData()->validate(), (string) $contentTemplate->getTypedData()->validate());
    $get_layout_api_request = Request::create($this->getLayoutUrl($contentTemplate)->toString());
    $this->setUpCurrentUser([], [self::getAdminPermission($contentTemplate)]);

    // Local helper to check these are in sync/contain the expected title:
    // - entity label
    // - `model` in API response
    // - `html` in API.
    $title_matches_resolved_and_html = function (string $expected_title, JsonResponse $response) use ($top_level_component_uuid, $nested_component_uuid) {
      // Current preview entity label MUST match the expected title.
      self::assertSame($expected_title, $this->previewEntity?->label());
      // The `model` of the layout API response MUST contain the expected title.
      self::assertSame($expected_title, static::decodeResponse($response)['model'][$top_level_component_uuid]['resolved']['heading']);
      self::assertSame($expected_title, static::decodeResponse($response)['model'][$nested_component_uuid]['resolved']['heading']);
      // The `html` of the layout API response MUST render the expected title in
      // the both the top-level and nested component.
      self::assertCount(1, $this->cssSelect('[data-component-id="canvas_test_sdc:props-slots"]'));
      // Make sure we match only the h1 that is direct the child of the component
      // so don't match the one in the nested component.
      self::assertSame($expected_title, (string) $this->cssSelect('[data-component-id="canvas_test_sdc:props-slots"] > h1')[0]);
      self::assertCount(1, $this->cssSelect('[data-component-id="canvas_test_sdc:props-slots"] [data-component-id="canvas_test_sdc:props-no-slots"]'));
      self::assertSame($expected_title, (string) $this->cssSelect('[data-component-id="canvas_test_sdc:props-slots"] [data-component-id="canvas_test_sdc:props-no-slots"] > h1')[0]);
    };

    // Assert the original resolved entity field prop source + resulting HTML.
    $response = $this->request($get_layout_api_request);
    self::assertInstanceOf(JsonResponse::class, $response);
    $title_matches_resolved_and_html('Canvas Needs This For The Time Being', $response);

    // Updating the title of the preview entity must propagate throughout.
    \assert($this->previewEntity instanceof Node);
    $this->previewEntity->set('title', 'New title for preview')->save();
    $response = $this->request($get_layout_api_request);
    self::assertInstanceOf(JsonResponse::class, $response);
    $title_matches_resolved_and_html('New title for preview', $response);
  }

  /**
 * Tests .
 */
  #[DataProvider('providerEntityTypes')]
  public function test(string $entity_type): void {
    // By default, there is only the "content" region in the client-side
    // representation.
    $entity = $this->getTestEntity($entity_type);
    $admin_permission = self::getAdminPermission($entity);
    $this->setUpCurrentUser([], [$admin_permission]);

    $this->assertRegions(1, $entity);
    /** @var \Drupal\canvas\AutoSave\AutoSaveManager $autoSave */
    $autoSave = $this->container->get(AutoSaveManager::class);
    self::assertTrue($autoSave->getAutoSaveEntity($entity)->isEmpty());
    $regions = $this->enableGlobalRegions();

    // … but the corresponding client-side representation contains only the
    // "content" region unless it has permissions to edit the global regions.
    $this->assertRegions(1, $entity);

    $this->setUpCurrentUser([], [$admin_permission, PageRegion::ADMIN_PERMISSION]);

    // … and the corresponding client-side representation contains all regions
    // plus one more (the "content" region) once it has the required permission.
    $this->assertRegions(12, $entity);

    // Disable a PageRegion to make it non-editable, and check that only 11
    // regions are present in the client-side representation.
    $regions['stark.highlighted']->disable()->save();
    $this->assertRegions(11, $entity);

    // Store a draft region in the auto-save manager and confirm that is returned.
    $regions['stark.highlighted']->enable()->save();
    $layoutData = [
      'layout' => [
        [
          "nodeType" => "component",
          "slots" => [],
          "type" => "block.page_title_block@" . Component::load('block.page_title_block')?->getActiveVersion(),
          "uuid" => "c3f3c22c-c22e-4bb6-ad16-635f069148e4",
        ],
      ],
      'model' => [
        "c3f3c22c-c22e-4bb6-ad16-635f069148e4" => [
          "label" => "Page title",
          "label_display" => "0",
          "provider" => "core",
        ],
      ],
    ];
    $stark_highlighted = $regions['stark.highlighted']->forAutoSaveData($layoutData, validate: TRUE);
    $autoSave->saveEntity($stark_highlighted);

    $url = $this->getLayoutUrl($entity);

    // Draft of highlighted region in global template should be returned even if
    // there is no auto-save data for the node.
    $response = $this->request(Request::create($url->toString()));
    $expected_title = match(TRUE) {
      ContentTemplate::ENTITY_TYPE_ID === $entity_type && $this->previewEntity instanceof ContentEntityInterface => $this->previewEntity->label(),
      default => $entity->label(),
    };
    $this->assertTitle($expected_title . ' | Drupal');
    $this->assertResponseAutoSaves($response, [$entity], TRUE);
    $json = static::decodeResponse($response);
    self::assertArrayHasKey('layout', $json);
    $highlightedRegion = \array_filter($json['layout'], static fn (array $region) => ($region['id'] ?? NULL) === 'highlighted');
    self::assertCount(1, $highlightedRegion);
    self::assertArrayHasKey('model', $json);
    self::assertArrayHasKey('c3f3c22c-c22e-4bb6-ad16-635f069148e4', $json['model']);
    self::assertEquals('Page title', $json['model']['c3f3c22c-c22e-4bb6-ad16-635f069148e4']['resolved']['label']);
    self::assertEquals([
      [
        "nodeType" => "component",
        "slots" => [],
        "type" => "block.page_title_block@" . Component::load('block.page_title_block')?->getActiveVersion(),
        "uuid" => "c3f3c22c-c22e-4bb6-ad16-635f069148e4",
        'name' => NULL,
      ],
    ], reset($highlightedRegion)['components']);

    $original_entity = $entity::load($entity->id());
    \assert($original_entity instanceof $entity);
    // Update the title.
    if ($original_entity instanceof Node) {
      $new_title = $this->getRandomGenerator()->sentences(10);
      $original_entity->setTitle($new_title);
      // Note we use a string here.
      $original_entity->set('status', '1');
      $autoSave->saveEntity($original_entity);
    }

    $response = $this->request(Request::create($url->toString()));
    $this->assertResponseAutoSaves($response, [$original_entity], TRUE);

    // Extract HTML from JSON response for title assertion.
    $expected_title = match(TRUE) {
      ContentTemplate::ENTITY_TYPE_ID === $entity_type && $this->previewEntity instanceof ContentEntityInterface => $this->previewEntity->label(),
      default => $original_entity->label(),
    };
    $this->assertTitle($expected_title . " | Drupal");

    $json = static::decodeResponse($response);
    self::assertArrayHasKey('layout', $json);
    $highlightedRegion = \array_filter($json['layout'], static fn (array $region) => ($region['id'] ?? NULL) === 'highlighted');
    self::assertCount(1, $highlightedRegion);
    self::assertArrayHasKey('model', $json);
    self::assertArrayHasKey('c3f3c22c-c22e-4bb6-ad16-635f069148e4', $json['model']);
    self::assertEquals('Page title', $json['model']['c3f3c22c-c22e-4bb6-ad16-635f069148e4']['resolved']['label']);
    self::assertEquals([
      [
        "nodeType" => "component",
        "slots" => [],
        "type" => "block.page_title_block@" . Component::load('block.page_title_block')?->getActiveVersion(),
        "uuid" => "c3f3c22c-c22e-4bb6-ad16-635f069148e4",
        'name' => NULL,
      ],
    ], reset($highlightedRegion)['components']);
    if ($original_entity instanceof Node) {
      \assert(isset($new_title));
      self::assertEquals($new_title, $json['entity_form_fields']['title[0][value]']);
    }
    else {
      self::assertArrayNotHasKey('entity_form_fields', $json);
    }

    // Now let's remove the draft of the page region but retain that of the
    // node.
    $autoSave->delete($regions['stark.highlighted']);
    // We should still see the global regions.
    $response = $this->request(Request::create($url->toString()));
    $this->assertResponseAutoSaves($response, [$original_entity], TRUE);
    $json = static::decodeResponse($response);
    self::assertArrayHasKey('layout', $json);
    $highlightedRegion = \array_filter($json['layout'], static fn (array $region) => ($region['id'] ?? NULL) === 'highlighted');
    self::assertCount(1, $highlightedRegion);
    // @see \Drupal\Tests\canvas\TestSite\CanvasTestSetup::setup()
    self::assertEquals([
      [
        "nodeType" => "component",
        "slots" => [],
        "type" => "block.page_title_block@" . Component::load('block.page_title_block')?->getActiveVersion(),
        'name' => NULL,
      ],
    ],
      // Filter out the UUID as that is added randomly by creating the block
      // in the setup class.
      \array_map(static fn(array $component) => \array_diff_key($component, \array_flip(['uuid'])), \current($highlightedRegion)['components']));

    // Test that saving the exact values as the stored/live node, no auto-saves
    // remain.
    $original_entity = $entity::load($entity->id());
    \assert($original_entity instanceof $entity);
    $autoSave->saveEntity($original_entity);
    $response = $this->request(Request::create($url->toString()));
    $this->assertResponseAutoSaves($response, [$original_entity], TRUE);
  }

  /**
   * Global regions are hidden when editing a content template for non-full view mode.
   */
  public function testNonFullContentTemplateHidesGlobalRegions(): void {
    // Ensure the teaser view mode exists (node module provides it by default).
    $view_mode = $this->container->get(EntityTypeManagerInterface::class)
      ->getStorage('entity_view_mode')
      ->load('node.teaser');
    if ($view_mode === NULL) {
      EntityViewMode::create([
        'id' => 'node.teaser',
        'label' => 'Teaser',
        'targetEntityType' => 'node',
      ])->save();
    }

    ContentTemplate::create([
      'id' => 'node.article.teaser',
      'content_entity_type_id' => 'node',
      'content_entity_type_bundle' => 'article',
      'content_entity_type_view_mode' => 'teaser',
      'component_tree' => [
        [
          'uuid' => 'e1f6fbca-e331-4506-9dba-5734194c1e59',
          'component_id' => 'sdc.canvas_test_sdc.my-hero',
          'component_version' => 'a681ae184a8f6b7f',
          'inputs' => [
            'heading' => 'Canvas is large and in charge!',
            'subheading' => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:article␝created␞␟value',
              'adapter' => 'unix_to_date',
            ],
            'cta1' => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:article␝title␞␟value',
            ],
            'cta1href' => [
              'sourceType' => PropSource::HostEntityUrl->value,
            ],
          ],
        ],
      ],
    ])->save();

    $this->previewEntity = Node::load(1);
    self::assertInstanceOf(ContentEntityInterface::class, $this->previewEntity);
    $entity = ContentTemplate::load('node.article.teaser');
    self::assertInstanceOf(ContentTemplate::class, $entity);
    $this->setUpCurrentUser([], [ContentTemplate::ADMIN_PERMISSION, PageRegion::ADMIN_PERMISSION]);
    $this->enableGlobalRegions();

    $url = $this->getLayoutUrl($entity);
    $response = $this->request(Request::create($url->toString()));
    self::assertSame(Response::HTTP_OK, $response->getStatusCode());

    $json = static::decodeResponse($response);
    self::assertArrayHasKey('layout', $json);
    self::assertCount(1, $json['layout'], 'Non-full content template must expose only the content region.');
    self::assertSame(CanvasPageVariant::MAIN_CONTENT_REGION, $json['layout'][0]['id']);

    $this->assertResponseAutoSaves($response, [$entity], FALSE);

    self::assertArrayHasKey('html', $json);
    $this->setRawContent($json['html']);
    self::assertNotNull($this->getRegion('content'), 'Content region must be present in preview HTML.');
    self::assertNull($this->getRegion('header'), 'Global regions must not appear in preview HTML for non-full view mode.');
    self::assertNull($this->getRegion('highlighted'), 'Global regions must not appear in preview HTML for non-full view mode.');
  }

  protected function assertRegions(int $count, EntityInterface $entity): NodeInterface {
    $node = Node::load(1);
    \assert($node instanceof NodeInterface);
    $url = $this->getLayoutUrl($entity);
    // Draft of highlighted region in global template should be returned even if
    // there is no auto-save data for the node.
    $response = $this->request(Request::create($url->toString()));
    $json = static::decodeResponse($response);
    $this->assertArrayHasKey('layout', $json);
    $this->assertCount($count, $json['layout']);
    self::assertArrayHasKey('html', $json);
    self::assertArrayHasKey('translations', $json);
    self::assertArrayHasKey('available', $json['translations']);
    self::assertIsArray($json['translations']['available']);
    $content = $this->getRegion('content');
    $this->assertNotNull($content);

    foreach ($json['layout'] as $region) {
      $this->assertArrayHasKey('nodeType', $region);
      $this->assertSame('region', $region['nodeType']);
      $this->assertArrayHasKey('id', $region);
      $this->assertArrayHasKey('name', $region);
      $this->assertArrayHasKey('components', $region);

      if ($region['id'] === 'highlighted') {
        // @see \Drupal\Tests\canvas\TestSite\CanvasTestSetup::setup()
        $this->assertEquals([
          [
            "nodeType" => "component",
            'name' => NULL,
            "slots" => [],
            // The component version may vary depending on upstream changes in
            // core.
            "type" => "block.page_title_block@" . Component::load('block.page_title_block')?->getActiveVersion(),
          ],
        ],
          // Filter out the UUID as that is added randomly by creating the block
          // in the setup class.
          \array_map(static fn(array $component) => \array_diff_key($component, \array_flip(['uuid'])), $region['components']));
        continue;
      }
      if ($region['id'] === 'sidebar_first') {
        // @see \Drupal\Tests\canvas\TestSite\CanvasTestSetup::setup()
        // @see \Drupal\canvas\Entity\PageRegion::createFromBlockLayout()
        $this->assertSame([
          [
            "nodeType" => "component",
            // The component version may vary depending on upstream changes in
            // core.
            "type" => "block.system_messages_block@" . Component::load('block.system_messages_block')?->getActiveVersion(),
            'name' => NULL,
            "slots" => [],
          ],
        ],
          // Filter out the UUID as that is added randomly by creating the block
          // in the setup class.
          \array_map(static fn(array $component) => \array_diff_key($component, \array_flip(['uuid'])), $region['components']));
        continue;
      }
      if ($region['id'] !== CanvasPageVariant::MAIN_CONTENT_REGION) {
        $this->assertEmpty($region['components']);
        continue;
      }
      $this->assertSame('Content', $region['name']);
      $this->assertSame([
        [
          'uuid' => CanvasTestSetup::UUID_TWO_COLUMN_UUID,
          'nodeType' => 'component',
          'type' => 'sdc.canvas_test_sdc.two_column@f90c1f6cfb2fc04a',
          'name' => NULL,
          'slots' => [
            [
              'id' => CanvasTestSetup::UUID_TWO_COLUMN_UUID . '/column_one',
              'name' => 'column_one',
              'nodeType' => 'slot',
              'components' => [
                [
                  'uuid' => CanvasTestSetup::UUID_STATIC_IMAGE,
                  'nodeType' => 'component',
                  'type' => 'sdc.canvas_test_sdc.image@fb40be57bd7e0973',
                  'name' => NULL,
                  'slots' => [],
                ],
                [
                  'uuid' => CanvasTestSetup::UUID_STATIC_CARD1,
                  'nodeType' => 'component',
                  'type' => 'sdc.canvas_test_sdc.my-hero@a681ae184a8f6b7f',
                  'name' => NULL,
                  'slots' => [],
                ],
                [
                  'uuid' => CanvasTestSetup::UUID_CODE_COMPONENT,
                  'nodeType' => 'component',
                  'type' => 'js.test-code-component@36a8cee6a86c3d8d',
                  'name' => NULL,
                  'slots' => [],
                ],
                [
                  'uuid' => CanvasTestSetup::UUID_ALL_SLOTS_EMPTY,
                  'nodeType' => 'component',
                  'type' => 'sdc.canvas_test_sdc.one_column@80cc82f44d0a94f2',
                  'name' => NULL,
                  'slots' => [
                    [
                      'id' => CanvasTestSetup::UUID_ALL_SLOTS_EMPTY . '/content',
                      'name' => 'content',
                      'nodeType' => 'slot',
                      'components' => [],
                    ],
                  ],
                ],
              ],
            ],
            [
              'id' => CanvasTestSetup::UUID_TWO_COLUMN_UUID . '/column_two',
              'name' => 'column_two',
              'nodeType' => 'slot',
              'components' => [
                [
                  'uuid' => CanvasTestSetup::UUID_STATIC_CARD2,
                  'nodeType' => 'component',
                  'type' => 'sdc.canvas_test_sdc.my-hero@a681ae184a8f6b7f',
                  'name' => NULL,
                  'slots' => [],
                ],
                [
                  'uuid' => CanvasTestSetup::UUID_STATIC_CARD3,
                  'nodeType' => 'component',
                  'type' => 'sdc.canvas_test_sdc.my-hero@a681ae184a8f6b7f',
                  'name' => NULL,
                  'slots' => [],
                ],
                [
                  'uuid' => CanvasTestSetup::UUID_STATIC_IMAGE2,
                  'nodeType' => 'component',
                  'type' => 'sdc.canvas_test_sdc.image@fb40be57bd7e0973',
                  'name' => 'Magnificent image!',
                  'slots' => [],
                ],
              ],
            ],
          ],
        ],
      ], $region['components']);
    }

    self::assertIsArray($json);
    if ($entity instanceof NodeInterface) {
      $this->assertArrayHasKey('entity_form_fields', $json);
      $this->assertSame($node->label(), $json['entity_form_fields']['title[0][value]']);
    }

    self::assertEquals([
      'resolved' => [
        'heading' => 'Canvas Needs This For The Time Being',
        'cta1href' => 'https://drupal.org',
      ],
      'source' => [
        'heading' => [
          'sourceType' => 'static:field_item:string',
          'expression' => 'ℹ︎string␟value',
        ],
        'cta1href' => [
          'sourceType' => 'static:field_item:link',
          'value' => [
            'uri' => 'https://drupal.org',
            'options' => [],
          ],
          'expression' => 'ℹ︎link␟url',
          'sourceTypeSettings' => [
            'instance' => [
              'title' => LinkTitleVisibility::Disabled->value,
              'link_type' => LinkItemInterface::LINK_GENERIC,
            ],
          ],
        ],
      ],
    ], $json['model'][CanvasTestSetup::UUID_STATIC_CARD2]);
    return $node;
  }

  public function testStatusFlags(): void {
    $this->setUpCurrentUser(permissions: [Page::CREATE_PERMISSION, Page::EDIT_PERMISSION]);

    $request = Request::create('/canvas/api/v0/content/canvas_page', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['clientInstanceId' => 'test-123'], JSON_THROW_ON_ERROR));
    $content = $this->parentRequest($request)->getContent();

    self::assertIsString($content);
    $entity_id = (int) json_decode($content, TRUE)['entity_id'];
    $entity = Page::load($entity_id);
    self::assertInstanceOf(Page::class, $entity);
    $this->assertStatusFlags($entity, TRUE, FALSE, FALSE);

    $entity->set('title', 'Here we go')->save();
    $this->assertStatusFlags($entity, FALSE, FALSE, FALSE);

    $entity->setPublished()->save();
    $this->assertStatusFlags($entity, FALSE, TRUE, FALSE);

    $contentTemplate = $this->getTestEntity(ContentTemplate::ENTITY_TYPE_ID);
    \assert($contentTemplate instanceof ContentTemplate);
    self::assertFalse($contentTemplate->status());
    $this->setUpCurrentUser([], [self::getAdminPermission($contentTemplate)]);
    $this->assertStatusFlags($contentTemplate, TRUE, NULL, NULL);

    $contentTemplate->setStatus(TRUE)->save();
    $this->assertStatusFlags($contentTemplate, FALSE, NULL, NULL);
  }

  /**
   * Tests hasUnsavedStatusChange flag for unpublish/publish operations.
   */
  public function testHasUnsavedStatusChange(): void {
    $this->setUpCurrentUser(permissions: [Page::CREATE_PERMISSION, Page::EDIT_PERMISSION]);
    $autoSaveManager = $this->container->get(AutoSaveManager::class);
    \assert($autoSaveManager instanceof AutoSaveManager);

    // Published page unpublished via auto-save.
    $published_page = Page::create([
      'title' => 'Published Page',
      'status' => TRUE,
    ]);
    $published_page->save();
    $this->assertStatusFlags($published_page, FALSE, TRUE, FALSE);

    // Unpublish the published page via auto-save.
    $published_page->setUnpublished();
    $autoSaveManager->saveEntity($published_page);
    // isNew=FALSE (not a draft), isPublished=FALSE (auto-saved unpublished),
    // hasUnsavedStatusChange=TRUE (auto-save has different status than original).
    $this->assertStatusFlags($published_page, FALSE, FALSE, TRUE);

    // Reverting unpublish operation.
    $published_page->setPublished();
    $autoSaveManager->saveEntity($published_page);
    // hasUnsavedStatusChange should be FALSE when auto-save matches original.
    $this->assertStatusFlags($published_page, FALSE, TRUE, FALSE);

    // Unpublished page (published then unpublished).
    $unpublished_page = Page::create([
      'title' => 'Unpublished Page',
      'status' => TRUE,
    ]);
    $unpublished_page->save();
    $unpublished_page->setNewRevision(TRUE);
    $unpublished_page->setUnpublished();
    $unpublished_page->save();
    // Unpublished page without auto-save should not have unsaved status change.
    $this->assertStatusFlags($unpublished_page, FALSE, FALSE, FALSE);

    // Publishing unpublished page via auto-save.
    $unpublished_page->setPublished();
    $autoSaveManager->saveEntity($unpublished_page);
    // isNew=FALSE (not a draft, was published before),
    // isPublished=TRUE (auto-saved published),
    // hasUnsavedStatusChange=TRUE (auto-save has different status than original).
    $this->assertStatusFlags($unpublished_page, FALSE, TRUE, TRUE);

    // Non-status field changes don't trigger hasUnsavedStatusChange.
    $test_page = Page::create([
      'title' => 'Test Page',
      'status' => TRUE,
    ]);
    $test_page->save();
    $this->assertStatusFlags($test_page, FALSE, TRUE, FALSE);

    // Change only the title via auto-save (no status change).
    $test_page->set('title', 'Updated Title');
    $autoSaveManager->saveEntity($test_page);
    // hasUnsavedStatusChange should still be FALSE.
    $this->assertStatusFlags($test_page, FALSE, TRUE, FALSE);

    // Clearing auto-save resets hasUnsavedStatusChange.
    $test_page->setUnpublished();
    $autoSaveManager->saveEntity($test_page);
    $this->assertStatusFlags($test_page, FALSE, FALSE, TRUE);

    $autoSaveManager->delete($test_page);
    $this->assertStatusFlags($test_page, FALSE, TRUE, FALSE);

    // Draft page with auto-saved published status.
    $draft_page = Page::create([
      'title' => self::NEW_PAGE_TITLE,
      'status' => FALSE,
    ]);
    $draft_page->save();
    $this->assertStatusFlags($draft_page, TRUE, FALSE, FALSE);

    // Set published status in auto-save for draft.
    $draft_page->setPublished();
    $autoSaveManager->saveEntity($draft_page);
    // isNew=TRUE (still a draft, never truly published),
    // isPublished=TRUE (auto-saved published),
    // hasUnsavedStatusChange=TRUE (auto-save has different status than original).
    $this->assertStatusFlags($draft_page, TRUE, TRUE, TRUE);
  }

  /**
   * Tests the `autoSaved` query argument in layout GET for Page entities.
   */
  #[TestWith([FALSE, self::PUBLISHED_PAGE_TITLE])]
  #[TestWith([TRUE, self::AUTO_SAVE_PAGE_TITLE])]
  public function testGetWithAutoSavedQueryArgument(
    bool $auto_saved,
    string $expected_title,
  ): void {
    // The `autoSaved` query argument and `updated` response property are gated
    // behind the `canvas_dev_cd` module.
    // @todo Remove in https://git.drupalcode.org/project/canvas/-/work_items/3591732
    $this->enableModules(['canvas_dev_cd']);

    $this->setUpCurrentUser(permissions: [Page::CREATE_PERMISSION, Page::EDIT_PERMISSION]);
    $autoSaveManager = $this->container->get(AutoSaveManager::class);
    \assert($autoSaveManager instanceof AutoSaveManager);

    $page = Page::create([
      'title' => self::PUBLISHED_PAGE_TITLE,
      'status' => TRUE,
      'components' => [],
    ]);
    $page->save();

    // Create an auto-save item that diverges from the published entity.
    $page->set('title', self::AUTO_SAVE_PAGE_TITLE);
    $page->setUnpublished();
    $autoSaveManager->saveEntity($page);
    $auto_save_entity = $autoSaveManager->getAutoSaveEntity($page);

    $url = $this->getLayoutUrl($page);
    $response = $this->request(Request::create(\sprintf(self::LAYOUT_GET_WITH_QUERY_ARGUMENT_URI_PATTERN, $url->toString(), ApiLayoutController::AUTO_SAVED_QUERY_KEY, ($auto_saved ? '1' : '0'))));
    self::assertSame(Response::HTTP_OK, $response->getStatusCode());
    $decodedResponse = static::decodeResponse($response);

    self::assertStringContainsString($expected_title, $decodedResponse['html']);
    self::assertSame($expected_title, $decodedResponse['entity_form_fields']['title[0][value]']);
    self::assertSame(!$auto_saved, $decodedResponse['isPublished']);
    self::assertSame($auto_saved, $decodedResponse['hasUnsavedStatusChange']);
    self::assertSame($auto_saved ? $auto_save_entity->updated : $page->getChangedTime(), $decodedResponse['updated']);
  }

  private function assertStatusFlags(EntityInterface $entity, bool $isNew, ?bool $isPublished, ?bool $hasUnsavedStatusChange = NULL): void {
    $content = $this->parentRequest(Request::create($this->getLayoutUrl($entity)->toString()))->getContent();
    self::assertIsString($content);
    $json = json_decode($content, TRUE);
    self::assertSame($isNew, $json['isNew']);
    self::assertSame($isPublished, $json['isPublished'] ?? NULL);
    if ($hasUnsavedStatusChange !== NULL) {
      self::assertSame($hasUnsavedStatusChange, $json['hasUnsavedStatusChange'] ?? FALSE);
    }
  }

  /**
   * Tests that auto-save entries with inaccessible fields do not cause errors.
   *
   * @legacy-covers \Drupal\canvas\Controller\ApiLayoutController::buildPreviewRenderable
   */
  public function testInaccessibleFieldsInAutoSave(): void {
    // Create a node to work with.
    $node = Node::create([
      'type' => 'article',
      'title' => 'Test Node',
    ]);
    $node->save();

    // Set up the current user without access to path field.
    $authenticated_role = $this->createRole(['edit any article content']);
    $limited_user = $this->createUser([], NULL, FALSE, ['roles' => [$authenticated_role]]);
    \assert($limited_user instanceof User);
    $this->setCurrentUser($limited_user);

    // Create an auto-save entry with a value for a field that the user doesn't have access to.
    $autoSave = $this->container->get(AutoSaveManager::class);
    \assert($autoSave instanceof AutoSaveManager);

    $node->set('path', ['alias' => '/test-path']);
    $autoSave->saveEntity($node);

    $url = Url::fromRoute('canvas.api.layout.get', [
      'entity' => $node->id(),
      'entity_type' => 'node',
    ]);

    // This should not throw an exception even though the auto-save data
    // contains a value for path field that the user doesn't have access to.
    $response = $this->request(Request::create($url->toString()));

    // Verify that the response is successful.
    self::assertEquals(Response::HTTP_OK, $response->getStatusCode());

    // Check that the response contains the correct title.
    $json = static::decodeResponse($response);
    self::assertArrayHasKey('entity_form_fields', $json);
    self::assertEquals('Test Node', $json['entity_form_fields']['title[0][value]']);
    $entity_form_fields = $json['entity_form_fields'];
    // Expand form values from their respective element name, e.g.
    // ['title[0][value]' => 'Node title'] becomes
    // ['title' => ['value' => 'Node title']].
    // @see \Drupal\canvas\Controller\ApiLayoutController::getEntityData
    \parse_str(\http_build_query($entity_form_fields), $entity_form_fields);
    self::assertArrayNotHasKey('path', $entity_form_fields);
  }

  public function testFieldException(): void {
    $page_type = NodeType::create([
      'type' => 'page',
      'name' => 'Page',
    ]);
    $page_type->save();
    $node = Node::create([
      'type' => 'page',
      'title' => 'Test',
    ]);
    $node->save();
    /** @var \Drupal\canvas\Controller\ApiLayoutController $controller */
    $controller = \Drupal::classResolver(ApiLayoutController::class);
    $this->expectException(\LogicException::class);
    // @todo Fix in https://drupal.org/i/3498525 for testing a bundle where a
    //   canvas field is not present.
    // @see \Drupal\canvas\Storage\ComponentTreeLoader::getCanvasFieldName
    $this->expectExceptionMessage('For now Canvas only works if the entity is a canvas_page! Other entity types and bundles must use content templates for now, see https://drupal.org/i/3498525');
    $request = Request::create('/api/canvas/content/canvas_page/' . $node->id());
    $controller->get(request: $request, entity: $node);
  }

  /**
   * Tests that an entity without Canvas support gets a 403, not a 500.
   *
   * When the routed entity does not support Canvas component tree editing
   * (e.g. a node bundle that has no Canvas field), ComponentTreeLoader::load()
   * throws a \LogicException. The access check must catch that and deny access,
   * so the request results in a 403 Forbidden rather than an uncaught
   * exception surfacing as a 500 Internal Server Error.
   *
   * @legacy-covers \Drupal\canvas\Access\ComponentTreeEditAccessCheck::access
   */
  public function testUnsupportedEntityAccessDenied(): void {
    NodeType::create([
      'type' => 'page',
      'name' => 'Page',
    ])->save();
    $node = Node::create([
      'type' => 'page',
      'title' => 'Test',
    ]);
    $node->save();
    // Grant entity update access so the only possible denial comes from the
    // component tree edit access check, not from missing entity update access.
    $this->setUpCurrentUser([], ['edit any page content']);

    $url = Url::fromRoute('canvas.api.layout.get', [
      'entity' => $node->id(),
      'entity_type' => 'node',
    ]);
    $this->expectException(AccessDeniedHttpException::class);
    $this->expectExceptionMessage('This entity does not support Canvas component tree editing.');
    $this->parentRequest(Request::create($url->toString()));
  }

  /**
   * @return \Drupal\canvas\Entity\PageRegion[]
   */
  protected function enableGlobalRegions(string $theme = 'stark', int $expected_region_count = 11): array {
    $this->container->get(ThemeInstallerInterface::class)->install([$theme]);
    $this->container->get(ConfigFactoryInterface::class)
      ->getEditable('system.theme')
      ->set('default', $theme)
      ->save();
    $this->container->get(ThemeManagerInterface::class)->resetActiveTheme();

    $regions = PageRegion::createFromBlockLayout($theme);
    // Check that all the theme regions get a corresponding PageRegion config
    // entity (except the "content" region).
    self::assertCount($expected_region_count, $regions);
    foreach ($regions as $region) {
      $region->save();
    }
    return $regions;
  }

  /**
   * Data provider for testFieldAccess.
   *
   * @return array[]
   *   Test data with permissions and expected results.
   */
  public static function fieldAccessProvider(): array {
    return [
      'no_permissions' => [
        'permissions' => ['access content'],
        'exception_message' => "The 'edit canvas_page' permission is required.",
      ],
      'entity_edit_only' => [
        'permissions' => [Page::EDIT_PERMISSION],
        'exception_message' => 'You do not have permission to edit this field.',
      ],
      'field_edit_only' => [
        // @see \canvas_test_field_access_entity_field_access()
        'permissions' => ['edit canvas page components'],
        'exception_message' => "The 'edit canvas_page' permission is required.",
      ],
      'both_permissions' => [
        'permissions' => [Page::EDIT_PERMISSION, 'edit canvas page components'],
        'exception_message' => NULL,
      ],
    ];
  }

  /**
   * Tests field access for the Drupal Canvas API layout.
   */
  #[DataProvider('fieldAccessProvider')]
  public function testFieldAccess(array $permissions, ?string $exception_message): void {
    $this->container->get(ModuleInstallerInterface::class)->install(['canvas_test_field_access']);
    $this->setUpCurrentUser([], $permissions);

    // Test field access using URL/request approach rather than directly calling controller
    // to ensure proper route resolution and access checking.
    $page = Page::create([
      'title' => 'Test page',
      'description' => 'This is a test page.',
      'components' => [
        [
          'uuid' => CanvasTestSetup::UUID_COMPONENT_SDC,
          'component_id' => 'sdc.canvas_test_sdc.props-slots',
          'inputs' => [
            'heading' => 'Welcome to the site!',
          ],
        ],
      ],
    ]);
    self::assertSame([], self::violationsToArray($page->validate()));
    $page->save();

    $url = Url::fromRoute('canvas.api.layout.get', [
      'entity' => $page->id(),
      'entity_type' => Page::ENTITY_TYPE_ID,
    ]);

    if ($exception_message !== NULL) {
      $this->expectException(AccessDeniedHttpException::class);
      $this->expectExceptionMessage($exception_message);
      $this->parentRequest(Request::create($url->toString()));
    }
    else {
      $response = $this->parentRequest(Request::create($url->toString()));
      $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
    }
  }

  /**
   * Tests preview entity validation.
   *
   * @legacy-covers \Drupal\canvas\Routing\ContentTemplatePreviewEntityConverter
   */
  public function testPreviewEntityValidation(): void {
    $this->setUpCurrentUser([], [ContentTemplate::ADMIN_PERMISSION]);
    $node = Node::create([
      'type' => 'article',
      'title' => $this->randomMachineName(),
    ]);
    self::assertCount(0, $node->validate());
    $node->save();
    NodeType::create([
      'type' => 'page',
      'name' => 'Page',
    ])->save();
    $ineligible_preview_node = Node::create([
      'type' => 'page',
      'title' => $this->randomMachineName(),
    ]);
    self::assertCount(0, $ineligible_preview_node->validate());
    $ineligible_preview_node->save();
    $contentTemplate = $this->getTestEntity(ContentTemplate::ENTITY_TYPE_ID);

    // Existing node ID, but of invalid bundle.
    $bad_preview_url = Url::fromRoute('canvas.api.layout.get.content_template', [
      'entity' => $contentTemplate->id(),
      'preview_entity' => $ineligible_preview_node->id(),
    ]);
    try {
      $this->request(Request::create($bad_preview_url->toString()));
      $this->fail('Expected exception not thrown');
    }
    catch (ParamNotConvertedException $e) {
      self::assertSame('The "preview_entity" parameter was not converted because the `node` content entity with ID 5 is of the bundle `page`, should be `article`.', $e->getMessage());
    }

    // Non-existing node ID.
    $bad_preview_url = Url::fromRoute('canvas.api.layout.get.content_template', [
      'entity' => $contentTemplate->id(),
      'preview_entity' => 42,
    ]);
    try {
      $this->request(Request::create($bad_preview_url->toString()));
      $this->fail('Expected exception not thrown');
    }
    catch (ParamNotConvertedException $e) {
      self::assertSame('The "preview_entity" parameter was not converted because a `node` content entity with ID 42 does not exist.', $e->getMessage());
    }

    $url = Url::fromRoute('canvas.api.layout.get.content_template', [
      'entity' => $contentTemplate->id(),
      'entity_type' => ContentTemplate::ENTITY_TYPE_ID,
      'preview_entity' => $node->id(),
    ]);

    // Ensure that the user must have 'view' access to the preview entity.
    $node->setUnpublished()->save();
    try {
      $this->request(Request::create($url->toString()));
      $this->fail('Expected exception not thrown');
    }
    catch (CacheableAccessDeniedHttpException) {
    }

    $node->setPublished()->save();
    $this->container->get(EntityTypeManagerInterface::class)->getAccessControlHandler('node')->resetCache();
    $response = $this->request(Request::create($url->toString()));
    $this->assertEquals(200, $response->getStatusCode(), 'Response status code is 200 OK');
  }

  /**
   * Data provider for testConfigTranslationAccessDenied.
   */
  public static function providerConfigTranslationAccessDenied(): array {
    return [
      'no_permissions' => [[]],
      'translate_config_only' => [['translate configuration']],
    ];
  }

  /**
   * Tests layout API denies access without ContentTemplate::ADMIN_PERMISSION.
   */
  #[DataProvider('providerConfigTranslationAccessDenied')]
  public function testConfigTranslationAccessDenied(array $permissions): void {
    $this->container->get(ModuleInstallerInterface::class)->install([
      'config_translation',
    ]);
    $template = $this->getTestEntity(ContentTemplate::ENTITY_TYPE_ID);
    $this->setUpCurrentUser([], $permissions);
    $this->expectException(AccessDeniedHttpException::class);
    $this->parentRequest(Request::create($this->getLayoutUrl($template)->toString()));
  }

  /**
   * Tests config translation handling for ContentTemplate in the layout API.
   *
   * Covers:
   * - Available translations includes languages that have a config language
   *   override on the ContentTemplate.
   * - The generated delete-form link points to
   *   canvas.api.config.translation.delete (not the content-translation route).
   * - No delete link is emitted for preview-entity content translations;
   *   those belong to the article node, not to the template.
   * - The delete link is gated on the 'translate configuration' permission
   *   that protects the delete route.
   * - The entire block is skipped when config_translation is not installed,
   *    so config-override languages disappear from available entirely.
   */
  public function testConfigTranslationAvailabilityLinksAndPermissions(): void {
    $this->container->get(ModuleInstallerInterface::class)->install([
      'language',
      'config_translation',
      'content_translation',
    ]);

    ConfigurableLanguage::createFromLangcode('fr')->save();
    ConfigurableLanguage::createFromLangcode('es')->save();

    // Add a Spanish content translation to the preview entity. This must
    // NOT surface in translations.available for a ContentTemplate – the
    // language selector only reflects the template's own config overrides.
    $node = Node::load(1);
    \assert($node instanceof Node);
    $node->addTranslation('es', ['title' => 'Title in Spanish', 'status' => 1]);
    $node->save();

    // Add a French config language override to the ContentTemplate.
    $template = $this->getTestEntity(ContentTemplate::ENTITY_TYPE_ID);
    \assert($template instanceof ContentTemplate);
    $languageManager = $this->container->get(LanguageManagerInterface::class);
    \assert($languageManager instanceof ConfigurableLanguageManagerInterface);
    $override = $languageManager->getLanguageConfigOverride('fr', $template->getConfigDependencyName());
    $override->setData(['component_tree' => []])->save();

    $url = $this->getLayoutUrl($template);

    // ContentTemplate::ADMIN_PERMISSION is required to access the endpoint.
    // Neither no permissions nor 'translate configuration' alone is sufficient.
    // @see testConfigTranslationAccessDenied()
    $this->setUpCurrentUser([], [ContentTemplate::ADMIN_PERMISSION, 'translate configuration']);

    $json = static::decodeResponse($this->request(Request::create($url->toString())));

    self::assertContains('fr', $json['translations']['available'],
      'French config override is listed as available.');

    self::assertNotContains('es', $json['translations']['available'],
      'Preview-entity content translation for Spanish is not listed for a ContentTemplate.');

    self::assertArrayHasKey('fr', $json['translations']['links'],
      'Delete link is emitted for the French config override.');
    $deleteLink = $json['translations']['links']['fr'][CanvasUriDefinitions::LINK_REL_DELETE];
    $expectedDeleteLink = Url::fromRoute('canvas.api.config.translation.delete', [
      'canvas_config_entity_type_id' => 'content_template',
      'config_entity' => $template->id(),
    ], ['language' => $languageManager->getLanguage('fr')])->toString();
    self::assertSame($expectedDeleteLink, $deleteLink,
      'Delete link points to canvas.api.config.translation.delete for the French override.');

    self::assertArrayNotHasKey('es', $json['translations']['links'],
      'No delete link is emitted for preview-entity content translations.');

    // Without 'translate configuration', the language is still listed in
    // available (so the selector shows it) but no delete link is emitted.
    $this->setUpCurrentUser([], [ContentTemplate::ADMIN_PERMISSION]);
    $json = static::decodeResponse($this->request(Request::create($url->toString())));
    self::assertContains('fr', $json['translations']['available'],
      'French is still available without translate configuration.');
    self::assertArrayNotHasKey('fr', $json['translations']['links'],
      'No delete link without translate configuration permission.');

    // Granting the permission brings the delete link back.
    $this->setUpCurrentUser([], [ContentTemplate::ADMIN_PERMISSION, 'translate configuration']);
    $json = static::decodeResponse($this->request(Request::create($url->toString())));
    self::assertArrayHasKey('fr', $json['translations']['links'],
      'Delete link is present again once translate configuration is granted.');

    // When config_translation is uninstalled the detection block is skipped
    // entirely, so French must also disappear from available.
    $this->container->get(ModuleInstallerInterface::class)->uninstall(['config_translation']);
    $this->setUpCurrentUser([], [ContentTemplate::ADMIN_PERMISSION]);
    $json = static::decodeResponse($this->request(Request::create($url->toString())));
    self::assertNotContains('fr', $json['translations']['available'],
      'French is absent from available when config_translation is uninstalled.');
    self::assertArrayNotHasKey('fr', $json['translations']['links'],
      'Delete link is absent when config_translation is not installed.');
  }

}
