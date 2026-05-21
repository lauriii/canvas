<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel;

use Drupal\canvas\Entity\ContentTemplate;
use Drupal\canvas\PropSource\PropSource;
use Drupal\Core\Url;
use Drupal\node\Entity\Node;
use Drupal\Tests\canvas\TestSite\CanvasTestSetup;
use League\OpenAPIValidation\PSR7\Exception\Validation\InvalidBody;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Tests the draft content template preview endpoint.
 *
 * @legacy-covers \Drupal\canvas\Controller\ApiLayoutController::draftContentTemplate
 */
#[RunTestsInSeparateProcesses]
#[Group('canvas')]
#[Group('#slow')]
class ApiLayoutControllerDraftContentTemplateTest extends ApiLayoutControllerTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'canvas_test_storable_prop_shape_alter',
    'sdc_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->container->get('module_installer')->install(['system']);
    (new CanvasTestSetup())->setup(TRUE);
    $this->setUpCurrentUser([], [ContentTemplate::ADMIN_PERMISSION, 'edit any article content']);
  }

  /**
   * Renders a draft component tree against a preview entity.
   */
  public function testRendersDraftWithEntityFieldPropSource(): void {
    $contentTemplate = ContentTemplate::load('node.article.full');
    self::assertNotNull($contentTemplate);
    $previewEntity = Node::load(1);
    self::assertNotNull($previewEntity);

    // The draft sends a brand-new component tree the server has never seen.
    $componentUuid = '5f71027b-d9d3-4f3d-8990-a6502c0ba676';
    $componentTree = [
      [
        'uuid' => $componentUuid,
        'component_id' => 'sdc.canvas_test_sdc.props-no-slots',
        'component_version' => 'b1e991f726a2a266',
        'inputs' => [
          'heading' => [
            'sourceType' => PropSource::EntityField->value,
            'expression' => 'ℹ︎␜entity:node:article␝title␞␟value',
          ],
        ],
      ],
    ];

    $url = Url::fromRoute('canvas.api.layout.content_template_draft', [
      'entity_type' => 'node',
      'preview_entity' => $previewEntity->id(),
    ]);
    $request = Request::create(
      $url->toString(),
      'POST',
      [],
      [],
      [],
      ['CONTENT_TYPE' => 'application/json'],
      json_encode([
        'bundle' => 'article',
        'viewMode' => 'full',
        'component_tree' => $componentTree,
      ], JSON_THROW_ON_ERROR),
    );

    $response = $this->request($request);
    self::assertInstanceOf(JsonResponse::class, $response);
    self::assertEquals(Response::HTTP_OK, $response->getStatusCode());

    $decoded = static::decodeResponse($response);
    self::assertSame(
      $previewEntity->label(),
      $decoded['model'][$componentUuid]['resolved']['heading'],
    );
  }

  /**
   * Rejects a draft whose bundle does not match the preview entity bundle.
   */
  public function testRejectsBundleMismatch(): void {
    $previewEntity = Node::load(1);
    self::assertNotNull($previewEntity);
    $url = Url::fromRoute('canvas.api.layout.content_template_draft', [
      'entity_type' => 'node',
      'preview_entity' => $previewEntity->id(),
    ]);
    $request = Request::create(
      $url->toString(),
      'POST',
      [],
      [],
      [],
      ['CONTENT_TYPE' => 'application/json'],
      json_encode([
        'bundle' => 'page',
        'viewMode' => 'full',
        'component_tree' => [],
      ], JSON_THROW_ON_ERROR),
    );

    $this->expectException(BadRequestHttpException::class);
    $this->expectExceptionMessageMatches('/does not match draft bundle/');
    $this->parentRequest($request);
  }

  /**
   * Rejects a draft missing required body fields.
   *
   * The OpenAPI request validator catches the missing required field before
   * the controller method runs.
   */
  public function testRejectsMissingBodyFields(): void {
    $previewEntity = Node::load(1);
    self::assertNotNull($previewEntity);
    $url = Url::fromRoute('canvas.api.layout.content_template_draft', [
      'entity_type' => 'node',
      'preview_entity' => $previewEntity->id(),
    ]);
    $request = Request::create(
      $url->toString(),
      'POST',
      [],
      [],
      [],
      ['CONTENT_TYPE' => 'application/json'],
      json_encode(['component_tree' => []], JSON_THROW_ON_ERROR),
    );

    $this->expectException(InvalidBody::class);
    $this->parentRequest($request);
  }

}
