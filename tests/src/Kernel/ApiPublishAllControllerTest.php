<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Kernel;

use Drupal\Core\Url;
use Drupal\experience_builder\AutoSave\AutoSaveManager;
use Drupal\experience_builder\Controller\ApiPendingChangesController;
use Drupal\experience_builder\Controller\ApiPublishAllController;
use Drupal\experience_builder\Controller\ErrorCodesEnum;
use Drupal\experience_builder\Plugin\DataType\ComponentTreeStructure;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\Tests\experience_builder\TestSite\XBTestSetup;
use Drupal\Tests\experience_builder\Traits\XBFieldTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\Tests\experience_builder\Kernel\Traits\RequestTrait;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class ApiPublishAllControllerTest extends KernelTestBase {

  use RequestTrait;
  use XBFieldTrait;
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->container->get('module_installer')->install(['system']);
    (new XBTestSetup())->setup();
    $this->setUpImages();
  }

  public function test(): void {
    /** @var \Drupal\experience_builder\AutoSave\AutoSaveManager $autoSave */
    $autoSave = \Drupal::service(AutoSaveManager::class);
    $this->setUpCurrentUser(permissions: ['access administration pages']);
    $this->assertNoAutoSaveData();
    $node1 = Node::create([
      'type' => 'article',
      'title' => '5 amazing uses for old toothbrushes',
      'field_xb_demo' => [
        'tree' => json_encode([
          ComponentTreeStructure::ROOT_UUID => [],
        ]),
        'props' => '{}',
      ],
    ]);
    self::assertSame(SAVED_NEW, $node1->save());
    $this->assertNodeXbField($node1, [], []);

    $node2 = Node::create([
      'type' => 'article',
      'title' => 'Are leg-warmers due for a comeback? These young designers are betting on it',
      'field_xb_demo' => [
        'tree' => json_encode([
          ComponentTreeStructure::ROOT_UUID => [],
        ]),
        'props' => '{}',
      ],
    ]);
    self::assertSame(SAVED_NEW, $node2->save());
    $this->assertNodeXbField($node2, [], []);

    $validClientJson = $this->getValidClientJson();

    // Auto-save node 1.
    $response = $this->request(Request::create(Url::fromRoute('experience_builder.api.preview', [
      'entity_type' => 'node',
      'entity' => $node1->id(),
    ])->toString(), content: (string) json_encode($validClientJson)));
    self::assertEquals(Response::HTTP_OK, $response->getStatusCode());

    // Auto-save node 2 with only the heading.
    unset($validClientJson['model'][self::TEST_IMAGE_UUID]);
    unset($validClientJson['layout']['components'][1]);
    // And an invalid prop.
    $validClientJson['model'][self::TEST_HEADING_UUID]['style'] = 'flared';

    // \Drupal\experience_builder\Controller\ApiPreviewController will not work
    // with invalid data so we need to use the NotTheGoodAutoSaveTrait directly.
    // @todo In https://drupal.org/i/3485878 we could also replace this by using
    //   the 'experience_builder.api.preview' route as we do above.
    $autoSave->save($node2, $validClientJson);

    $response = $this->makePublishAllRequest();
    $json = json_decode($response->getContent() ?: '', TRUE);
    self::assertEquals(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
    self::assertEquals([
      'errors' => [
        [
          'detail' => 'Does not have a value in the enumeration ["primary","secondary"]',
          'source' => [
            'pointer' => 'model.' . self::TEST_HEADING_UUID . '.style',
          ],
          'meta' => [
            'entity_type' => 'node',
            'entity_id' => $node2->id(),
            'label' => $node2->label(),
          ],
        ],
      ],
    ], $json);
    // Ensure that neither the valid nor invalid node gets updated if one is
    // invalid.
    $this->assertNodeXbField($node1, [], []);
    $this->assertNodeXbField($node2, [], []);

    // Fix the error.
    $validClientJson['model'][self::TEST_HEADING_UUID]['style'] = 'primary';
    $response = $this->request(Request::create(Url::fromRoute('experience_builder.api.preview', [
      'entity_type' => 'node',
      'entity' => $node2->id(),
    ])->toString(), content: (string) json_encode($validClientJson)));
    self::assertEquals(Response::HTTP_OK, $response->getStatusCode());

    $auto_save_data = $this->getAutoSaveStatesFromServer();
    $node1_auto_save_key = 'node:' . $node1->id() . ':en';
    self::assertArrayHasKey($node1_auto_save_key, $auto_save_data);

    // Make publish requests that have missing, extra, and out-dated auto-save
    // information.
    $missing_auto_save_data = $auto_save_data;
    unset($missing_auto_save_data[$node1_auto_save_key]);
    $response = $this->makePublishAllRequest($missing_auto_save_data);
    self::assertSame(Response::HTTP_CONFLICT, $response->getStatusCode());
    self::assertEquals([
      'errors' => [
      [
        'detail' => ErrorCodesEnum::MissingItemInPublishRequest->getMessage(),
        'source' => [
          'pointer' => $node1_auto_save_key,
        ],
        'code' => ErrorCodesEnum::MissingItemInPublishRequest->value,
        'meta' => [
          'entity_type' => 'node',
          'entity_id' => $node1->id(),
          'label' => $node1->label(),
        ],
      ],
      ],
    ], \json_decode($response->getContent() ?: '', TRUE, flags: JSON_THROW_ON_ERROR));

    $extra_auto_save_data = $auto_save_data;
    $extra_key = 'node:' . (((int) $node2->id()) + 1) . ':en';
    $extra_auto_save_data[$extra_key] = $auto_save_data[$node1_auto_save_key];
    $response = $this->makePublishAllRequest($extra_auto_save_data);
    self::assertSame(Response::HTTP_CONFLICT, $response->getStatusCode());
    self::assertEquals([
      'errors' => [
      [
        'detail' => ErrorCodesEnum::UnexpectedItemInPublishRequest->getMessage(),
        'source' => [
          'pointer' => $extra_key,
        ],
        'code' => ErrorCodesEnum::UnexpectedItemInPublishRequest->value,
      ],
      ],
    ], \json_decode($response->getContent() ?: '', TRUE, flags: JSON_THROW_ON_ERROR));

    $out_dated_auto_save_data = $auto_save_data;
    $out_dated_auto_save_data[$node1_auto_save_key]['data_hash'] = 'old-hash';
    $response = $this->makePublishAllRequest($out_dated_auto_save_data);
    self::assertSame(Response::HTTP_CONFLICT, $response->getStatusCode());
    self::assertEquals([
      'errors' => [
      [
        'detail' => ErrorCodesEnum::UnmatchedItemInPublishRequest->getMessage(),
        'source' => [
          'pointer' => $node1_auto_save_key,
        ],
        'code' => ErrorCodesEnum::UnmatchedItemInPublishRequest->value,
        'meta' => [
          'entity_type' => 'node',
          'entity_id' => $node1->id(),
          'label' => $node1->label(),
        ],
      ],
      ],
    ], \json_decode($response->getContent() ?: '', TRUE, flags: JSON_THROW_ON_ERROR));

    $response = $this->makePublishAllRequest();
    $json = json_decode($response->getContent() ?: '', TRUE);
    self::assertEquals(Response::HTTP_OK, $response->getStatusCode());
    self::assertEquals(['message' => 'Successfully published 2 items.'], $json);

    $this->assertValidJsonUpdateNode($node1);
    $this->assertNodeXbField(
      $node2,
      [
        'sdc.experience_builder.heading',
      ],
      [self::TEST_HEADING_UUID => $this->getValidConvertedProps()[self::TEST_HEADING_UUID]]
    );
    // Ensure that after the nodes have been published their auto-save data is
    // removed.
    $this->assertNoAutoSaveData();
  }

  protected function assertNoAutoSaveData(): void {
    $response = $this->makePublishAllRequest([]);
    $json = json_decode($response->getContent() ?: '', TRUE);
    self::assertEquals(Response::HTTP_NO_CONTENT, $response->getStatusCode());
    self::assertEquals(['message' => 'No items to publish.'], $json);
  }

  protected function makePublishAllRequest(?array $data = NULL): JsonResponse {
    if (is_null($data)) {
      $data = $this->getAutoSaveStatesFromServer();
    }
    $controller = \Drupal::service(ApiPublishAllController::class);
    $request = Request::create(
      Url::fromRoute('experience_builder.api.publish_all')->toString(),
      content: (string) json_encode($data)
    );
    return $controller($request);
  }

  protected function getAutoSaveStatesFromServer(): array {
    $auto_save_controller = \Drupal::service(ApiPendingChangesController::class);
    $response = $auto_save_controller();
    assert($response instanceof JsonResponse);
    $content = $response->getContent();
    assert(is_string($content));
    $auto_saves = json_decode($content, TRUE);
    return $auto_saves;
  }

}
