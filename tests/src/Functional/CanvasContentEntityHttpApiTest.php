<?php

declare(strict_types=1);

// cspell:ignore Bwidth

namespace Drupal\Tests\canvas\Functional;

use Drupal\canvas\ComponentSource\ComponentSourceManager;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\DataProvider;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityPublishedInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Url;
use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\Entity\Page;
use Drupal\canvas\CanvasUriDefinitions;
use Drupal\user\Entity\Role;
use Drupal\user\UserInterface;
use GuzzleHttp\RequestOptions;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Psr\Http\Message\ResponseInterface;

/**
 * Tests Canvas Content Entity Http Api.
 *
 * @internal
 * @legacy-covers \Drupal\canvas\Controller\ApiContentControllers
 */
#[RunTestsInSeparateProcesses]
#[Group('canvas')]
#[Group('#slow')]
final class CanvasContentEntityHttpApiTest extends HttpApiTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'canvas',
    'canvas_test_sdc',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';


  /**
   * @todo Test GET/PATCH here instead / on top of the new test(s)?
   */

  protected array $pages;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->pages = [
      Page::create([
        'title' => "Page 1",
        'status' => TRUE,
        'path' => ['alias' => "/page-1"],
      ]),
      Page::create([
        'title' => self::NEW_PAGE_TITLE,
        'status' => FALSE,
      ]),
      Page::create([
        'title' => "Page 3",
        'status' => TRUE,
        'path' => ['alias' => "/page-3"],
      ]),
    ];
    foreach ($this->pages as $page) {
      $page->save();
    }
    foreach ($this->pages as $page) {
      $page->save();
    }
    // Set the page 2 to be the homepage.
    $this->config('system.site')
      ->set('page.front', '/page/2')
      ->save();
  }

  public function testPostWithData(): void {
    $this->container->get(ComponentSourceManager::class)->generateComponents('sdc', ['canvas_test_sdc:heading']);
    // Suppress the security token in the URL. In this test we cannot really
    // set a predictable itok because of the HTTP requests of this test.
    // This is an insecure setup, but good enough for the purpose of this test.
    $this->config('image.settings')->set('suppress_itok_output', TRUE)->save();

    $url = Url::fromUri('base:/canvas/api/v0/content/canvas_page');
    $request_options = [
      RequestOptions::HEADERS => [
        'Content-Type' => 'application/json',
      ],
      RequestOptions::JSON => [
        'title' => 'This is my new content title',
        'status' => TRUE,
        'path' => '/my-awesome-new-page',
        'components' => [
          [
            'uuid' => '4c3482ac-4635-4ba9-aaf4-eb86892d77a1',
            'component_id' => 'sdc.canvas_test_sdc.heading',
            'component_version' => '8c01a2bdb897a810',
            'inputs' => [
              'text' => 'My custom header',
              'style' => 'secondary',
              'element' => 'h3',
            ],
          ],
          [
            'uuid' => '834fc6b0-7abd-48c7-888e-93b0a7f2526c',
            'component_id' => 'sdc.canvas_test_sdc.card',
            'component_version' => 'e94eb1a3d14c2de8',
            'inputs' => [
              'heading' => 'Test Card',
              'content' => 'Test content',
              'footer' => 'Test Card Footer',
              'loading' => 'lazy',
              'image' => [
                'target_id' => 1,
              ],
            ],
          ],
        ],
      ],
    ];

    $this->assertAuthenticationAndAuthorization($url, 'POST');

    $request_options['headers']['X-CSRF-Token'] = $this->drupalGet('session/token');
    // Authenticated, authorized, with CSRF token: 201.
    Role::load('authenticated')
      ?->grantPermission(Page::CREATE_PERMISSION)
      // Access content is required for accessing the media entity that we
      // are using in the card component.
      ?->grantPermission('access content')
        ->save();
    $response = $this->makeApiRequest('POST', $url, $request_options);
    $this->assertSame(201, $response->getStatusCode());
    $this->assertPostResponse($response, [
      'entity_type' => Page::ENTITY_TYPE_ID,
      'entity_id' => '4',
      'title' => 'This is my new content title',
      'path' => Url::fromUri('base://my-awesome-new-page')->toString(),
      'components' => [
        [
          'parent_uuid' => NULL,
          'slot' => NULL,
          'uuid' => '4c3482ac-4635-4ba9-aaf4-eb86892d77a1',
          'component_id' => 'sdc.canvas_test_sdc.heading',
          'component_version' => '8c01a2bdb897a810',
          'inputs' => [
            'text' => 'My custom header',
            'style' => 'secondary',
            'element' => 'h3',
          ],
          'label' => NULL,
          'inputs_resolved' => [
            'text' => 'My custom header',
            'style' => 'secondary',
            'element' => 'h3',
          ],
        ],
        [
          'parent_uuid' => NULL,
          'slot' => NULL,
          'uuid' => '834fc6b0-7abd-48c7-888e-93b0a7f2526c',
          'component_id' => 'sdc.canvas_test_sdc.card',
          'component_version' => 'e94eb1a3d14c2de8',
          'inputs' => [
            'heading' => 'Test Card',
            'content' => 'Test content',
            'footer' => 'Test Card Footer',
            'loading' => 'lazy',
            'image' => [
              'target_id' => 1,
            ],
          ],
          'label' => NULL,
          'inputs_resolved' => [
            'heading' => 'Test Card',
            'content' => 'Test content',
            'footer' => 'Test Card Footer',
            'loading' => 'lazy',
            'image' => [
              'src' => base_path() . $this->siteDirectory . '/files/balloons.png?alternateWidths=' . base_path() . $this->siteDirectory . '/files/styles/canvas_parametrized_width--%7Bwidth%7D/public/balloons.png.avif',
              'alt' => '',
              'width' => 0,
              'height' => 0,
            ],
          ],
        ],
      ],
    ]);
  }

  public function testPostWithInvalidData(): void {
    $this->container->get(ComponentSourceManager::class)->generateComponents('sdc', ['canvas_test_sdc:heading']);
    $url = Url::fromUri('base:/canvas/api/v0/content/canvas_page');
    $request_options = [
      RequestOptions::HEADERS => [
        'Content-Type' => 'application/json',
      ],
      RequestOptions::JSON => [
        'title' => 'This is my new content title',
        'status' => TRUE,
        'path' => '/my-awesome-new-page',
        'components' => [
          [
            "uuid" => "4c3482ac-4635-4ba9-aaf4-eb86892d77a1",
            "component_id" => "sdc.canvas_test_sdc.heading",
            // A component version that doesn't exist.
            "component_version" => "incorrect-component-version",
            "inputs" => [
              'text' => 'My custom header',
              'style' => 'secondary',
              'element' => 'h3',
            ],
          ],
        ],
      ],
    ];

    $this->assertAuthenticationAndAuthorization($url, 'POST');

    $request_options['headers']['X-CSRF-Token'] = $this->drupalGet('session/token');
    // Authenticated, authorized, with CSRF token: 201.
    Role::load('authenticated')?->grantPermission(Page::CREATE_PERMISSION)->save();
    $response = $this->makeApiRequest('POST', $url, $request_options);
    $this->assertSame(422, $response->getStatusCode());
    $this->assertPostResponse($response, [
      'errors' => [
        [
          'detail' => "'incorrect-component-version' is not a version that exists on component config entity 'sdc.canvas_test_sdc.heading'. Available versions: '8c01a2bdb897a810'.",
          'source' => [
            'pointer' => 'components.0.component_version',
          ],
        ],
      ],
    ]);
  }

  public function testPostWithNoData(): void {
    $url = Url::fromUri('base:/canvas/api/v0/content/canvas_page');
    $request_options = [
      RequestOptions::HEADERS => [
        'Content-Type' => 'application/json',
      ],
      RequestOptions::JSON => [
        // The clientInstanceId is mandatory if no other data is sent!
        'clientInstanceId' => 'client-123',
      ],
    ];

    $this->assertAuthenticationAndAuthorization($url, 'POST');

    $request_options['headers']['X-CSRF-Token'] = $this->drupalGet('session/token');
    // Authenticated, authorized, with CSRF token: 201.
    Role::load('authenticated')?->grantPermission(Page::CREATE_PERMISSION)->save();
    $response = $this->makeApiRequest('POST', $url, $request_options);
    $this->assertSame(201, $response->getStatusCode());
    $this->assertPostResponse($response, [
      'entity_type' => Page::ENTITY_TYPE_ID,
      'entity_id' => '4',
      'title' => 'Untitled page',
      'path' => Url::fromUri('base://page/4')->toString(),
      'components' => [],
    ]);
  }

  public function testList(): void {
    $url = Url::fromUri('base:/canvas/api/v0/content/canvas_page');

    $this->assertAuthenticationAndAuthorization($url, 'GET');

    // Authenticated, authorized: 200.
    $user = $this->createUser([Page::EDIT_PERMISSION], 'administer_canvas_page_user');
    \assert($user instanceof UserInterface);
    $this->drupalLogin($user);
    // We have a cache tag for page 2 as it's the homepage, set in system.site
    // config.
    $expected_tags = [
      AutoSaveManager::CACHE_TAG,
      'config:system.site',
      'http_response',
      'canvas_page:2',
      'canvas_page_list',
    ];
    $list_cache_contexts = ['url.query_args:page', 'url.query_args:search', 'user.permissions'];
    $body = $this->assertExpectedResponse('GET', $url, [], 200, $list_cache_contexts, $expected_tags, 'UNCACHEABLE (request policy)', 'MISS');
    \assert(\is_array($body));
    $this->assertArrayHasKey('data', $body);
    $this->assertArrayHasKey('meta', $body);
    $this->assertSame(3, $body['meta']['count']);
    // All 3 pages fit on one page, so no prev/next links.
    $this->assertArrayHasKey('self', $body['links']);
    $this->assertArrayNotHasKey('first', $body['links']);
    $this->assertArrayNotHasKey('prev', $body['links']);
    $this->assertArrayNotHasKey('next', $body['links']);
    $this->assertArrayNotHasKey('last', $body['links']);

    // Build a map keyed by entity ID for stable per-entity assertions.
    $no_auto_save_expected_pages = [
      // Page 1 has a path alias.
      1 => [
        'id' => 1,
        'title' => 'Page 1',
        'status' => TRUE,
        'isNew' => FALSE,
        'hasUnsavedStatusChange' => FALSE,
        'path' => base_path() . 'page-1',
        'autoSaveLabel' => NULL,
        'autoSavePath' => NULL,
        'links' => [
          // @todo https://www.drupal.org/i/3498525 should standardize arguments.
          CanvasUriDefinitions::LINK_REL_EDIT => Url::fromUri('base:/canvas/editor/canvas_page/1')->toString(),
          CanvasUriDefinitions::LINK_REL_SET_AS_HOMEPAGE => Url::fromUri('base:/canvas/editor/canvas_page/1')->toString(),
          CanvasUriDefinitions::LINK_REL_UNPUBLISH => Url::fromUri('base:/canvas/api/v0/content/auto-save/canvas_page/1')->toString(),
        ],
        'internalPath' => '/page/1',
        'uuid' => $this->pages[0]->uuid(),
      ],
      // Page 2 has no path alias.
      2 => [
        'id' => 2,
        'title' => self::NEW_PAGE_TITLE,
        'status' => FALSE,
        'isNew' => TRUE,
        'hasUnsavedStatusChange' => FALSE,
        'path' => base_path() . 'page/2',
        'autoSaveLabel' => NULL,
        'autoSavePath' => NULL,
        'links' => [
          // @todo https://www.drupal.org/i/3498525 should standardize arguments.
          CanvasUriDefinitions::LINK_REL_EDIT => Url::fromUri('base:/canvas/editor/canvas_page/2')->toString(),
          CanvasUriDefinitions::LINK_REL_SET_AS_HOMEPAGE => Url::fromUri('base:/canvas/editor/canvas_page/2')->toString(),
        ],
        'internalPath' => '/page/2',
        'uuid' => $this->pages[1]->uuid(),
      ],
      3 => [
        'id' => 3,
        'title' => 'Page 3',
        'status' => TRUE,
        'isNew' => FALSE,
        'hasUnsavedStatusChange' => FALSE,
        'path' => base_path() . 'page-3',
        'autoSaveLabel' => NULL,
        'autoSavePath' => NULL,
        'links' => [
          // @todo https://www.drupal.org/i/3498525 should standardize arguments.
          CanvasUriDefinitions::LINK_REL_EDIT => Url::fromUri('base:/canvas/editor/canvas_page/3')->toString(),
          CanvasUriDefinitions::LINK_REL_SET_AS_HOMEPAGE => Url::fromUri('base:/canvas/editor/canvas_page/3')->toString(),
          CanvasUriDefinitions::LINK_REL_UNPUBLISH => Url::fromUri('base:/canvas/api/v0/content/auto-save/canvas_page/3')->toString(),
        ],
        'internalPath' => '/page/3',
        'uuid' => $this->pages[2]->uuid(),
      ],
    ];
    $this->assertEquals(
      $no_auto_save_expected_pages,
      \array_column($body['data'], NULL, 'id')
    );
    $this->assertExpectedResponse('GET', $url, [], 200, $list_cache_contexts, $expected_tags, 'UNCACHEABLE (request policy)', 'HIT');

    // Test searching by query parameter — search returns {data: [...]} without meta/links.
    $search_url = Url::fromUri('base:/canvas/api/v0/content/canvas_page', ['query' => ['search' => 'Page 1']]);
    // Because page 2 isn't in these results, we don't get its cache tag.
    $expected_tags_without_page_2 = \array_diff($expected_tags, ['canvas_page:2']);
    // Confirm that the cache is not hit when a different request is made with query parameter.
    $search_body = $this->assertExpectedResponse('GET', $search_url, [], 200, ['languages:' . LanguageInterface::TYPE_CONTENT, 'url.query_args:search', 'user.permissions'], $expected_tags_without_page_2, 'UNCACHEABLE (request policy)', 'MISS');
    \assert(\is_array($search_body));
    $this->assertArrayHasKey('data', $search_body);
    $this->assertArrayNotHasKey('meta', $search_body, 'Search results do not include a total count.');
    $this->assertArrayNotHasKey('links', $search_body, 'Search results do not include pagination links.');
    $this->assertEquals(
      [
        1 => [
          'id' => 1,
          'title' => 'Page 1',
          'status' => TRUE,
          'isNew' => FALSE,
          'hasUnsavedStatusChange' => FALSE,
          'path' => base_path() . 'page-1',
          'autoSaveLabel' => NULL,
          'autoSavePath' => NULL,
          'links' => [
            // @todo https://www.drupal.org/i/3498525 should remove the hardcoded `canvas_page` from these.
            CanvasUriDefinitions::LINK_REL_EDIT => Url::fromUri('base:/canvas/editor/canvas_page/1')->toString(),
            CanvasUriDefinitions::LINK_REL_SET_AS_HOMEPAGE => Url::fromUri('base:/canvas/editor/canvas_page/1')->toString(),
            CanvasUriDefinitions::LINK_REL_UNPUBLISH => Url::fromUri('base:/canvas/api/v0/content/auto-save/canvas_page/1')->toString(),
          ],
          'internalPath' => '/page/1',
          'uuid' => $this->pages[0]->uuid(),
        ],
      ],
      \array_column($search_body['data'], NULL, 'id')
    );
    // Confirm that the cache is hit when the same request is made again.
    $this->assertExpectedResponse('GET', $search_url, [], 200, ['languages:' . LanguageInterface::TYPE_CONTENT, 'url.query_args:search', 'user.permissions'], $expected_tags_without_page_2, 'UNCACHEABLE (request policy)', 'HIT');

    // Test searching by query parameter - substring match.
    $substring_search_url = Url::fromUri('base:/canvas/api/v0/content/canvas_page', ['query' => ['search' => 'age']]);
    $substring_search_body = $this->assertExpectedResponse('GET', $substring_search_url, [], 200, ['languages:' . LanguageInterface::TYPE_CONTENT, 'url.query_args:search', 'user.permissions'], $expected_tags, 'UNCACHEABLE (request policy)', 'MISS');
    \assert(\is_array($substring_search_body));
    $this->assertEquals($no_auto_save_expected_pages, \array_column($substring_search_body['data'], NULL, 'id'));

    $autoSaveManager = $this->container->get(AutoSaveManager::class);
    $page_1 = Page::load(1);
    $this->assertInstanceOf(Page::class, $page_1);
    $page_1->set('title', 'The updated title.');
    $page_1->set('path', ['alias' => "/the-updated-path"]);
    $autoSaveManager->saveEntity($page_1);

    $page_2 = Page::load(2);
    $this->assertInstanceOf(Page::class, $page_2);
    $page_2->set('title', 'The updated title2.');
    $page_2->set('path', ['alias' => "/the-new-path"]);
    $autoSaveManager->saveEntity($page_2);

    $body = $this->assertExpectedResponse('GET', $url, [], 200, $list_cache_contexts, $expected_tags, 'UNCACHEABLE (request policy)', 'MISS');
    \assert(\is_array($body));
    $auto_save_expected_pages = $no_auto_save_expected_pages;
    $auto_save_expected_pages[1]['autoSaveLabel'] = 'The updated title.';
    $auto_save_expected_pages[1]['autoSavePath'] = '/the-updated-path';
    $auto_save_expected_pages[2]['autoSaveLabel'] = 'The updated title2.';
    $auto_save_expected_pages[2]['autoSavePath'] = '/the-new-path';
    $this->assertEquals(
      $auto_save_expected_pages,
      \array_column($body['data'], NULL, 'id')
    );
    $this->assertExpectedResponse('GET', $url, [], 200, $list_cache_contexts, $expected_tags, 'UNCACHEABLE (request policy)', 'HIT');

    // Confirm that if path alias is empty, the system path is used, not the
    // existing alias if set.
    $page_1->set('title', 'The updated title.');
    $page_1->set('path', NULL);
    $autoSaveManager->saveEntity($page_1);

    $page_2->set('title', 'The updated title2.');
    $page_2->set('path', NULL);
    $autoSaveManager->saveEntity($page_2);

    $body = $this->assertExpectedResponse('GET', $url, [], 200, $list_cache_contexts, $expected_tags, 'UNCACHEABLE (request policy)', 'MISS');
    \assert(\is_array($body));
    $auto_save_expected_pages[1]['autoSavePath'] = '/page/1';
    $auto_save_expected_pages[2]['autoSavePath'] = '/page/2';
    $this->assertEquals(
      $auto_save_expected_pages,
      \array_column($body['data'], NULL, 'id')
    );

    $autoSaveManager->delete($page_1);
    $autoSaveManager->delete($page_2);
    $body = $this->assertExpectedResponse('GET', $url, [], 200, $list_cache_contexts, $expected_tags, 'UNCACHEABLE (request policy)', 'MISS');
    \assert(\is_array($body));
    $this->assertEquals(
      $no_auto_save_expected_pages,
      \array_column($body['data'], NULL, 'id')
    );
    $this->assertExpectedResponse('GET', $url, [], 200, $list_cache_contexts, $expected_tags, 'UNCACHEABLE (request policy)', 'HIT');
  }

  public function testListPagination(): void {
    $user = $this->createUser([Page::EDIT_PERMISSION], 'list_canvas_page_user');
    \assert($user instanceof UserInterface);
    $this->drupalLogin($user);

    $list_cache_contexts = ['url.query_args:page', 'url.query_args:search', 'user.permissions'];
    // Base tags present on every list response, regardless of which page is
    // included in the result. canvas_page:2 is only present when page 2 is in
    // the result set (page 2 has no path alias, so its entity cache tag is
    // added during URL generation; pages with aliases add a path_alias tag
    // instead).
    $base_tags = [
      AutoSaveManager::CACHE_TAG,
      'config:system.site',
      'http_response',
      'canvas_page_list',
    ];
    $all_pages_tags = [...$base_tags, 'canvas_page:2'];

    // Page 1 of 3 with limit=1: should have next/last but not first/prev.
    $url_page1 = Url::fromUri('base:/canvas/api/v0/content/canvas_page', ['query' => ['page' => ['offset' => 0, 'limit' => 1]]]);
    $body = $this->assertExpectedResponse('GET', $url_page1, [], 200, $list_cache_contexts, $base_tags, 'UNCACHEABLE (request policy)', 'MISS');
    \assert(\is_array($body));
    $this->assertCount(1, $body['data']);
    $this->assertSame(3, $body['meta']['count']);
    $this->assertArrayHasKey('self', $body['links']);
    $this->assertArrayNotHasKey('first', $body['links']);
    $this->assertArrayNotHasKey('prev', $body['links']);
    $this->assertArrayHasKey('next', $body['links']);
    $this->assertArrayHasKey('last', $body['links']);
    // The next link should point to offset=1.
    $next_href = urldecode($body['links']['next']['href']);
    $this->assertStringContainsString('page[offset]=1', $next_href);
    $this->assertStringContainsString('page[limit]=1', $next_href);

    // Middle page (offset=1, limit=1): should have first/prev/next/last.
    // Pages are sorted by revision_created DESC; page 2 (saved second, no path
    // alias) lands here, so canvas_page:2 is present in the cache tags.
    $url_page2 = Url::fromUri('base:/canvas/api/v0/content/canvas_page', ['query' => ['page' => ['offset' => 1, 'limit' => 1]]]);
    $body = $this->assertExpectedResponse('GET', $url_page2, [], 200, $list_cache_contexts, $all_pages_tags, 'UNCACHEABLE (request policy)', 'MISS');
    \assert(\is_array($body));
    $this->assertCount(1, $body['data']);
    $this->assertSame(3, $body['meta']['count']);
    $this->assertArrayHasKey('first', $body['links']);
    $this->assertArrayHasKey('prev', $body['links']);
    $this->assertArrayHasKey('next', $body['links']);
    $this->assertArrayHasKey('last', $body['links']);

    // Last page (offset=2, limit=1): should have first/prev but not next/last.
    $url_page3 = Url::fromUri('base:/canvas/api/v0/content/canvas_page', ['query' => ['page' => ['offset' => 2, 'limit' => 1]]]);
    $body = $this->assertExpectedResponse('GET', $url_page3, [], 200, $list_cache_contexts, $base_tags, 'UNCACHEABLE (request policy)', 'MISS');
    \assert(\is_array($body));
    $this->assertCount(1, $body['data']);
    $this->assertSame(3, $body['meta']['count']);
    $this->assertArrayHasKey('first', $body['links']);
    $this->assertArrayHasKey('prev', $body['links']);
    $this->assertArrayNotHasKey('next', $body['links']);
    $this->assertArrayNotHasKey('last', $body['links']);
  }

  /**
   * Tests list meta operations.
   *
   * @param list<string> $extraCacheContexts
   * @param list<string> $extraCacheTags
   */
  #[DataProvider('metaOperationsProvider')]
  public function testListMetaOperations(array $permissions, array $expectedLinks, array $extraCacheContexts, array $extraCacheTags): void {
    $url = Url::fromUri('base:/canvas/api/v0/content/canvas_page');
    array_walk($expectedLinks, fn(&$value) => $value = Url::fromUri($value)->toString());
    // Enable canvas_test_access, which will disable view permission for page 1
    // and add extra cache contexts and cache tags.
    $this->container->get('module_installer')->install(['canvas_test_access']);
    \Drupal::keyValue('canvas_test_access')->set('cache_contexts', $extraCacheContexts);
    \Drupal::keyValue('canvas_test_access')->set('cache_tags', $extraCacheTags);

    $user = $this->createUser($permissions);
    \assert($user instanceof UserInterface);
    $this->drupalLogin($user);
    // We have a cache tag for page 2 as it's the homepage, set in system.site
    // config.
    $body = $this->assertExpectedResponse('GET', $url, [], 200, Cache::mergeContexts(['url.query_args:page', 'url.query_args:search', 'user.permissions'], $extraCacheContexts), Cache::mergeTags([AutoSaveManager::CACHE_TAG, 'config:system.site', 'http_response', 'canvas_page:2', 'canvas_page_list'], $extraCacheTags), 'UNCACHEABLE (request policy)', 'MISS');
    \assert(\is_array($body));
    $data_by_id = \array_column($body['data'], NULL, 'id');
    \assert(\array_key_exists(1, $data_by_id) && \array_key_exists('links', $data_by_id[1]));
    $this->assertEquals(
      $expectedLinks,
      $data_by_id[1]['links']
    );
  }

  public static function metaOperationsProvider(): array {
    // All of them require Page::EDIT_PERMISSION, that's a requirement for the
    // controller itself.
    return [
      'can edit' => [
        [Page::EDIT_PERMISSION],
        [
          CanvasUriDefinitions::LINK_REL_EDIT => 'base:/canvas/editor/canvas_page/1',
          CanvasUriDefinitions::LINK_REL_SET_AS_HOMEPAGE => 'base:/canvas/editor/canvas_page/1',
          CanvasUriDefinitions::LINK_REL_UNPUBLISH => 'base:/canvas/api/v0/content/auto-save/canvas_page/1',
        ],
        [],
        [],
      ],
      'can edit and delete' => [
        [Page::EDIT_PERMISSION, Page::DELETE_PERMISSION],
        [
          CanvasUriDefinitions::LINK_REL_EDIT => 'base:/canvas/editor/canvas_page/1',
          CanvasUriDefinitions::LINK_REL_SET_AS_HOMEPAGE => 'base:/canvas/editor/canvas_page/1',
          CanvasUriDefinitions::LINK_REL_UNPUBLISH => 'base:/canvas/api/v0/content/auto-save/canvas_page/1',
          CanvasUriDefinitions::LINK_REL_DELETE => 'base:/canvas/api/v0/content/canvas_page/1',
        ],
        [],
        [],
      ],
      'can create, edit and delete' => [
        [Page::CREATE_PERMISSION, Page::EDIT_PERMISSION, Page::DELETE_PERMISSION],
        [
          CanvasUriDefinitions::LINK_REL_EDIT => 'base:/canvas/editor/canvas_page/1',
          CanvasUriDefinitions::LINK_REL_SET_AS_HOMEPAGE => 'base:/canvas/editor/canvas_page/1',
          CanvasUriDefinitions::LINK_REL_UNPUBLISH => 'base:/canvas/api/v0/content/auto-save/canvas_page/1',
          CanvasUriDefinitions::LINK_REL_DUPLICATE => 'base:/canvas/api/v0/content/canvas_page',
          CanvasUriDefinitions::LINK_REL_DELETE => 'base:/canvas/api/v0/content/canvas_page/1',
        ],
        [],
        [],
      ],
      'can create and edit, with extra cache metadata' => [
        [Page::CREATE_PERMISSION, Page::EDIT_PERMISSION],
        [
          CanvasUriDefinitions::LINK_REL_EDIT => 'base:/canvas/editor/canvas_page/1',
          CanvasUriDefinitions::LINK_REL_SET_AS_HOMEPAGE => 'base:/canvas/editor/canvas_page/1',
          CanvasUriDefinitions::LINK_REL_UNPUBLISH => 'base:/canvas/api/v0/content/auto-save/canvas_page/1',
          CanvasUriDefinitions::LINK_REL_DUPLICATE => 'base:/canvas/api/v0/content/canvas_page',
        ],
        ['headers:X-Something'],
        ['zzz'],
      ],
    ];
  }

  public function testDelete(): void {
    $url = Url::fromUri('base:/canvas/api/v0/content/canvas_page/1');
    $request_options = [
      RequestOptions::HEADERS => [
        'Content-Type' => 'application/json',
      ],
    ];

    $this->assertAuthenticationAndAuthorization($url, 'DELETE');

    $request_options['headers']['X-CSRF-Token'] = $this->drupalGet('session/token');
    // Authenticated, unauthorized, with CSRF token: 403.
    $response = $this->makeApiRequest('DELETE', $url, $request_options);
    $this->assertSame(403, $response->getStatusCode());
    $this->assertSame(['errors' => ["The 'delete canvas_page' permission is required."]], json_decode((string) $response->getBody(), TRUE));

    // Authenticated, authorized, with CSRF token: 204.
    Role::load('authenticated')?->grantPermission(Page::DELETE_PERMISSION)->save();
    $response = $this->makeApiRequest('DELETE', $url, $request_options);
    $this->assertSame(204, $response->getStatusCode());
    $this->assertNull(\Drupal::entityTypeManager()->getStorage(Page::ENTITY_TYPE_ID)->load(1));

    // Try to delete the page 2, which is set as homepage.
    $url = Url::fromUri('base:/canvas/api/v0/content/canvas_page/2');
    $response = $this->makeApiRequest('DELETE', $url, $request_options);
    $this->assertSame(403, $response->getStatusCode());
    $this->assertSame(
      ['errors' => ['This entity cannot be deleted because its path is set as the homepage.']],
      json_decode((string) $response->getBody(), TRUE)
    );
  }

  public function testDeleteOperationInList(): void {
    $url = Url::fromUri('base:/canvas/api/v0/content/canvas_page');

    $this->assertAuthenticationAndAuthorization($url, 'GET');

    // Authenticated, authorized: 200.
    $user = $this->createUser([Page::EDIT_PERMISSION, Page::DELETE_PERMISSION], 'administer_canvas_page_user');
    \assert($user instanceof UserInterface);
    $this->drupalLogin($user);
    $body = $this->assertExpectedResponse('GET', $url, [], 200, ['url.query_args:page', 'url.query_args:search', 'user.permissions'], [AutoSaveManager::CACHE_TAG, 'config:system.site', 'http_response', 'canvas_page:2', 'canvas_page_list'], 'UNCACHEABLE (request policy)', 'MISS');
    \assert(\is_array($body));
    $data_by_id = \array_column($body['data'], NULL, 'id');
    \assert(\array_key_exists(2, $data_by_id) && \array_key_exists('links', $data_by_id[2]));
    $this->assertEquals(
      [
        CanvasUriDefinitions::LINK_REL_EDIT => Url::fromUri('base:/canvas/editor/canvas_page/2')->toString(),
        CanvasUriDefinitions::LINK_REL_SET_AS_HOMEPAGE => Url::fromUri('base:/canvas/editor/canvas_page/2')->toString(),
      ],
      $data_by_id[2]['links'],
      'Links for page 2 should not include delete operation, as it is set as homepage.'
    );
    // Assert links for page 1.
    \assert(\array_key_exists(1, $data_by_id) && \array_key_exists('links', $data_by_id[1]));
    $this->assertEquals(
      [
        CanvasUriDefinitions::LINK_REL_EDIT => Url::fromUri('base:/canvas/editor/canvas_page/1')->toString(),
        CanvasUriDefinitions::LINK_REL_SET_AS_HOMEPAGE => Url::fromUri('base:/canvas/editor/canvas_page/1')->toString(),
        CanvasUriDefinitions::LINK_REL_UNPUBLISH => Url::fromUri('base:/canvas/api/v0/content/auto-save/canvas_page/1')->toString(),
        CanvasUriDefinitions::LINK_REL_DELETE => Url::fromUri('base:/canvas/api/v0/content/canvas_page/1')->toString(),
      ],
      $data_by_id[1]['links'],
      'Links for page 1 should include delete and unpublish operations.'
    );
    // Assert links for page 3.
    \assert(\array_key_exists(3, $data_by_id) && \array_key_exists('links', $data_by_id[3]));
    $this->assertEquals(
      [
        CanvasUriDefinitions::LINK_REL_EDIT => Url::fromUri('base:/canvas/editor/canvas_page/3')->toString(),
        CanvasUriDefinitions::LINK_REL_UNPUBLISH => Url::fromUri('base:/canvas/api/v0/content/auto-save/canvas_page/3')->toString(),
        CanvasUriDefinitions::LINK_REL_DELETE => Url::fromUri('base:/canvas/api/v0/content/canvas_page/3')->toString(),
        CanvasUriDefinitions::LINK_REL_SET_AS_HOMEPAGE => Url::fromUri('base:/canvas/editor/canvas_page/3')->toString(),
      ],
      $data_by_id[3]['links'],
      'Links for page 3 should include delete and unpublish operations.'
    );
  }

  public function testDuplicate(): void {
    $url = Url::fromUri('base:/canvas/api/v0/content/canvas_page');
    $request_options = [
      RequestOptions::HEADERS => [
        'Content-Type' => 'application/json',
      ],
      RequestOptions::JSON => [
        'entity_id' => '10',
        // The clientInstanceId is mandatory for duplicating an entity!
        'clientInstanceId' => 'client-434',
      ],
    ];

    $this->assertAuthenticationAndAuthorization($url, 'POST');
    // Authenticated, authorized, with CSRF token: 204.
    $request_options['headers']['X-CSRF-Token'] = $this->drupalGet('session/token');
    Role::load('authenticated')?->grantPermission(Page::CREATE_PERMISSION)->save();
    // Grant 'access content' permission so the user can view published pages
    // to duplicate them.
    Role::load('authenticated')?->grantPermission('access content')->save();
    // Grant 'edit canvas_page' permission so the user can view unpublished
    // pages (needed when duplicating a duplicate, which is unpublished).
    Role::load('authenticated')?->grantPermission(Page::EDIT_PERMISSION)->save();

    // Try to duplicate a non-existent entity.
    $response = $this->makeApiRequest('POST', $url, $request_options);
    $this->assertSame(404, $response->getStatusCode());
    $this->assertSame(
      '{"error":"Cannot find entity to duplicate."}',
      (string) $response->getBody()
    );

    $original = \Drupal::entityTypeManager()->getStorage('canvas_page')->load(1);
    \assert($original instanceof ContentEntityInterface);
    $this->assertEquals('Page 1', $original->label());
    self::assertFalse($original->get('path')->isEmpty());
    self::assertNotNull($original->get('path')->first()?->get('alias')->getValue());

    $request_options[RequestOptions::JSON] = [
      'entity_id' => $original->id(),
      'clientInstanceId' => 'client-132',
    ];

    // Test module will return view access forbidden for canvas_page id 1 instance.
    $this->container->get('module_installer')->install(['canvas_test_access']);

    // Try to duplicate entity without view access.
    $response = $this->makeApiRequest('POST', $url, $request_options);
    $this->assertSame(404, $response->getStatusCode());
    $this->assertSame(
      '{"error":"Cannot find entity to duplicate."}',
      (string) $response->getBody()
    );

    // Turn off module to have proper view access.
    $this->container->get('module_installer')->uninstall(['canvas_test_access']);
    // Duplicate Page 1 entity.
    $response = $this->makeApiRequest('POST', $url, $request_options);
    $this->assertSame(201, $response->getStatusCode());
    $this->assertPostResponse($response, [
      'entity_type' => Page::ENTITY_TYPE_ID,
      'entity_id' => '4',
      'title' => 'Page 1 (Copy)',
      'status' => FALSE,
      'path' => Url::fromUri('base://page/4')->toString(),
      'components' => [],
    ]);
    $duplicate_1 = \Drupal::entityTypeManager()->getStorage('canvas_page')->load(4);
    \assert($duplicate_1 instanceof ContentEntityInterface);
    $this->assertEquals('Page 1 (Copy)', $duplicate_1->label());
    self::assertNull($duplicate_1->get('path')->first()?->get('alias')->getValue());

    // Add temp store data for Previous duplicate.
    $auto_save_manager = \Drupal::service(AutoSaveManager::class);
    $duplicate_1->set('title', 'Title from temp store');
    $auto_save_manager->saveEntity($duplicate_1);

    $url = Url::fromUri('base:/canvas/api/v0/content/canvas_page');
    $request_options[RequestOptions::JSON] = [
      'entity_id' => $duplicate_1->id(),
      'clientInstanceId' => 'client-434',
    ];
    $response = $this->makeApiRequest('POST', $url, $request_options);
    $this->assertSame(201, $response->getStatusCode());
    $this->assertPostResponse($response, [
      'entity_type' => Page::ENTITY_TYPE_ID,
      'entity_id' => '5',
      'title' => 'Title from temp store (Copy)',
      'status' => FALSE,
      'path' => Url::fromUri('base://page/5')->toString(),
      'components' => [],
    ]);
    $duplicate_2 = \Drupal::entityTypeManager()->getStorage('canvas_page')->load(5);
    \assert($duplicate_2 instanceof EntityInterface);
    // Test that the data from the temp store is present.
    $this->assertEquals('Title from temp store (Copy)', $duplicate_2->label());
    $this->assertNotEmpty($auto_save_manager->getAutoSaveEntity($original));
    // Autosaved data is empty in duplicate.
    self::assertTrue($auto_save_manager->getAutoSaveEntity($duplicate_2)->isEmpty());
    self::assertNull($duplicate_2->get('path')->first()?->get('alias')->getValue());
  }

  /**
   * Assert values from a response body contents.
   *
   * This is to avoid having to ::assertSame() on values we don't really care
   * about for the purpose of that test.
   *
   * @param \Psr\Http\Message\ResponseInterface $response
   *   The received response object.
   * @param array<string, mixed> $expected_map
   *   The expected values on the response body contents.
   */
  private function assertPostResponse(ResponseInterface $response, array $expected_map): void {
    $responseBody = (string) $response->getBody();
    self::assertIsString($responseBody);
    self::assertJson($responseBody);
    $decodedBody = \json_decode($responseBody, TRUE);
    foreach ($expected_map as $key => $value) {
      $this->assertSame($decodedBody[$key], $value);
    }
  }

  /**
   * Tests unpublishing and publishing canvas pages via the PATCH API.
   */
  public function testPatchUnpublishPublish(): void {
    $request_options = [
      RequestOptions::HEADERS => [
        'Content-Type' => 'application/json',
      ],
    ];

    // Test unpublishing a published page.
    $url = Url::fromUri('base:/canvas/api/v0/content/auto-save/canvas_page/1');

    // Test authentication and authorization for PATCH.
    $this->assertAuthenticationAndAuthorization($url, 'PATCH');

    $request_options['headers']['X-CSRF-Token'] = $this->drupalGet('session/token');
    Role::load('authenticated')?->grantPermission(Page::EDIT_PERMISSION)->save();

    // Test that unexpected fields in request body return an error.
    $request_options[RequestOptions::JSON] = ['status' => FALSE, 'unexpected_field' => 'value'];
    $response = $this->makeApiRequest('PATCH', $url, $request_options);
    $this->assertSame(400, $response->getStatusCode());
    $response_data = json_decode((string) $response->getBody(), TRUE);
    $this->assertStringContainsString('Unexpected fields in request body:', $response_data['error']);
    $this->assertStringContainsString('unexpected_field', $response_data['error']);

    // Test that missing 'status' field returns an error.
    $request_options[RequestOptions::JSON] = [];
    $response = $this->makeApiRequest('PATCH', $url, $request_options);
    $this->assertSame(400, $response->getStatusCode());
    $this->assertSame(
      ['error' => 'Missing required field: status'],
      json_decode((string) $response->getBody(), TRUE)
    );

    // Unpublish page 1 (published -> unpublished).
    $request_options[RequestOptions::JSON] = ['status' => FALSE];
    $response = $this->makeApiRequest('PATCH', $url, $request_options);
    $this->assertSame(204, $response->getStatusCode());

    // Verify the page is unpublished via auto-save.
    $page_1 = Page::load(1);
    \assert($page_1 instanceof Page);
    $this->assertTrue($page_1->isPublished(), 'Original page should still be published.');

    $autoSaveManager = $this->container->get(AutoSaveManager::class);
    $autoSaveData = $autoSaveManager->getAutoSaveEntity($page_1);
    $this->assertFalse($autoSaveData->isEmpty(), 'Auto-save data should exist.');
    \assert($autoSaveData->entity instanceof EntityPublishedInterface);
    $this->assertFalse($autoSaveData->entity->isPublished(), 'Auto-saved page should be unpublished.');

    // Test publishing an unpublished page.
    $request_options[RequestOptions::JSON] = ['status' => TRUE];
    $response = $this->makeApiRequest('PATCH', $url, $request_options);
    $this->assertSame(204, $response->getStatusCode());

    // Verify the auto-save is now empty because both original and auto-save are published.
    $autoSaveData = $autoSaveManager->getAutoSaveEntity($page_1);
    $this->assertTrue($autoSaveData->isEmpty(), 'Auto-save should be empty when it matches the original.');

    // Try to unpublish the homepage (page 2).
    // First, publish page 2 so it can be unpublished.
    $page_2 = Page::load(2);
    \assert($page_2 instanceof Page);
    $page_2->setPublished()->save();

    $url = Url::fromUri('base:/canvas/api/v0/content/auto-save/canvas_page/2');
    $request_options[RequestOptions::JSON] = ['status' => FALSE];
    $response = $this->makeApiRequest('PATCH', $url, $request_options);
    $this->assertSame(403, $response->getStatusCode());
    $this->assertSame(
      ['error' => 'Cannot unpublish the homepage. Please set a different page as the homepage first.'],
      json_decode((string) $response->getBody(), TRUE)
    );

    // Verify that unpublish/publish operations work correctly with clientInstanceId.
    $url = Url::fromUri('base:/canvas/api/v0/content/auto-save/canvas_page/3');
    $request_options[RequestOptions::JSON] = [
      'status' => FALSE,
      'clientInstanceId' => 'test-client-123',
    ];
    $response = $this->makeApiRequest('PATCH', $url, $request_options);
    $this->assertSame(204, $response->getStatusCode());

    $page_3 = Page::load(3);
    \assert($page_3 instanceof Page);
    $autoSaveData = $autoSaveManager->getAutoSaveEntity($page_3);
    $this->assertFalse($autoSaveData->isEmpty());
    \assert($autoSaveData->entity instanceof EntityPublishedInterface);
    $this->assertFalse($autoSaveData->entity->isPublished());
  }

  /**
   * Tests the presence of unpublish and publish links in the canvas page list API.
   */
  public function testUnpublishPublishLinksInList(): void {
    $url = Url::fromUri('base:/canvas/api/v0/content/canvas_page');

    $user = $this->createUser([Page::EDIT_PERMISSION], 'edit_canvas_page_user');
    \assert($user instanceof UserInterface);
    $this->drupalLogin($user);

    $list_cache_contexts = ['url.query_args:page', 'url.query_args:search', 'user.permissions'];
    $list_cache_tags = [AutoSaveManager::CACHE_TAG, 'config:system.site', 'http_response', 'canvas_page:2', 'canvas_page_list'];

    // Get the initial list.
    $body = $this->assertExpectedResponse('GET', $url, [], 200, $list_cache_contexts, $list_cache_tags, 'UNCACHEABLE (request policy)', 'MISS');
    \assert(\is_array($body));
    $data_by_id = \array_column($body['data'], NULL, 'id');

    // Page 1 is published, should have unpublish link and set-as-homepage link.
    \assert(\array_key_exists(1, $data_by_id) && \array_key_exists('links', $data_by_id[1]));
    $this->assertArrayHasKey(CanvasUriDefinitions::LINK_REL_UNPUBLISH, $data_by_id[1]['links'], 'Published page should have unpublish link.');
    $this->assertArrayNotHasKey(CanvasUriDefinitions::LINK_REL_PUBLISH, $data_by_id[1]['links'], 'Published page should not have publish link.');
    $this->assertArrayHasKey(CanvasUriDefinitions::LINK_REL_SET_AS_HOMEPAGE, $data_by_id[1]['links'], 'Published page should have set-as-homepage link.');
    $this->assertFalse($data_by_id[1]['isNew'], 'Published page should not be marked as new.');

    // Page 2 is unpublished draft (never published), should have set-as-homepage link but not unpublish or publish link.
    \assert(\array_key_exists(2, $data_by_id) && \array_key_exists('links', $data_by_id[2]));
    $this->assertArrayNotHasKey(CanvasUriDefinitions::LINK_REL_UNPUBLISH, $data_by_id[2]['links'], 'Draft page should not have unpublish link.');
    $this->assertArrayNotHasKey(CanvasUriDefinitions::LINK_REL_PUBLISH, $data_by_id[2]['links'], 'Draft page should not have publish link.');
    $this->assertArrayHasKey(CanvasUriDefinitions::LINK_REL_SET_AS_HOMEPAGE, $data_by_id[2]['links'], 'Draft page should have set-as-homepage link.');
    $this->assertTrue($data_by_id[2]['isNew'], 'Draft page should be marked as new.');

    // Create an unpublished page (published then unpublished).
    $unpublished_page = Page::create([
      'title' => 'Unpublished Page',
      'status' => TRUE,
    ]);
    $unpublished_page->save();
    $unpublished_page->setNewRevision(TRUE);
    $unpublished_page->setUnpublished()->save();

    // Fetch the list again and check the unpublished page.
    $body = $this->assertExpectedResponse('GET', $url, [], 200, $list_cache_contexts, $list_cache_tags, 'UNCACHEABLE (request policy)', 'MISS');
    \assert(\is_array($body));
    $data_by_id = \array_column($body['data'], NULL, 'id');

    $unpublished_page_id = (int) $unpublished_page->id();
    \assert(\array_key_exists($unpublished_page_id, $data_by_id) && \array_key_exists('links', $data_by_id[$unpublished_page_id]));
    $this->assertArrayNotHasKey(CanvasUriDefinitions::LINK_REL_UNPUBLISH, $data_by_id[$unpublished_page_id]['links'], 'Unpublished page should not have unpublish link.');
    $this->assertArrayHasKey(CanvasUriDefinitions::LINK_REL_PUBLISH, $data_by_id[$unpublished_page_id]['links'], 'Unpublished page should have publish link.');
    $this->assertArrayNotHasKey(CanvasUriDefinitions::LINK_REL_SET_AS_HOMEPAGE, $data_by_id[$unpublished_page_id]['links'], 'Unpublished page should not have set-as-homepage link.');
    $this->assertFalse($data_by_id[$unpublished_page_id]['isNew'], 'Unpublished page should not be marked as new.');
    $this->assertFalse($data_by_id[$unpublished_page_id]['status'], 'Unpublished page should have status false.');

    // Test that auto-save unpublish operation shows correct links.
    $autoSaveManager = $this->container->get(AutoSaveManager::class);
    $page_1 = Page::load(1);
    \assert($page_1 instanceof Page);
    $page_1->setUnpublished();
    $autoSaveManager->saveEntity($page_1);

    $body = $this->assertExpectedResponse('GET', $url, [], 200, $list_cache_contexts, $list_cache_tags, 'UNCACHEABLE (request policy)', 'MISS');
    \assert(\is_array($body));
    $data_by_id = \array_column($body['data'], NULL, 'id');

    // Page 1 now has auto-save with unpublished status.
    \assert(\array_key_exists(1, $data_by_id) && \array_key_exists('links', $data_by_id[1]));
    $this->assertArrayNotHasKey(CanvasUriDefinitions::LINK_REL_UNPUBLISH, $data_by_id[1]['links'], 'Page with auto-saved unpublished status should not have unpublish link.');
    $this->assertArrayHasKey(CanvasUriDefinitions::LINK_REL_PUBLISH, $data_by_id[1]['links'], 'Page with auto-saved unpublished status should have publish link (revert).');
    $this->assertArrayNotHasKey(CanvasUriDefinitions::LINK_REL_SET_AS_HOMEPAGE, $data_by_id[1]['links'], 'Page with auto-saved unpublished status should not have set-as-homepage link.');
    $this->assertFalse($data_by_id[1]['status'], 'Page should show auto-saved unpublished status.');
  }

  private function assertAuthenticationAndAuthorization(Url $url, string $method): void {
    $request_options = [
      RequestOptions::HEADERS => [
        'Content-Type' => 'application/json',
      ],
    ];

    // Authenticated but unauthorized: 403 due to missing CSRF token.
    $user = $this->createUser([]);
    \assert($user instanceof UserInterface);
    $this->drupalLogin($user);
    if ($method !== 'GET') {
      $response = $this->makeApiRequest($method, $url, $request_options);
      $this->assertSame(403, $response->getStatusCode());
      $this->assertSame(
        ['errors' => ['X-CSRF-Token request header is missing']],
        json_decode((string) $response->getBody(), TRUE)
      );
    }

    // Authenticated but unauthorized: 403 due to missing permission.
    $request_options['headers']['X-CSRF-Token'] = $this->drupalGet('session/token');
    $response = $this->makeApiRequest($method, $url, $request_options);
    $this->assertSame(403, $response->getStatusCode());

    $error = match ($method) {
      'POST' => "The 'create canvas_page' permission is required.",
      'DELETE' => "The 'delete canvas_page' permission is required.",
      'PATCH' => "The 'edit canvas_page' permission is required.",
      // GET method
      default => "The 'edit canvas_page' permission is required.",
    };
    $this->assertSame(
      ['errors' => [$error]],
      json_decode((string) $response->getBody(), TRUE)
    );
  }

}
