<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Http\Exception\CacheableAccessDeniedHttpException;
use Drupal\Core\ParamConverter\ParamNotConvertedException;
use Drupal\Core\Url;
use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\Entity\ContentTemplate;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\canvas\TestSite\CanvasTestSetup;
use Drupal\Tests\canvas\Traits\AutoSaveRequestTestTrait;
use Drupal\Tests\canvas\Traits\CanvasFieldTrait;
use Drupal\Tests\canvas\Traits\ContribStrictConfigSchemaTestTrait;
use Symfony\Component\HttpFoundation\Request;

/**
 * @todo Currently this adds minimal testing of
 *   \Drupal\canvas\Controller\ApiLayoutController when used with
 *   ContentTemplate entities. The existing ApiLayoutController*Test classes
 *   should be refactored cover ContentTemplate entities in
 *   https://drupal.org/i/3543834.
 * @coversDefaultClass  \Drupal\canvas\Controller\ApiLayoutController
 * @group canvas
 */
class ApiLayoutControllerContentTemplateTest extends ApiLayoutControllerTestBase {

  use AutoSaveRequestTestTrait;
  use ContribStrictConfigSchemaTestTrait;
  use CanvasFieldTrait;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    (new CanvasTestSetup())->setup();
  }

  public function test(): void {
    $this->setUpCurrentUser([], [ContentTemplate::ADMIN_PERMISSION]);
    $node = Node::create([
      'type' => 'article',
      'title' => $this->randomMachineName(),
    ]);
    self::assertCount(0, $node->validate());
    $node->save();
    $autoSaveManager = $this->container->get(AutoSaveManager::class);
    \assert($autoSaveManager instanceof AutoSaveManager);

    $contentTemplate = ContentTemplate::create([
      'content_entity_type_id' => 'node',
      'content_entity_type_bundle' => 'article',
      'content_entity_type_view_mode' => 'full',
      'component_tree' => [],
    ]);
    $contentTemplate->save();

    $url = Url::fromRoute('canvas.api.layout.get.content_template', [
      'entity' => $contentTemplate->id(),
      'preview_entity' => $node->id(),
    ]);
    $response = $this->request(Request::create($url->toString()));
    $this->assertEquals(200, $response->getStatusCode(), 'Response status code is 200 OK');
    $decoded = $this->decodeResponse($response);
    self::assertArrayHasKey('layout', $decoded);
    self::assertArrayHasKey('model', $decoded);

    $decoded['layout'] = [
      [
        'nodeType' => 'region',
        'id' => 'content',
        'name' => 'Content',
        'components' => [
          [
            'uuid' => 'e1f6fbca-e331-4506-9dba-5734194c1e59',
            'nodeType' => 'component',
            'type' => 'sdc.canvas_test_sdc.props-no-slots@95f4f1d5ee47663b',
            'name' => NULL,
            'slots' => [],
          ],
        ],
      ],
    ];
    $decoded['model'] = [
      'e1f6fbca-e331-4506-9dba-5734194c1e59' => [
        'source' => [
          'heading' => [
            'sourceType' => 'static:field_item:string',
            'expression' => 'ℹ︎string␟value',
          ],
        ],
        'resolved' => [
          'heading' => 'Canvas is large and in charge!',
        ],
      ],
      'entity_form_fields' => [],
    ];

    $request = Request::create($url->toString(), 'POST', [], [], [], [], $this->filterLayoutForPost((string) json_encode($decoded)));
    $request->headers->set('Content-Type', 'application/json');
    $response = $this->request($request);
    $this->assertEquals(200, $response->getStatusCode(), 'Response status code is 200 OK');
    // Make GET request again to confirm the component was saved.
    $response = $this->request(Request::create($url->toString()));
    $this->assertEquals(200, $response->getStatusCode(), 'Response status code is 200 OK');
    $decoded = $this->decodeResponse($response);
    self::assertCount(1, $decoded['layout'][0]['components'], 'Layout has 1 components');
    self::assertArrayHasKey('e1f6fbca-e331-4506-9dba-5734194c1e59', $decoded['model'], 'Model has component with UUID e1f6fbca-e331-4506-9dba-5734194c1e59');

    $autoSaveTemplate = $autoSaveManager->getAutoSaveEntity($contentTemplate)->entity;
    self::assertInstanceOf(ContentTemplate::class, $autoSaveTemplate);
    // Confirm we only have 1 component.
    self::assertCount(1, $autoSaveTemplate->getComponentTree());

    // Now let's try to add a new component.
    $newComponent = [
      'uuid' => 'd1f6fbca-e331-4506-9dba-5734194c1e59',
      'nodeType' => 'component',
      'type' => 'sdc.canvas_test_sdc.props-no-slots@95f4f1d5ee47663b',
      'name' => NULL,
      'slots' => [],
    ];
    $decoded['layout'][0]['components'][] = $newComponent;
    $decoded['model']['d1f6fbca-e331-4506-9dba-5734194c1e59'] = [
      'source' => [
        'heading' => [
          'sourceType' => 'static:field_item:string',
          'expression' => 'ℹ︎string␟value',
        ],
      ],
      'resolved' => [
        'heading' => 'New component added!',
      ],
    ];
    unset($decoded['autoSaves']);
    $decoded += $this->getPostContentsDefaults($contentTemplate);
    $request = Request::create($url->toString(), 'POST', [], [], [], [], $this->filterLayoutForPost((string) json_encode($decoded)));
    $request->headers->set('Content-Type', 'application/json');
    $response = $this->request($request);
    $this->assertEquals(200, $response->getStatusCode(), 'Response status code is 200 OK');
    $decoded = $this->decodeResponse($response);
    self::assertStringContainsString('New component added!', $decoded['html'], 'Response HTML contains the new component heading');
    $autoSaveTemplate = $autoSaveManager->getAutoSaveEntity($autoSaveTemplate)->entity;
    self::assertInstanceOf(ContentTemplate::class, $autoSaveTemplate);
    // Confirm the new component was added to the auto-save template.
    self::assertCount(2, $autoSaveTemplate->getComponentTree());

    // First do a GET request to get the current state of the template.
    $response = $this->request(Request::create($url->toString()));
    $this->assertEquals(200, $response->getStatusCode(), 'Response status code is 200 OK');
    $decoded = $this->decodeResponse($response);
    $newModel = $decoded['model']['d1f6fbca-e331-4506-9dba-5734194c1e59'];
    $newModel['resolved']['heading'] = 'Patched heading!';
    $updateNewComponentData = [
      'model' => $newModel,
      'componentType' => 'sdc.canvas_test_sdc.props-no-slots@95f4f1d5ee47663b',
      'componentInstanceUuid' => 'd1f6fbca-e331-4506-9dba-5734194c1e59',
        // 'autoSaves'  => $decoded['autoSaves'],
    ] + $this->getPatchContentsDefaults([$contentTemplate]);
    $request = Request::create($url->toString(), 'PATCH', [], [], [], [], (string) json_encode($updateNewComponentData));
    $request->headers->set('Content-Type', 'application/json');
    $response = $this->request($request);
    $this->assertEquals(200, $response->getStatusCode(), 'Response status code is 200 OK');
    $decoded = $this->decodeResponse($response);
    self::assertStringContainsString('Patched heading!', $decoded['html'], 'Response HTML contains the new component heading');

    $autoSaveTemplate = $autoSaveManager->getAutoSaveEntity($autoSaveTemplate)->entity;
    self::assertInstanceOf(ContentTemplate::class, $autoSaveTemplate);
    // Assert change got in the auto-save template.
    self::assertSame('Patched heading!', $autoSaveTemplate->getComponentTree()->get(1)?->getValue()['inputs']['heading']);

    // Assert that we can publish the template.
    // Confirm the saved template still has no components.
    $loadedTemplate = ContentTemplate::load($contentTemplate->id());
    self::assertInstanceOf(ContentTemplate::class, $loadedTemplate);
    self::assertCount(0, $loadedTemplate->getComponentTree());
    $this->setUpCurrentUser(
      [],
      [
        ContentTemplate::ADMIN_PERMISSION,
        AutoSaveManager::PUBLISH_PERMISSION,
        'edit any article content',
      ]
    );
    $response = $this->makePublishAllRequest();
    $this->assertEquals(200, $response->getStatusCode(), 'Response status code is 200');
    // Confirm the saved template now has 2 components.
    $loadedTemplate = ContentTemplate::load($contentTemplate->id());
    self::assertInstanceOf(ContentTemplate::class, $loadedTemplate);
    self::assertCount(2, $loadedTemplate->getComponentTree());
  }

  /**
   * @covers \Drupal\canvas\Routing\ContentTemplatePreviewEntityConverter
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
    $contentTemplate = ContentTemplate::create([
      'content_entity_type_id' => 'node',
      'content_entity_type_bundle' => 'article',
      'content_entity_type_view_mode' => 'full',
      'component_tree' => [],
    ]);
    $contentTemplate->save();

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

}
