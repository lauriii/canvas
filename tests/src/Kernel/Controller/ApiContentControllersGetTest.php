<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\Controller;

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\CanvasUriDefinitions;
use Drupal\canvas\ComponentSource\ComponentSourceManager;
use Drupal\canvas\Controller\ApiContentControllers;
use Drupal\canvas\Entity\Page;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\canvas\Kernel\Traits\RequestTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests the ApiContentControllers::get() method.
*/
#[RunTestsInSeparateProcesses]
#[Group('canvas')]
#[CoversClass(ApiContentControllers::class)]
#[CoversMethod(ApiContentControllers::class, 'get')]
class ApiContentControllersGetTest extends CanvasKernelTestBase {

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
   * Tests get() returns a page with its component tree.
   *
   * @legacy-covers \Drupal\canvas\Controller\ApiContentControllers::get
   */
  #[DataProvider('providerGet')]
  public function testGet(array $page_contents, array $expected_response_contents, array $expected_cacheability): void {
    $page = Page::create([
      'title' => $page_contents['title'],
      'status' => TRUE,
      'path' => ['alias' => $page_contents['alias']],
      'components' => $page_contents['components'],
    ]);
    $page->save();

    $response = $this->request(Request::create(\sprintf(self::URL, $page->id())));
    \assert($response instanceof CacheableJsonResponse);

    $data = $this->decodeResponse($response);

    // Versioned public APIs need to be strict: this means asserting
    // that we get all the expected info, but also NO extra additions.
    // So we use `assertSame` in the full response contents.
    $this->assertSame(
      ['id' => (int) $page->id(), 'uuid' => $page->uuid()] + $expected_response_contents,
      $data
    );

    $cacheability = $response->getCacheableMetadata();
    $this->assertSame($expected_cacheability['cache_tags'], $cacheability->getCacheTags());
    $this->assertSame($expected_cacheability['cache_contexts'], $cacheability->getCacheContexts());
    $this->assertSame($expected_cacheability['cache_max_age'], $cacheability->getCacheMaxAge());

  }

  public static function providerGet(): \Generator {
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
      [
        'cache_tags' => [
          'canvas_page:1',
          // Access check on home-page adds this.
          'test_create_access_cache_tag',
          'config:system.site',
          AutoSaveManager::CACHE_TAG,
          'http_response',
        ],
        'cache_contexts' => [
          'user.permissions',
        ],
        'cache_max_age' => Cache::PERMANENT,
      ],
    ];

    yield "A component tree with slots (ensuring decoded inputs)" => [
      [
        'title' => 'Page with components',
        'status' => TRUE,
        'alias' => '/components-page',
        'components' => [
          [
            'uuid' => '09365c2d-1ee1-47fd-b5a3-7e4f34866186',
            'component_id' => 'sdc.canvas_test_sdc.props-slots',
            'inputs' => ['heading' => 'Welcome'],
          ],
          [
            'uuid' => 'af5fc5ab-1457-4258-880f-541a69c0110b',
            'component_id' => 'sdc.canvas_test_sdc.props-slots',
            'parent_uuid' => '09365c2d-1ee1-47fd-b5a3-7e4f34866186',
            'slot' => 'slot_one',
            'inputs' => ['heading' => 'Nested'],
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
            'slot' => 'slot_one',
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
      [
        'cache_tags' => [
          'canvas_page:1',
          // Access check on home-page adds this.
          'test_create_access_cache_tag',
          'config:system.site',
          AutoSaveManager::CACHE_TAG,
          'http_response',
        ],
        'cache_contexts' => [
          'user.permissions',
        ],
        'cache_max_age' => Cache::PERMANENT,
      ],
    ];
  }

}
