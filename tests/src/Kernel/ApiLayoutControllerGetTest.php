<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Kernel;

use Drupal\Core\Url;
use Drupal\experience_builder\AutoSave\AutoSaveManager;
use Drupal\experience_builder\Controller\ApiLayoutController;
use Drupal\experience_builder\Entity\Page;
use Drupal\experience_builder\Entity\PageRegion;
use Drupal\experience_builder\Plugin\DisplayVariant\XbPageVariant;
use Drupal\experience_builder\Plugin\Field\FieldType\ComponentTreeItemList;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;
use Drupal\Tests\experience_builder\Kernel\Traits\RequestTrait;
use Drupal\Tests\experience_builder\TestSite\XBTestSetup;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\Entity\User;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * @covers \Drupal\experience_builder\Controller\ApiLayoutController::get()
 * @group experience_builder
 * @group #slow
 */
class ApiLayoutControllerGetTest extends ApiLayoutControllerTestBase {

  use UserCreationTrait;
  use RequestTrait {
    request as parentRequest;
  }

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    // Allows format=uri to be stored using URI field type.
    'xb_test_storage_prop_shape_alter',
    'sdc_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->container->get('module_installer')->install(['system']);
    (new XBTestSetup())->setup();
    $this->setUpCurrentUser([], ['edit any article content']);
  }

  public function testEmpty(): void {
    $node = Node::create([
      'type' => 'article',
      'title' => $this->randomMachineName(),
    ]);
    $node->save();
    // Enable global regions.
    $regions = $this->enableGlobalRegions();
    foreach ($regions as $region) {
      // But let's make sure none of them have a component tree so we have an
      // empty model.
      $region->set('component_tree', [])->save();
    }
    $url = Url::fromRoute('experience_builder.api.layout.get', [
      'entity' => $node->id(),
      'entity_type' => 'node',
    ]);
    $response = $this->request(Request::create($url->toString()));
    self::assertEquals(Response::HTTP_OK, $response->getStatusCode());
    $this->assertResponseAutoSaves($response, [$node]);
  }

  public function test(): void {
    // By default, there is only the "content" region in the client-side
    // representation.
    $node = $this->assertRegions(1);
    /** @var \Drupal\experience_builder\AutoSave\AutoSaveManager $autoSave */
    $autoSave = $this->container->get(AutoSaveManager::class);
    self::assertTrue($autoSave->getAutoSaveEntity($node)->isEmpty());
    $regions = $this->enableGlobalRegions();

    // … but the corresponding client-side representation contains only the
    // "content" region unless it has permissions to edit the global regions.
    $this->assertRegions(1);

    $this->setUpCurrentUser([], ['edit any article content', PageRegion::ADMIN_PERMISSION]);

    // … and the corresponding client-side representation contains all regions
    // plus one more (the "content" region) once it has the required permission.
    $this->assertRegions(12);

    // Disable a PageRegion to make it non-editable, and check that only 11
    // regions are present in the client-side representation.
    $regions['stark.highlighted']->disable()->save();
    $this->assertRegions(11);

    // Store a draft region in the auto-save manager and confirm that is returned.
    $regions['stark.highlighted']->enable()->save();
    $layoutData = [
      'layout' => [
        [
          "nodeType" => "component",
          "slots" => [],
          "type" => "block.page_title_block@62af221149ae4887",
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
    $node1 = Node::load(1);
    \assert($node1 instanceof NodeInterface);
    $url = Url::fromRoute('experience_builder.api.layout.get', [
      'entity' => $node1->id(),
      'entity_type' => 'node',
    ]);

    // Draft of highlighted region in global template should be returned even if
    // there is no auto-save data for the node.
    $response = $this->request(Request::create($url->toString()));
    self::assertInstanceOf(JsonResponse::class, $response);
    $json = \json_decode($response->getContent() ?: '', TRUE);
    self::assertIsArray($json);
    self::assertArrayHasKey('html', $json);
    $this->setRawContent($json['html']);
    $this->assertTitle($node1->label() . ' | Drupal');
    $this->assertResponseAutoSaves($response, [$node1], TRUE);
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
        "type" => "block.page_title_block@62af221149ae4887",
        "uuid" => "c3f3c22c-c22e-4bb6-ad16-635f069148e4",
        'name' => NULL,
      ],
    ], reset($highlightedRegion)['components']);

    $node1 = Node::load(1);
    \assert($node1 instanceof NodeInterface);
    // Remove the adapted image.
    $tree = $node1->get('field_xb_demo');
    \assert($tree instanceof ComponentTreeItemList);
    $delta = $tree->getComponentTreeDeltaByUuid(XBTestSetup::UUID_ADAPTED_IMAGE);
    \assert($delta !== NULL);
    $tree->removeItem($delta);
    // Update the title.
    $new_title = $this->getRandomGenerator()->sentences(10);
    $node1->setTitle($new_title);
    // Note we use a string here.
    $node1->set('status', '1');

    $autoSave->saveEntity($node1);
    $response = $this->request(Request::create($url->toString()));
    $this->assertResponseAutoSaves($response, [$node1], TRUE);

    // Extract HTML from JSON response for title assertion
    self::assertInstanceOf(JsonResponse::class, $response);
    $json = \json_decode($response->getContent() ?: '', TRUE);
    self::assertArrayHasKey('html', $json);
    $this->setRawContent($json['html']);
    $this->assertTitle("$new_title | Drupal");

    self::assertIsArray($json);
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
        "type" => "block.page_title_block@62af221149ae4887",
        "uuid" => "c3f3c22c-c22e-4bb6-ad16-635f069148e4",
        'name' => NULL,
      ],
    ], reset($highlightedRegion)['components']);
    self::assertEquals($new_title, $json['entity_form_fields']['title[0][value]']);

    // Now let's remove the draft of the page region but retain that of the
    // node.
    $autoSave->delete($regions['stark.highlighted']);
    // We should still see the global regions.
    $response = $this->request(Request::create($url->toString()));
    $this->assertResponseAutoSaves($response, [$node1], TRUE);
    self::assertInstanceOf(JsonResponse::class, $response);
    $json = \json_decode($response->getContent() ?: '', TRUE);
    self::assertArrayHasKey('layout', $json);
    $highlightedRegion = \array_filter($json['layout'], static fn (array $region) => ($region['id'] ?? NULL) === 'highlighted');
    self::assertCount(1, $highlightedRegion);
    // @see \Drupal\Tests\experience_builder\TestSite\XBTestSetup::setup()
    self::assertEquals([
      [
        "nodeType" => "component",
        "slots" => [],
        "type" => "block.page_title_block@62af221149ae4887",
        'name' => NULL,
      ],
    ],
      // Filter out the UUID as that is added randomly by creating the block
      // in the setup class.
      \array_map(static fn(array $component) => \array_diff_key($component, \array_flip(['uuid'])), \current($highlightedRegion)['components']));

    // Test that saving the exact values as the stored/live node, no auto-saves
    // remain.
    $original_node = Node::load(1);
    assert($original_node instanceof Node);
    $autoSave->saveEntity($original_node);
    $response = $this->request(Request::create($url->toString()));
    $this->assertResponseAutoSaves($response, [$original_node], TRUE);
  }

  protected function assertRegions(int $count): NodeInterface {
    $node = Node::load(1);
    \assert($node instanceof NodeInterface);
    $url = Url::fromRoute('experience_builder.api.layout.get', [
      'entity' => $node->id(),
      'entity_type' => 'node',
    ]);
    // Draft of highlighted region in global template should be returned even if
    // there is no auto-save data for the node.
    $response = $this->request(Request::create($url->toString()));

    $this->assertInstanceOf(JsonResponse::class, $response);
    $json = json_decode($response->getContent() ?: '', TRUE);
    self::assertIsArray($json);
    $this->assertArrayHasKey('layout', $json);
    $this->assertCount($count, $json['layout']);
    self::assertArrayHasKey('html', $json);
    $content = $this->getRegion('content');
    $this->assertNotNull($content);

    foreach ($json['layout'] as $region) {
      $this->assertArrayHasKey('nodeType', $region);
      $this->assertSame('region', $region['nodeType']);
      $this->assertArrayHasKey('id', $region);
      $this->assertArrayHasKey('name', $region);
      $this->assertArrayHasKey('components', $region);

      if ($region['id'] === 'highlighted') {
        // @see \Drupal\Tests\experience_builder\TestSite\XBTestSetup::setup()
        $this->assertEquals([
          [
            "nodeType" => "component",
            'name' => NULL,
            "slots" => [],
            "type" => "block.page_title_block@62af221149ae4887",
          ],
        ],
          // Filter out the UUID as that is added randomly by creating the block
          // in the setup class.
          \array_map(static fn(array $component) => \array_diff_key($component, \array_flip(['uuid'])), $region['components']));
        continue;
      }
      if ($region['id'] === 'sidebar_first') {
        // @see \Drupal\Tests\experience_builder\TestSite\XBTestSetup::setup()
        // @see \Drupal\experience_builder\Entity\PageRegion::createFromBlockLayout()
        $this->assertSame([
          [
            "nodeType" => "component",
            "type" => "block.system_messages_block@b92f802cf68eb83e",
            'name' => NULL,
            "slots" => [],
          ],
        ],
          // Filter out the UUID as that is added randomly by creating the block
          // in the setup class.
          \array_map(static fn(array $component) => \array_diff_key($component, \array_flip(['uuid'])), $region['components']));
        continue;
      }
      if ($region['id'] !== XbPageVariant::MAIN_CONTENT_REGION) {
        $this->assertEmpty($region['components']);
        continue;
      }
      $this->assertSame('Content', $region['name']);
      $this->assertSame([
        [
          'uuid' => XBTestSetup::UUID_TWO_COLUMN_UUID,
          'nodeType' => 'component',
          'type' => 'sdc.xb_test_sdc.two_column@d99140cbd47c0b51',
          'name' => NULL,
          'slots' => [
            [
              'id' => XBTestSetup::UUID_TWO_COLUMN_UUID . '/column_one',
              'name' => 'column_one',
              'nodeType' => 'slot',
              'components' => [
                [
                  'uuid' => XBTestSetup::UUID_STATIC_IMAGE,
                  'nodeType' => 'component',
                  'type' => 'sdc.xb_test_sdc.image@c06e0be7dd131740',
                  'name' => NULL,
                  'slots' => [],
                ],
                [
                  'uuid' => XBTestSetup::UUID_STATIC_CARD1,
                  'nodeType' => 'component',
                  'type' => 'sdc.xb_test_sdc.my-hero@060b3f2620eb7f36',
                  'name' => NULL,
                  'slots' => [],
                ],
                [
                  'uuid' => XbTestSetup::UUID_CODE_COMPONENT,
                  'nodeType' => 'component',
                  'type' => 'js.test-code-component@b09d5da457224c5e',
                  'name' => NULL,
                  'slots' => [],
                ],
                [
                  'uuid' => XBTestSetup::UUID_ALL_SLOTS_EMPTY,
                  'nodeType' => 'component',
                  'type' => 'sdc.xb_test_sdc.one_column@836c8835c850cdc5',
                  'name' => NULL,
                  'slots' => [
                    [
                      'id' => XBTestSetup::UUID_ALL_SLOTS_EMPTY . '/content',
                      'name' => 'content',
                      'nodeType' => 'slot',
                      'components' => [],
                    ],
                  ],
                ],
              ],
            ],
            [
              'id' => XBTestSetup::UUID_TWO_COLUMN_UUID . '/column_two',
              'name' => 'column_two',
              'nodeType' => 'slot',
              'components' => [
                [
                  'uuid' => XBTestSetup::UUID_STATIC_CARD2,
                  'nodeType' => 'component',
                  'type' => 'sdc.xb_test_sdc.my-hero@060b3f2620eb7f36',
                  'name' => NULL,
                  'slots' => [],
                ],
                [
                  'uuid' => XBTestSetup::UUID_STATIC_CARD3,
                  'nodeType' => 'component',
                  'type' => 'sdc.xb_test_sdc.my-hero@060b3f2620eb7f36',
                  'name' => NULL,
                  'slots' => [],
                ],
                [
                  'uuid' => XBTestSetup::UUID_ADAPTED_IMAGE,
                  'nodeType' => 'component',
                  'type' => 'sdc.xb_test_sdc.image@c06e0be7dd131740',
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
    $this->assertArrayHasKey('entity_form_fields', $json);
    $this->assertSame($node->label(), $json['entity_form_fields']['title[0][value]']);

    self::assertEquals([
      'resolved' => [
        'heading' => $node->label(),
        'cta1href' => 'https://drupal.org',
      ],
      'source' => [
        'heading' => [
          'sourceType' => 'static:field_item:string',
          'expression' => 'ℹ︎string␟value',

        ],
        'cta1href' => [
          'sourceType' => 'static:field_item:uri',
          'expression' => 'ℹ︎uri␟value',
        ],
      ],
    ], $json['model'][XBTestSetup::UUID_STATIC_CARD2]);
    return $node;
  }

  public function testStatusFlags(): void {
    $this->setUpCurrentUser(permissions: [Page::CREATE_PERMISSION, Page::EDIT_PERMISSION]);

    $request = Request::create('/xb/api/v0/content/xb_page', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([], JSON_THROW_ON_ERROR));
    $content = $this->parentRequest($request)->getContent();

    self::assertIsString($content);
    $entity_id = (int) json_decode($content, TRUE)['entity_id'];
    $entity = Page::load($entity_id);
    self::assertInstanceOf(Page::class, $entity);
    $this->assertStatusFlags($entity_id, TRUE, FALSE);

    $entity->set('title', 'Here we go')->save();
    $this->assertStatusFlags($entity_id, FALSE, FALSE);

    $entity->setPublished()->save();
    $this->assertStatusFlags($entity_id, FALSE, TRUE);
  }

  private function assertStatusFlags(int $entity_id, bool $isNew, bool $isPublished): void {
    $content = $this->parentRequest(Request::create('/xb/api/v0/layout/xb_page/' . $entity_id))->getContent();
    self::assertIsString($content);
    $json = json_decode($content, TRUE);
    self::assertSame($isNew, $json['isNew']);
    self::assertSame($isPublished, $json['isPublished']);
  }

  /**
   * Tests that auto-save entries with inaccessible fields do not cause errors.
   *
   * @covers \Drupal\experience_builder\Controller\ApiLayoutController::buildPreviewRenderable
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
    assert($limited_user instanceof User);
    $this->setCurrentUser($limited_user);

    // Create an auto-save entry with a value for a field that the user doesn't have access to.
    $autoSave = $this->container->get(AutoSaveManager::class);
    assert($autoSave instanceof AutoSaveManager);

    $node->set('path', ['alias' => '/test-path']);
    $autoSave->saveEntity($node);

    $url = Url::fromRoute('experience_builder.api.layout.get', [
      'entity' => $node->id(),
      'entity_type' => 'node',
    ]);

    // This should not throw an exception even though the auto-save data
    // contains a value for path field that the user doesn't have access to.
    $response = $this->request(Request::create($url->toString()));

    // Verify that the response is successful.
    self::assertEquals(Response::HTTP_OK, $response->getStatusCode());

    // Check that the response contains the correct title.
    self::assertInstanceOf(JsonResponse::class, $response);
    $json = json_decode($response->getContent() ?: '', TRUE);
    self::assertArrayHasKey('entity_form_fields', $json);
    self::assertEquals('Test Node', $json['entity_form_fields']['title[0][value]']);
    $entity_form_fields = $json['entity_form_fields'];
    // Expand form values from their respective element name, e.g.
    // ['title[0][value]' => 'Node title'] becomes
    // ['title' => ['value' => 'Node title']].
    // @see \Drupal\experience_builder\Controller\ApiLayoutController::getEntityData
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
    /** @var \Drupal\experience_builder\Controller\ApiLayoutController $controller */
    $controller = \Drupal::classResolver(ApiLayoutController::class);
    $this->expectException(\LogicException::class);
    $this->expectExceptionMessage('For now XB only works if the entity is an xb_page or an article node! Other entity types and bundles must be tested before they are supported, to help see https://drupal.org/i/3493675.');
    $controller->get($node);
  }

  /**
   * @return \Drupal\experience_builder\Entity\PageRegion[]
   */
  protected function enableGlobalRegions(string $theme = 'stark', int $expected_region_count = 11): array {
    $this->container->get('theme_installer')->install([$theme]);
    $this->container->get('config.factory')
      ->getEditable('system.theme')
      ->set('default', $theme)
      ->save();
    $this->container->get('theme.manager')->resetActiveTheme();

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
        'exception_message' => "The 'edit xb_page' permission is required.",
      ],
      'entity_edit_only' => [
        'permissions' => [Page::EDIT_PERMISSION],
        'exception_message' => 'You do not have permission to edit this field.',
      ],
      'field_edit_only' => [
        // @see \xb_test_field_access_entity_field_access()
        'permissions' => ['edit xb page components'],
        'exception_message' => "The 'edit xb_page' permission is required.",
      ],
      'both_permissions' => [
        'permissions' => [Page::EDIT_PERMISSION, 'edit xb page components'],
        'exception_message' => NULL,
      ],
    ];
  }

  /**
   * Tests field access for the Experience Builder API layout.
   *
   * @dataProvider fieldAccessProvider
   */
  public function testFieldAccess(array $permissions, ?string $exception_message): void {
    $this->container->get('module_installer')->install(['xb_test_field_access']);
    $this->setUpCurrentUser([], $permissions);

    // Test field access using URL/request approach rather than directly calling controller
    // to ensure proper route resolution and access checking.
    $page = Page::create([
      'title' => 'Test page',
      'description' => 'This is a test page.',
      'components' => [
        [
          'uuid' => XBTestSetup::UUID_COMPONENT_SDC,
          'component_id' => 'sdc.xb_test_sdc.props-slots',
          'inputs' => [
            'heading' => [
              'sourceType' => 'static:field_item:string',
              'value' => 'Welcome to the site!',
              'expression' => 'ℹ︎string␟value',
            ],
          ],
        ],
      ],
    ]);
    $page->save();

    $url = Url::fromRoute('experience_builder.api.layout.get', [
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

}
