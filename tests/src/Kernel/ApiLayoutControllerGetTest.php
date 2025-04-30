<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Kernel;

use Drupal\Core\Url;
use Drupal\experience_builder\AutoSave\AutoSaveManager;
use Drupal\experience_builder\Controller\ApiLayoutController;
use Drupal\experience_builder\Entity\Page;
use Drupal\experience_builder\Entity\PageRegion;
use Drupal\experience_builder\Plugin\DisplayVariant\XbPageVariant;
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

/**
 * @covers \Drupal\experience_builder\Controller\ApiLayoutController::get()
 * @group experience_builder
 */
class ApiLayoutControllerGetTest extends ApiLayoutControllerTestBase {

  use RequestTrait;
  use UserCreationTrait;

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
    $this->setUpCurrentUser([], ['access administration pages']);
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
  }

  public function test(): void {
    // By default, there is only the "content" region in the client-side
    // representation.
    $node = $this->assertRegions(1);
    /** @var \Drupal\experience_builder\AutoSave\AutoSaveManager $autoSave */
    $autoSave = $this->container->get(AutoSaveManager::class);
    self::assertTrue($autoSave->getAutoSaveData($node)->isEmpty());
    $regions = $this->enableGlobalRegions();

    // … but the corresponding client-side representation contains only the
    // "content" region unless it has permissions to edit the global regions.
    $this->assertRegions(1);

    $this->setUpCurrentUser([], ['access administration pages', PageRegion::ADMIN_PERMISSION]);

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
          "type" => "block.page_title_block",
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
    $autoSave->save($regions['stark.highlighted'], $layoutData);
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
    self::assertArrayHasKey('layout', $json);
    $highlightedRegion = \array_filter($json['layout'], static fn (array $region) => ($region['id'] ?? NULL) === 'highlighted');
    self::assertCount(1, $highlightedRegion);
    self::assertArrayHasKey('model', $json);
    self::assertArrayHasKey('c3f3c22c-c22e-4bb6-ad16-635f069148e4', $json['model']);
    self::assertEquals('Page title', $json['model']['c3f3c22c-c22e-4bb6-ad16-635f069148e4']['label']);
    self::assertEquals([
      [
        "nodeType" => "component",
        "slots" => [],
        "type" => "block.page_title_block",
        "uuid" => "c3f3c22c-c22e-4bb6-ad16-635f069148e4",
      ],
    ], reset($highlightedRegion)['components']);

    // Now let's add an auto-save entry for the node.
    $sampleData = \file_get_contents(\dirname(__DIR__, 3) . '/ui/tests/fixtures/layout-default.json');
    self::assertNotFalse($sampleData);
    $data = \json_decode($sampleData, TRUE);
    // Remove the adapted image.
    unset($data['model']['static-image-static-imageStyle-something7d']);
    unset($data['layout'][0]['components'][3]);
    $data['layout'][0]['components'] = \array_values($data['layout'][0]['components']);
    // Update the page title.
    $new_title = $this->getRandomGenerator()->sentences(10);
    $data['entity_form_fields']['title[0][value]'] = $new_title;
    $data['entity_form_fields']['status[value]'] = (string) TRUE;
    $node1 = Node::load(1);
    \assert($node1 instanceof NodeInterface);
    $autoSave->save($node1, $data);
    $response = $this->request(Request::create($url->toString()));

    self::assertInstanceOf(JsonResponse::class, $response);
    $json = \json_decode($response->getContent() ?: '', TRUE);
    self::assertArrayHasKey('layout', $json);
    $highlightedRegion = \array_filter($json['layout'], static fn (array $region) => ($region['id'] ?? NULL) === 'highlighted');
    self::assertCount(1, $highlightedRegion);
    self::assertArrayHasKey('model', $json);
    self::assertArrayHasKey('c3f3c22c-c22e-4bb6-ad16-635f069148e4', $json['model']);
    self::assertEquals('Page title', $json['model']['c3f3c22c-c22e-4bb6-ad16-635f069148e4']['label']);
    self::assertEquals([
      [
        "nodeType" => "component",
        "slots" => [],
        "type" => "block.page_title_block",
        "uuid" => "c3f3c22c-c22e-4bb6-ad16-635f069148e4",
      ],
    ], reset($highlightedRegion)['components']);
    self::assertEquals($new_title, $json['entity_form_fields']['title[0][value]']);

    // Now let's remove the draft of the page region but retain that of the
    // node.
    $autoSave->delete($regions['stark.highlighted']);
    // We should still see the global regions.
    $response = $this->request(Request::create($url->toString()));
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
        "type" => "block.page_title_block",
      ],
    ],
      // Filter out the UUID as that is added randomly by creating the block
      // in the setup class.
      \array_map(static fn(array $component) => \array_diff_key($component, \array_flip(['uuid'])), \current($highlightedRegion)['components']));
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
            "slots" => [],
            "type" => "block.page_title_block",
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
        $this->assertEquals([
          [
            "nodeType" => "component",
            "slots" => [],
            "type" => "block.system_messages_block",
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
          'nodeType' => 'component',
          'uuid' => 'two-column-uuid',
          'type' => 'sdc.experience_builder.two_column',
          'slots' => [
            [
              'nodeType' => 'slot',
              'id' => 'two-column-uuid/column_one',
              'name' => 'column_one',
              'components' => [
                [
                  'nodeType' => 'component',
                  'uuid' => 'static-image-udf7d',
                  'type' => 'sdc.experience_builder.image',
                  'slots' => [],
                ],
                [
                  'nodeType' => 'component',
                  'uuid' => 'static-static-card1ab',
                  'type' => 'sdc.experience_builder.my-hero',
                  'slots' => [],
                ],
                [
                  'nodeType' => 'component',
                  'uuid' => 'component-instance-with-all-slots-empty',
                  'type' => 'sdc.experience_builder.one_column',
                  'slots' => [
                    [
                      'nodeType' => 'slot',
                      'id' => 'component-instance-with-all-slots-empty/content',
                      'name' => 'content',
                      'components' => [],
                    ],
                  ],
                ],
              ],
            ],
            [
              'nodeType' => 'slot',
              'id' => 'two-column-uuid/column_two',
              'name' => 'column_two',
              'components' => [
                [
                  'nodeType' => 'component',
                  'uuid' => 'static-static-card2df',
                  'type' => 'sdc.experience_builder.my-hero',
                  'slots' => [],
                ],
                [
                  'nodeType' => 'component',
                  'uuid' => 'static-static-card3rr',
                  'type' => 'sdc.experience_builder.my-hero',
                  'slots' => [],
                ],
                [
                  'nodeType' => 'component',
                  'uuid' => 'static-image-static-imageStyle-something7d',
                  'type' => 'sdc.experience_builder.image',
                  'slots' => [],
                ],
              ],
            ],
          ],
        ],
      ], $region['components']);
    }

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
    ], $json['model']['static-static-card2df']);
    return $node;
  }

  public function testStatusFlags(): void {
    $this->setUpCurrentUser(permissions: ['access administration pages', Page::CREATE_PERMISSION]);

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
    $authenticated_role = $this->createRole(['access administration pages']);
    $limited_user = $this->createUser([], NULL, FALSE, ['roles' => [$authenticated_role]]);
    assert($limited_user instanceof User);
    $this->setCurrentUser($limited_user);

    // Create an auto-save entry with a value for a field that the user doesn't have access to.
    $autoSave = $this->container->get(AutoSaveManager::class);
    assert($autoSave instanceof AutoSaveManager);

    // Create test data with a field the user doesn't have access to (path field).
    $data = [
      'layout' => [
        [
          'nodeType' => 'region',
          'id' => 'content',
          'name' => 'Content',
          'components' => [],
        ],
      ],
      'model' => [],
      'entity_form_fields' => [
        'title[0][value]' => 'Test Node',
        // Path field that user doesn't have access to
        'path[0][alias]' => '/test-path',
      ],
    ];

    $autoSave->save($node, $data);

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

}
