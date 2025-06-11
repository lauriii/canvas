<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Functional;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Url;
use Drupal\experience_builder\AutoSave\AutoSaveManager;
use Drupal\experience_builder\Entity\Page;
use Drupal\experience_builder\XbUriDefinitions;
use Drupal\user\Entity\Role;
use Drupal\user\UserInterface;
use GuzzleHttp\RequestOptions;

/**
 * @covers \Drupal\experience_builder\Controller\ApiContentControllers
 * @group experience_builder
 * @group #slow
 * @internal
 */
final class XbContentEntityHttpApiTest extends HttpApiTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'experience_builder',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    Page::create([
      'title' => "Page 1",
      'status' => TRUE,
      'path' => ['alias' => "/page-1"],
    ])->save();
    Page::create([
      'title' => "Page 2",
      'status' => FALSE,
    ])->save();
    Page::create([
      'title' => "Page 3",
      'status' => TRUE,
      'path' => ['alias' => "/page-3"],
    ])->save();
  }

  public function testPost(): void {
    $url = Url::fromUri('base:/xb/api/v0/content/xb_page');
    $request_options = [
      RequestOptions::HEADERS => [
        'Content-Type' => 'application/json',
      ],
      RequestOptions::JSON => [],
    ];

    $this->assertAuthenticationAndAuthorization($url, 'POST');

    $request_options['headers']['X-CSRF-Token'] = $this->drupalGet('session/token');
    // Authenticated, authorized, with CSRF token: 201.
    Role::load('authenticated')?->grantPermission(Page::CREATE_PERMISSION)->save();
    $response = $this->makeApiRequest('POST', $url, $request_options);
    $this->assertSame(201, $response->getStatusCode());
    $this->assertSame(
      '{"entity_type":"xb_page","entity_id":"4"}',
      (string) $response->getBody()
    );
  }

  public function testList(): void {
    $url = Url::fromUri('base:/xb/api/v0/content/xb_page');

    $this->assertAuthenticationAndAuthorization($url, 'GET');

    // Authenticated, authorized: 200.
    $user = $this->createUser([Page::EDIT_PERMISSION], 'administer_xb_page_user');
    assert($user instanceof UserInterface);
    $this->drupalLogin($user);
    $body = $this->assertExpectedResponse('GET', $url, [], 200, ['user.permissions'], [AutoSaveManager::CACHE_TAG, 'http_response', 'xb_page_list'], 'UNCACHEABLE (request policy)', 'MISS');
    $no_auto_save_expected_pages = [
      // Page 1 has a path alias.
      '1' => [
        'id' => 1,
        'title' => 'Page 1',
        'status' => TRUE,
        'path' => base_path() . 'page-1',
        'autoSaveLabel' => NULL,
        'autoSavePath' => NULL,
        'links' => [
          // @todo https://www.drupal.org/i/3498525 should standardize arguments.
          XbUriDefinitions::LINK_REL_EDIT => Url::fromUri('base:/xb/xb_page/1')->toString(),
          XbUriDefinitions::LINK_REL_DUPLICATE => Url::fromUri('base:/xb/api/v0/content/xb_page')->toString(),
        ],
      ],
      // Page 2 has no path alias.
      '2' => [
        'id' => 2,
        'title' => 'Page 2',
        'status' => FALSE,
        'path' => base_path() . 'page/2',
        'autoSaveLabel' => NULL,
        'autoSavePath' => NULL,
        'links' => [
          // @todo https://www.drupal.org/i/3498525 should standardize arguments.
          XbUriDefinitions::LINK_REL_EDIT => Url::fromUri('base:/xb/xb_page/2')->toString(),
          XbUriDefinitions::LINK_REL_DUPLICATE => Url::fromUri('base:/xb/api/v0/content/xb_page')->toString(),
        ],
      ],
      '3' => [
        'id' => 3,
        'title' => 'Page 3',
        'status' => TRUE,
        'path' => base_path() . 'page-3',
        'autoSaveLabel' => NULL,
        'autoSavePath' => NULL,
        'links' => [
          // @todo https://www.drupal.org/i/3498525 should standardize arguments.
          XbUriDefinitions::LINK_REL_EDIT => Url::fromUri('base:/xb/xb_page/3')->toString(),
          XbUriDefinitions::LINK_REL_DUPLICATE => Url::fromUri('base:/xb/api/v0/content/xb_page')->toString(),
        ],
      ],
    ];
    $this->assertEquals(
      $no_auto_save_expected_pages,
      $body
    );
    $this->assertExpectedResponse('GET', $url, [], 200, ['user.permissions'], [AutoSaveManager::CACHE_TAG, 'http_response', 'xb_page_list'], 'UNCACHEABLE (request policy)', 'HIT');

    $autoSaveManager = $this->container->get(AutoSaveManager::class);
    $page_1 = Page::load(1);
    $this->assertInstanceOf(Page::class, $page_1);
    $autoSaveManager->save(
      $page_1,
      [
        'layout' => [],
        'model' => [],
        'entity_form_fields' => [
          'title[0][value]' => 'The updated title.',
          'path[0][alias]' => '/the-updated-path',
        ],
      ]
    );
    $page_2 = Page::load(2);
    $this->assertInstanceOf(Page::class, $page_2);
    $autoSaveManager->save(
      $page_2,
      [
        'layout' => [],
        'model' => [],
        'entity_form_fields' => [
          'title[0][value]' => 'The updated title2.',
          'path[0][alias]' => '/the-new-path',
        ],
      ]
    );

    $body = $this->assertExpectedResponse('GET', $url, [], 200, ['user.permissions'], [AutoSaveManager::CACHE_TAG, 'http_response', 'xb_page_list'], 'UNCACHEABLE (request policy)', 'MISS');
    $auto_save_expected_pages = $no_auto_save_expected_pages;
    $auto_save_expected_pages['1']['autoSaveLabel'] = 'The updated title.';
    $auto_save_expected_pages['1']['autoSavePath'] = '/the-updated-path';
    $auto_save_expected_pages['2']['autoSaveLabel'] = 'The updated title2.';
    $auto_save_expected_pages['2']['autoSavePath'] = '/the-new-path';
    $this->assertEquals(
      $auto_save_expected_pages,
      $body
    );
    $this->assertExpectedResponse('GET', $url, [], 200, ['user.permissions'], [AutoSaveManager::CACHE_TAG, 'http_response', 'xb_page_list'], 'UNCACHEABLE (request policy)', 'HIT');

    // Confirm that if path alias is empty, the system path is used, not the
    // existing alias if set.
    $autoSaveManager->save(
      $page_1,
      [
        'layout' => [],
        'model' => [],
        'entity_form_fields' => [
          'title[0][value]' => 'The updated title.',
          'path[0][alias]' => '',
        ],
      ]
    );
    $autoSaveManager->save(
      $page_2,
      [
        'layout' => [],
        'model' => [],
        'entity_form_fields' => [
          'title[0][value]' => 'The updated title2.',
          'path[0][alias]' => '',
        ],
      ]
    );
    $body = $this->assertExpectedResponse('GET', $url, [], 200, ['user.permissions'], [AutoSaveManager::CACHE_TAG, 'http_response', 'xb_page_list'], 'UNCACHEABLE (request policy)', 'MISS');
    $auto_save_expected_pages['1']['autoSavePath'] = '/page/1';
    $auto_save_expected_pages['2']['autoSavePath'] = '/page/2';
    $this->assertEquals(
      $auto_save_expected_pages,
      $body
    );

    $autoSaveManager->delete($page_1);
    $autoSaveManager->delete($page_2);
    $body = $this->assertExpectedResponse('GET', $url, [], 200, ['user.permissions'], [AutoSaveManager::CACHE_TAG, 'http_response', 'xb_page_list'], 'UNCACHEABLE (request policy)', 'MISS');
    $this->assertEquals(
      $no_auto_save_expected_pages,
      $body
    );
    $this->assertExpectedResponse('GET', $url, [], 200, ['user.permissions'], [AutoSaveManager::CACHE_TAG, 'http_response', 'xb_page_list'], 'UNCACHEABLE (request policy)', 'HIT');
  }

  /**
   * @dataProvider metaOperationsProvider
   */
  public function testListMetaOperations(array $permissions, array $expectedLinks, array $extraCacheContexts = [], array $extraCacheTags = []): void {
    $url = Url::fromUri('base:/xb/api/v0/content/xb_page');
    array_walk($expectedLinks, fn(&$value) => $value = Url::fromUri($value)->toString());
    // Enable xb_test_access, which will disable view permission for page 1
    // and add extra cache contexts and cache tags.
    $this->container->get('module_installer')->install(['xb_test_access']);
    \Drupal::keyValue('xb_test_access')->set('cache_contexts', $extraCacheContexts);
    \Drupal::keyValue('xb_test_access')->set('cache_tags', $extraCacheTags);

    $user = $this->createUser($permissions);
    assert($user instanceof UserInterface);
    $this->drupalLogin($user);

    $body = $this->assertExpectedResponse('GET', $url, [], 200, Cache::mergeContexts(['user.permissions'], $extraCacheContexts), Cache::mergeTags([AutoSaveManager::CACHE_TAG, 'http_response', 'xb_page_list'], $extraCacheTags), 'UNCACHEABLE (request policy)', 'MISS');
    assert(\is_array($body));
    assert(\array_key_exists('1', $body) && \array_key_exists('links', $body['1']));
    $this->assertEquals(
      $expectedLinks,
      $body['1']['links']
    );
  }

  public static function metaOperationsProvider(): array {
    return [
      'can edit' => [
        [Page::EDIT_PERMISSION],
        [
          XbUriDefinitions::LINK_REL_EDIT => 'base:/xb/xb_page/1',
          XbUriDefinitions::LINK_REL_DUPLICATE => 'base:/xb/api/v0/content/xb_page',
        ],
      ],
      'can edit and delete' => [
        [Page::EDIT_PERMISSION, Page::DELETE_PERMISSION],
        [
          XbUriDefinitions::LINK_REL_EDIT => 'base:/xb/xb_page/1',
          XbUriDefinitions::LINK_REL_DUPLICATE => 'base:/xb/api/v0/content/xb_page',
          XbUriDefinitions::LINK_REL_DELETE => 'base:/xb/api/v0/content/xb_page/1',
        ],
      ],
      'can edit and create' => [
        [Page::EDIT_PERMISSION, Page::CREATE_PERMISSION],
        [
          XbUriDefinitions::LINK_REL_EDIT => 'base:/xb/xb_page/1',
          XbUriDefinitions::LINK_REL_DUPLICATE => 'base:/xb/api/v0/content/xb_page',
        ],
        ['headers:X-Something'],
        ['zzz'],
      ],
    ];
  }

  public function testDelete(): void {
    $url = Url::fromUri('base:/xb/api/v0/content/xb_page/1');
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
    $this->assertSame(['errors' => ["The 'delete xb_page' permission is required."]], json_decode((string) $response->getBody(), TRUE));

    // Authenticated, authorized, with CSRF token: 204.
    Role::load('authenticated')?->grantPermission(Page::DELETE_PERMISSION)->save();
    $response = $this->makeApiRequest('DELETE', $url, $request_options);
    $this->assertSame(204, $response->getStatusCode());
    $this->assertNull(\Drupal::entityTypeManager()->getStorage(Page::ENTITY_TYPE_ID)->load(1));
  }

  public function testDuplicate(): void {
    $url = Url::fromUri('base:/xb/api/v0/content/xb_page');
    $request_options = [
      RequestOptions::HEADERS => [
        'Content-Type' => 'application/json',
      ],
      RequestOptions::JSON => ['entity_id' => '10'],
    ];

    $this->assertAuthenticationAndAuthorization($url, 'POST');
    // Authenticated, authorized, with CSRF token: 204.
    $request_options['headers']['X-CSRF-Token'] = $this->drupalGet('session/token');
    Role::load('authenticated')?->grantPermission(Page::CREATE_PERMISSION)->save();

    // Try to duplicate a non-existent entity.
    $response = $this->makeApiRequest('POST', $url, $request_options);
    $this->assertSame(404, $response->getStatusCode());
    $this->assertSame(
      '{"error":"Cannot find entity to duplicate."}',
      (string) $response->getBody()
    );

    $request_options[RequestOptions::JSON] = ['entity_id' => '1'];

    // Test module will return view access forbidden for xb_page id 1 instance.
    $this->container->get('module_installer')->install(['xb_test_access']);

    // Try to duplicate entity without view access.
    $response = $this->makeApiRequest('POST', $url, $request_options);
    $this->assertSame(404, $response->getStatusCode());
    $this->assertSame(
      '{"error":"Cannot find entity to duplicate."}',
      (string) $response->getBody()
    );

    // Turn off module to have proper view access.
    $this->container->get('module_installer')->uninstall(['xb_test_access']);
    // Duplicate Page 1 entity.
    $response = $this->makeApiRequest('POST', $url, $request_options);
    $this->assertSame(201, $response->getStatusCode());
    $this->assertSame(
      '{"entity_type":"xb_page","entity_id":"4"}',
      (string) $response->getBody()
    );
    $original = \Drupal::entityTypeManager()->getStorage('xb_page')->load(4);
    assert($original instanceof EntityInterface);
    $this->assertEquals('Page 1 (Copy)', $original->label());

    // Add temp store data for Previous duplicate.
    assert($original instanceof EntityInterface);
    $auto_save_manager = \Drupal::service(AutoSaveManager::class);
    $auto_save_manager->save($original, [
      'layout' => [
        0 => [
          'nodeType' => 'region',
          'id' => 'content',
          'name' => 'Content',
          'components' => [],
        ],
      ],
      'model' => [],
      'entity_form_fields' => [
        'title[0][value]' => 'Title from temp store',
      ],
    ]);

    $url = Url::fromUri('base:/xb/api/v0/content/xb_page');
    $request_options[RequestOptions::JSON] = ['entity_id' => '4'];
    $response = $this->makeApiRequest('POST', $url, $request_options);
    $this->assertSame(201, $response->getStatusCode());
    $this->assertSame(
      '{"entity_type":"xb_page","entity_id":"5"}',
      (string) $response->getBody()
    );

    $duplicate = \Drupal::entityTypeManager()->getStorage('xb_page')->load(5);
    assert($duplicate instanceof EntityInterface);
    // Test that the data from the temp store is present.
    $this->assertEquals('Title from temp store (Copy)', $duplicate->label());
    $this->assertNotEmpty($auto_save_manager->getAutoSaveData($original));
    // Autosaved data is empty on duplicate.
    $this->assertEmpty($auto_save_manager->getAutoSaveData($duplicate)->data);
  }

  private function assertAuthenticationAndAuthorization(Url $url, string $method): void {
    $request_options = [
      RequestOptions::HEADERS => [
        'Content-Type' => 'application/json',
      ],
    ];

    // Authenticated but unauthorized: 403 due to missing CSRF token.
    $user = $this->createUser([]);
    assert($user instanceof UserInterface);
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
      'POST' => "The 'create xb_page' permission is required.",
      'DELETE' => "The 'delete xb_page' permission is required.",
      // GET method
      default => "The 'edit xb_page' permission is required.",
    };
    $this->assertSame(
      ['errors' => [$error]],
      json_decode((string) $response->getBody(), TRUE)
    );
  }

}
