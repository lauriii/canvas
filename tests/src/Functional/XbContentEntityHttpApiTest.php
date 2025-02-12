<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Functional;

use Drupal\Core\Url;
use Drupal\experience_builder\Entity\Page;
use Drupal\user\Entity\Role;
use Drupal\user\UserInterface;
use GuzzleHttp\RequestOptions;

/**
 * @covers \Drupal\experience_builder\Controller\ApiContentControllers
 * @group experience_builder
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
      'path' => ['alias' => "/page-2"],
    ])->save();
    Page::create([
      'title' => "Page 3",
      'status' => TRUE,
      'path' => ['alias' => "/page-3"],
    ])->save();
  }

  public function testPost(): void {
    $url = Url::fromUri('base:/xb/api/content-create/xb_page');
    $request_options = [
      RequestOptions::HEADERS => [
        'Content-Type' => 'application/json',
      ],
    ];

    $this->assertAuthenticationAndAuthorization($url, 'POST');

    $request_options['headers']['X-CSRF-Token'] = $this->drupalGet('session/token');
    // Authenticated, authorized, with CSRF token: 201.
    // @phpstan-ignore-next-line
    Role::load('authenticated')->grantPermission('administer xb_page')->save();
    $response = $this->makeApiRequest('POST', $url, $request_options);
    $this->assertSame(201, $response->getStatusCode());
    $this->assertSame(
      '{"entity_type":"xb_page","entity_id":"4"}',
      (string) $response->getBody()
    );
  }

  public function testList(): void {
    $url = Url::fromUri('base:/xb/api/content/xb_page');

    $this->assertAuthenticationAndAuthorization($url, 'GET');

    // Authenticated, authorized: 200.
    $user = $this->createUser(['administer xb_page'], 'administer_xb_page_user');
    assert($user instanceof UserInterface);
    $this->drupalLogin($user);
    $body = $this->assertExpectedResponse('GET', $url, [], 200, ['user.permissions'], ['http_response', 'xb_page_list'], 'UNCACHEABLE (request policy)', 'MISS');
    $this->assertEquals(
      [
        '1' => [
          'id' => 1,
          'title' => 'Page 1',
          'status' => TRUE,
          'path' => base_path() . 'page-1',
        ],
        '2' => [
          'id' => 2,
          'title' => 'Page 2',
          'status' => FALSE,
          'path' => base_path() . 'page-2',
        ],
        '3' => [
          'id' => 3,
          'title' => 'Page 3',
          'status' => TRUE,
          'path' => base_path() . 'page-3',
        ],
      ],
      $body
    );
    $this->assertExpectedResponse('GET', $url, [], 200, ['user.permissions'], ['http_response', 'xb_page_list'], 'UNCACHEABLE (request policy)', 'HIT');
  }

  public function testDelete(): void {
    $url = Url::fromUri('base:/xb/api/content-delete/xb_page/1');
    $request_options = [
      RequestOptions::HEADERS => [
        'Content-Type' => 'application/json',
      ],
    ];

    $this->assertAuthenticationAndAuthorization($url, 'DELETE');

    $request_options['headers']['X-CSRF-Token'] = $this->drupalGet('session/token');
    // Authenticated, authorized, with CSRF token: 204.
    // @phpstan-ignore-next-line
    Role::load('authenticated')->grantPermission('administer xb_page')->save();
    $response = $this->makeApiRequest('DELETE', $url, $request_options);
    $this->assertSame(204, $response->getStatusCode());
    $this->assertNull(\Drupal::entityTypeManager()->getStorage('xb_page')->load(1));
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
    $this->assertSame(
      ['errors' => ["The 'administer xb_page' permission is required."]],
      json_decode((string) $response->getBody(), TRUE)
    );
  }

}
