<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\Controller;

use Drupal\canvas\CanvasUriDefinitions;
use Drupal\canvas\ComponentSource\ComponentSourceManager;
use Drupal\canvas\Controller\ApiContentControllers;
use Drupal\canvas\Entity\Page;
use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\canvas\Kernel\Traits\RequestTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use League\OpenAPIValidation\PSR7\Exception\Validation\InvalidBody;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Tests the ApiContentControllers::post() method.
*/
#[RunTestsInSeparateProcesses]
#[Group('canvas')]
#[CoversClass(ApiContentControllers::class)]
#[CoversMethod(ApiContentControllers::class, 'post')]
class ApiContentControllersPostTest extends CanvasKernelTestBase {

  use UserCreationTrait;
  use RequestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'canvas_test_page',
    'field',
  ];

  private const string URL = '/canvas/api/v0/content/canvas_page';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('canvas_page');
    $this->installEntitySchema('path_alias');
    $this->installEntitySchema('media');
    $this->installConfig(['system', 'field', 'filter', 'path_alias']);

    $this->setUpCurrentUser([], ['access content', Page::CREATE_PERMISSION, Page::EDIT_PERMISSION]);

    $component_source_manager = \Drupal::service(ComponentSourceManager::class);
    \assert($component_source_manager instanceof ComponentSourceManager);
    $component_source_manager->generateComponents();

    Page::create([
      'title' => 'A pre-existing page that can be duplicated',
      'components' => [],
      'status' => TRUE,
      'path' => ['alias' => '/preexisting-page'],
    ])->save();
  }

  /**
   * Tests get() returns a page with its component tree.
   *
   * @legacy-covers \Drupal\canvas\Controller\ApiContentControllers::get
   */
  #[DataProvider('providerPost')]
  public function testPost(array $page_contents, array $expected_response_contents): void {
    $content = \json_encode($page_contents, JSON_THROW_ON_ERROR);
    $response = $this->request(
      Request::create(
        self::URL,
        'POST',
        server: ['CONTENT_TYPE' => 'application/json'],
        content: $content,
      ),
    );
    // The response of a POST request shouldn't be cacheable.
    \assert($response instanceof JsonResponse && !$response instanceof CacheableJsonResponse);

    $data = $this->decodeResponse($response);

    // Versioned public APIs need to be strict: this means asserting
    // that we get all the expected info, but also NO extra additions.
    // So we use `assertSame` in the full response contents.
    $this->assertSame(
      [
        'id' => 2,
        // But we cannot know in advance the UUID, so just take that from
        // the response itself.
        'uuid' => $data['uuid'],
      ] +
      $expected_response_contents + [
        // In contrast with e.g. PATCH, we get two extra properties for
        // compatibility with the expected response for the Canvas UI.
        'entity_id' => '2',
        'entity_type' => 'canvas_page',
      ],
      $data
    );
  }

  public static function providerPost(): \Generator {
    yield "Create a new blank page" => [
      [
        'clientInstanceId' => 'client-123',
      ],
      [
        'title' => 'Untitled page',
        'status' => FALSE,
        'isNew' => TRUE,
        'hasUnsavedStatusChange' => FALSE,
        'path' => '/page/2',
        'internalPath' => '/page/2',
        'autoSaveLabel' => NULL,
        'autoSavePath' => NULL,
        'components' => [],
        'links' => [
          CanvasUriDefinitions::LINK_REL_EDIT => '/canvas/editor/canvas_page/2',
          CanvasUriDefinitions::LINK_REL_DUPLICATE => '/canvas/api/v0/content/canvas_page',
          CanvasUriDefinitions::LINK_REL_SET_AS_HOMEPAGE => '/canvas/editor/canvas_page/2',
        ],
      ],
    ];

    yield "Duplicate an existing page" => [
      [
        'clientInstanceId' => 'client-123',
        'entity_id' => '1',
      ],
      [
        'title' => 'A pre-existing page that can be duplicated (Copy)',
        'status' => FALSE,
        'isNew' => TRUE,
        'hasUnsavedStatusChange' => FALSE,
        'path' => '/page/2',
        'internalPath' => '/page/2',
        'autoSaveLabel' => NULL,
        'autoSavePath' => NULL,
        'components' => [],
        'links' => [
          CanvasUriDefinitions::LINK_REL_EDIT => '/canvas/editor/canvas_page/2',
          CanvasUriDefinitions::LINK_REL_DUPLICATE => '/canvas/api/v0/content/canvas_page',
          CanvasUriDefinitions::LINK_REL_SET_AS_HOMEPAGE => '/canvas/editor/canvas_page/2',
        ],
      ],
    ];

    yield "Create a new empty tree" => [
      [
        'title' => 'Test Page',
        'path' => '/test-page',
        'status' => TRUE,
        'components' => [],
      ],
      [
        'title' => 'Test Page',
        'status' => TRUE,
        'isNew' => FALSE,
        'hasUnsavedStatusChange' => FALSE,
        'path' => '/test-page',
        'internalPath' => '/page/2',
        'autoSaveLabel' => NULL,
        'autoSavePath' => NULL,
        'components' => [],
        'links' => [
          CanvasUriDefinitions::LINK_REL_UNPUBLISH => '/canvas/api/v0/content/auto-save/canvas_page/2',
          CanvasUriDefinitions::LINK_REL_EDIT => '/canvas/editor/canvas_page/2',
          CanvasUriDefinitions::LINK_REL_DUPLICATE => '/canvas/api/v0/content/canvas_page',
          CanvasUriDefinitions::LINK_REL_SET_AS_HOMEPAGE => '/canvas/editor/canvas_page/2',
        ],
      ],
    ];

    yield "A component tree with slots (ensuring decoded inputs)" => [
      [
        'title' => 'Page with components',
        'path' => '/components-page',
        'status' => TRUE,
        'components' => [
          [
            'uuid' => '09365c2d-1ee1-47fd-b5a3-7e4f34866186',
            'component_id' => 'sdc.canvas_test_sdc.props-slots',
            'component_version' => '85a5c0c7dd53e0bb',
            'inputs' => ['heading' => 'Welcome'],
            'parent_uuid' => NULL,
            'slot' => NULL,
            'label' => NULL,
          ],
          [
            'uuid' => 'af5fc5ab-1457-4258-880f-541a69c0110b',
            'component_id' => 'sdc.canvas_test_sdc.props-slots',
            'component_version' => '85a5c0c7dd53e0bb',
            'inputs' => ['heading' => 'Nested'],
            'parent_uuid' => '09365c2d-1ee1-47fd-b5a3-7e4f34866186',
            'slot' => 'the_body',
            'label' => NULL,
          ],
        ],
      ],
      [
        'title' => 'Page with components',
        'status' => TRUE,
        'isNew' => FALSE,
        'hasUnsavedStatusChange' => FALSE,
        'path' => '/components-page',
        'internalPath' => '/page/2',
        'autoSaveLabel' => NULL,
        'autoSavePath' => NULL,
        'components' => [
          [
            'parent_uuid' => NULL,
            'slot' => NULL,
            'uuid' => '09365c2d-1ee1-47fd-b5a3-7e4f34866186',
            'component_id' => 'sdc.canvas_test_sdc.props-slots',
            'component_version' => '85a5c0c7dd53e0bb',
            'inputs' => ['heading' => 'Welcome'],
            'label' => NULL,
            'inputs_resolved' => ['heading' => 'Welcome'],
          ],
          [
            'parent_uuid' => '09365c2d-1ee1-47fd-b5a3-7e4f34866186',
            'slot' => 'the_body',
            'uuid' => 'af5fc5ab-1457-4258-880f-541a69c0110b',
            'component_id' => 'sdc.canvas_test_sdc.props-slots',
            'component_version' => '85a5c0c7dd53e0bb',
            'inputs' => ['heading' => 'Nested'],
            'label' => NULL,
            'inputs_resolved' => ['heading' => 'Nested'],
          ],
        ],
        'links' => [
          CanvasUriDefinitions::LINK_REL_UNPUBLISH => '/canvas/api/v0/content/auto-save/canvas_page/2',
          CanvasUriDefinitions::LINK_REL_EDIT => '/canvas/editor/canvas_page/2',
          CanvasUriDefinitions::LINK_REL_DUPLICATE => '/canvas/api/v0/content/canvas_page',
          CanvasUriDefinitions::LINK_REL_SET_AS_HOMEPAGE => '/canvas/editor/canvas_page/2',
        ],
      ],
    ];
  }

  public function testPostWithExplicitUuid(): void {
    $uuid = 'a1b2c3d4-e5f6-4789-8abc-def012345678';
    $response = $this->request(
      Request::create(
        self::URL,
        'POST',
        server: ['CONTENT_TYPE' => 'application/json'],
        content: \json_encode([
          'title' => 'Page With Explicit UUID',
          'status' => FALSE,
          'path' => '/page-with-uuid',
          'components' => [],
          'uuid' => $uuid,
        ], JSON_THROW_ON_ERROR),
      ),
    );
    $this->assertSame(Response::HTTP_CREATED, $response->getStatusCode());
    $this->assertSame($uuid, $this->decodeResponse($response)['uuid']);
  }

  public function testPostWithDuplicateUuid(): void {
    $this->expectException(ConflictHttpException::class);
    $this->expectExceptionMessage('An entity with UUID "a1b2c3d4-e5f6-4789-8abc-def012345678" already exists.');

    $uuid = 'a1b2c3d4-e5f6-4789-8abc-def012345678';
    Page::create([
      'title' => 'Pre-existing page with known UUID',
      'uuid' => $uuid,
      'status' => FALSE,
      'path' => ['alias' => '/existing-page'],
      'components' => [],
    ])->save();

    $this->request(
      Request::create(
        self::URL,
        'POST',
        server: ['CONTENT_TYPE' => 'application/json'],
        content: \json_encode([
          'title' => 'Duplicate UUID Page',
          'status' => FALSE,
          'path' => '/duplicate-uuid-page',
          'components' => [],
          'uuid' => $uuid,
        ], JSON_THROW_ON_ERROR),
      ),
    );
  }

  public function testPostWithEmptyContentRequest(): void {
    $this->expectException(InvalidBody::class);
    $this->request(
      Request::create(
        self::URL,
        'POST',
        server: ['CONTENT_TYPE' => 'application/json'],
      ),
    );
  }

}
