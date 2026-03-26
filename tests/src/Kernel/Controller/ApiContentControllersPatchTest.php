<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\Controller;

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\CanvasUriDefinitions;
use Drupal\canvas\ComponentSource\ComponentSourceManager;
use Drupal\canvas\Controller\ApiContentControllers;
use Drupal\canvas\Entity\Page;
use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\canvas\Kernel\Traits\RequestTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
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
 * Tests the ApiContentControllers::patch() method.
*/
#[RunTestsInSeparateProcesses]
#[Group('canvas')]
#[CoversClass(ApiContentControllers::class)]
#[CoversMethod(ApiContentControllers::class, 'patch')]
class ApiContentControllersPatchTest extends CanvasKernelTestBase {

  use UserCreationTrait;
  use RequestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'canvas_test_page',
    'field',
  ];

  private const string URL = '/canvas/api/v0/content/canvas_page/%s';

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
  }

  /**
   * Tests patch() returns a page with its component tree.
   *
   * @legacy-covers \Drupal\canvas\Controller\ApiContentControllers::patch
   */
  #[DataProvider('providerPatch')]
  public function testPatch(array $page_contents, array $expected_response_contents): void {
    $page = Page::create([
      'title' => 'Initial title',
      'status' => FALSE,
      'path' => ['alias' => '/this-is-the-old-path'],
      'components' => [],
    ]);
    self::assertEntityIsValid($page);
    $page->save();

    $request = Request::create(\sprintf(self::URL, $page->id()),
      'PATCH',
      server: ['CONTENT_TYPE' => 'application/json'],
      content: \json_encode([
        'title' => $page_contents['title'],
        'status' => TRUE,
        'path' => $page_contents['alias'],
        'components' => $page_contents['components'],
      ], JSON_THROW_ON_ERROR),
    );
    $response = $this->request($request);
    // The response of a PATCH request shouldn't be cacheable.
    \assert($response instanceof JsonResponse && !$response instanceof CacheableJsonResponse);

    $data = $this->decodeResponse($response);

    // Versioned public APIs need to be strict: this means asserting
    // that we get all the expected info, but also NO extra additions.
    // So we use `assertSame` in the full response contents.
    $this->assertSame(
      ['id' => (int) $page->id(), 'uuid' => $page->uuid()] + $expected_response_contents,
      $data
    );
  }

  public static function providerPatch(): \Generator {
    yield "Empty tree" => [
      [
        'title' => 'Test Page',
        'alias' => '/test-page',
        'status' => TRUE,
        'components' => [],
      ],
      [
        'title' => 'Test Page',
        'status' => TRUE,
        'isNew' => FALSE,
        'hasUnsavedStatusChange' => FALSE,
        'path' => '/test-page',
        'internalPath' => '/page/1',
        'autoSaveLabel' => NULL,
        'autoSavePath' => NULL,
        'components' => [],
        'links' => [
          CanvasUriDefinitions::LINK_REL_UNPUBLISH => '/canvas/api/v0/content/auto-save/canvas_page/1',
          CanvasUriDefinitions::LINK_REL_EDIT => '/canvas/editor/canvas_page/1',
          CanvasUriDefinitions::LINK_REL_DUPLICATE => '/canvas/api/v0/content/canvas_page',
          CanvasUriDefinitions::LINK_REL_SET_AS_HOMEPAGE => '/canvas/editor/canvas_page/1',
        ],
      ],
    ];

    yield "A component tree with slots (ensuring decoded inputs)" => [
      [
        'title' => 'Page with components',
        'alias' => '/components-page',
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
        'internalPath' => '/page/1',
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
          CanvasUriDefinitions::LINK_REL_UNPUBLISH => '/canvas/api/v0/content/auto-save/canvas_page/1',
          CanvasUriDefinitions::LINK_REL_EDIT => '/canvas/editor/canvas_page/1',
          CanvasUriDefinitions::LINK_REL_DUPLICATE => '/canvas/api/v0/content/canvas_page',
          CanvasUriDefinitions::LINK_REL_SET_AS_HOMEPAGE => '/canvas/editor/canvas_page/1',
        ],
      ],
    ];
  }

  /**
   * Tests that PATCHing a path alias results in exactly one alias being set.
   */
  #[DataProvider('providerPatchPathAlias')]
  public function testPatchPathAlias(array $initial_path): void {
    $page = Page::create([
      'title' => 'Initial title',
      'status' => TRUE,
      'path' => $initial_path,
      'components' => [],
    ]);
    self::assertEntityIsValid($page);
    $page->save();

    $alias_storage = \Drupal::entityTypeManager()
      ->getStorage('path_alias');
    $internal_path = ['path' => '/page/' . $page->id()];

    $this->assertCount(
      !empty($initial_path) ? 1 : 0,
      $alias_storage->loadByProperties($internal_path),
    );

    $this->request(Request::create(
      \sprintf(self::URL, $page->id()),
      'PATCH',
      server: ['CONTENT_TYPE' => 'application/json'],
      content: \json_encode([
        'title' => 'Initial title',
        'status' => TRUE,
        'path' => '/new-alias',
        'components' => [],
      ], JSON_THROW_ON_ERROR),
    ));

    $path_aliases = $alias_storage->loadByProperties($internal_path);
    $this->assertCount(1, $path_aliases);
    $this->assertSame('/new-alias', reset($path_aliases)->getAlias());
  }

  public static function providerPatchPathAlias(): \Generator {
    yield 'With a pre-existing alias' => [
      ['alias' => '/old-alias'],
      1,
    ];
    yield 'Without a pre-existing alias' => [
      [],
      0,
    ];
  }

  public function testConflictErrorIfPatchingEntityWithAutoSaveDataPresent(): void {
    $this->expectException(ConflictHttpException::class);
    $page = Page::create([
      'title' => 'Initial title',
      'status' => FALSE,
      'path' => ['alias' => '/this-is-the-old-path'],
      'components' => [],
    ]);
    self::assertEntityIsValid($page);
    $page->save();

    // Create some auto-save data. We don't know yet if should be discarded or
    // not, so better have explicit coverage of it.
    $autoSaveManager = \Drupal::service(AutoSaveManager::class);
    \assert($autoSaveManager instanceof AutoSaveManager);
    $page->set('title', 'autosaved title');
    $page->set('path', '/autosaved/path');
    $autoSaveManager->saveEntity($page);

    $request = Request::create(\sprintf(self::URL, $page->id()),
      'PATCH',
      server: ['CONTENT_TYPE' => 'application/json'],
      content: \json_encode([
        'title' => 'My patched title',
        'status' => TRUE,
        'path' => '/patched',
        'components' => [],
      ], JSON_THROW_ON_ERROR),
    );
    $response = $this->request($request);
    // The response of a patch shouldn't be cacheable.
    \assert($response instanceof JsonResponse && !$response instanceof CacheableJsonResponse);

    $this->assertSame(Response::HTTP_CONFLICT, $response->getStatusCode());
    $this->assertSame([
      'error' => 'Page with ID 1 has existing auto-saved data. Please use the UI to publish it or discard it.',
    ], $this->decodeResponse($response));
  }

}
