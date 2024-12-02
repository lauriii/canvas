<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Kernel;

use Drupal\Core\Url;
use Drupal\experience_builder\Controller\ApiPublishAllController;
use Drupal\experience_builder\Controller\NotTheGoodAutoSaveTrait;
use Drupal\experience_builder\Plugin\DataType\ComponentTreeStructure;
use Drupal\node\Entity\Node;
use Drupal\Tests\experience_builder\TestSite\XBTestSetup;
use Drupal\Tests\experience_builder\Traits\XBFieldTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class ApiPublishAllControllerTest extends ExperienceBuilderTestBase {

  use NotTheGoodAutoSaveTrait;
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
    self::assertNoAutoSaveData();
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

    $this->setUpCurrentUser(permissions: ['access administration pages']);
    $validClientJson = $this->getValidClientJson();

    // Auto-save node 1.
    $response = $this->request(Request::create(Url::fromRoute('experience_builder.api.preview', [
      'entity_type' => 'node',
      'entity' => $node1->id(),
    ])->toString(), content: (string) json_encode($validClientJson)));
    self::assertEquals(Response::HTTP_OK, $response->getStatusCode());

    // Auto-save node 2 with only the heading.
    unset($validClientJson['model'][self::TEST_IMAGE_UUID]);
    unset($validClientJson['layout']['children'][1]);
    // And an invalid prop.
    $validClientJson['model'][self::TEST_HEADING_UUID]['style'] = 'flared';

    // \Drupal\experience_builder\Controller\ApiPreviewController will not work
    // with invalid data so we need to use the NotTheGoodAutoSaveTrait directly.
    // @todo Replace this with a call the auto-save service in
    //   https://drupal.org/i/3489743. In https://drupal.org/i/3485878 we could
    //   also replace this by using the 'experience_builder.api.preview'
    //   route as we do above.
    $request = Request::create('', 'POST', [], [], [], [], (string) json_encode($validClientJson));
    $this->doAutoSave($node2, $request);

    $controller = \Drupal::service(ApiPublishAllController::class);
    $response = $controller();
    self::assertInstanceOf(JsonResponse::class, $response);
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

    $response = $controller();
    self::assertInstanceOf(JsonResponse::class, $response);
    $json = json_decode($response->getContent() ?: '', TRUE);
    self::assertEquals(Response::HTTP_OK, $response->getStatusCode());
    self::assertEquals(['message' => 'Successfully published 2 items.'], $json);

    $this->assertValidJsonUpdateNode($node1);
    $this->assertNodeXbField(
      $node2,
      [
        'sdc.experience_builder.heading',
      ],
      [
        self::TEST_HEADING_UUID => [
          'text' => [
            'sourceType' => 'static:field_item:string',
            'value' => 'This is a random heading.',
            'expression' => 'ℹ︎string␟value',
            'sourceTypeSettings' => [
              'storage' => [],
              'instance' => [],
            ],
          ],
          'style' => [
            'sourceType' => 'static:field_item:list_string',
            'value' => 'primary',
            'expression' => 'ℹ︎list_string␟value',
            'sourceTypeSettings' => [
              'storage' => [
                'allowed_values' => [
                  [
                    'value' => 'primary',
                    'label' => 'primary',
                  ],
                  [
                    'value' => 'secondary',
                    'label' => 'secondary',
                  ],
                ],
              ],
              'instance' => [],
            ],
          ],
          'element' => [
            'sourceType' => 'static:field_item:list_string',
            'value' => 'h1',
            'expression' => 'ℹ︎list_string␟value',
            'sourceTypeSettings' => [
              'storage' => [
                'allowed_values' => [
                  [
                    'value' => 'div',
                    'label' => 'div',
                  ],
                  [
                    'value' => 'h1',
                    'label' => 'h1',
                  ],
                  [
                    'value' => 'h2',
                    'label' => 'h2',
                  ],
                  [
                    'value' => 'h3',
                    'label' => 'h3',
                  ],
                  [
                    'value' => 'h4',
                    'label' => 'h4',
                  ],
                  [
                    'value' => 'h5',
                    'label' => 'h5',
                  ],
                  [
                    'value' => 'h6',
                    'label' => 'h6',
                  ],
                ],
              ],
              'instance' => [],
            ],
          ],
        ],
      ]
    );
    // Ensure that after the nodes have been published their auto-save data is
    // removed.
    self::assertNoAutoSaveData();
  }

  protected static function assertNoAutoSaveData(): void {
    $controller = \Drupal::service(ApiPublishAllController::class);
    $response = $controller();
    self::assertInstanceOf(JsonResponse::class, $response);
    $json = json_decode($response->getContent() ?: '', TRUE);
    self::assertEquals(Response::HTTP_NO_CONTENT, $response->getStatusCode());
    self::assertEquals(['message' => 'No items to publish.'], $json);
  }

}
