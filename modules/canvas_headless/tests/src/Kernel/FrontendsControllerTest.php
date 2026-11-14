<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_headless\Kernel;

use Drupal\canvas_headless\Cache\FrontendCacheTagInvalidator;
use Drupal\canvas_headless\Controller\FrontendsController;
use Drupal\Component\Utility\Crypt;
use Drupal\Core\Routing\RouteProviderInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response as FrontendResponse;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Psr\Http\Message\RequestInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tests the headless frontend configuration controller.
 */
#[Group('canvas_headless')]
#[RunTestsInSeparateProcesses]
final class FrontendsControllerTest extends CanvasKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'path_alias',
    'serialization',
    'custom_elements',
    'consumers',
    'simple_oauth',
    'canvas_headless',
  ];

  /**
   * Tests reading and replacing the ordered frontend list.
   */
  public function testGetAndPatch(): void {
    $this->installConfig(['canvas_headless']);
    $controller = FrontendsController::create($this->container);

    self::assertSame(['frontends' => []], self::decode($controller->get()));

    $response = $controller->patch(self::request([
      'frontends' => [
        ['url' => 'https://first.example/app'],
        ['url' => 'http://127.0.0.1:3000'],
      ],
    ]));
    self::assertSame(Response::HTTP_OK, $response->getStatusCode());
    self::assertSame([
      'frontends' => [
        ['url' => 'https://first.example/app', 'components' => [], 'cacheInvalidation' => FALSE, 'cacheInvalidationStatus' => NULL],
        ['url' => 'http://127.0.0.1:3000', 'components' => [], 'cacheInvalidation' => FALSE, 'cacheInvalidationStatus' => NULL],
      ],
    ], self::decode($response));
    self::assertSame(self::decode($response), self::decode($controller->get()));
    self::assertSame([
      ['url' => 'https://first.example/app', 'components' => [], 'cache_invalidation' => FALSE],
      ['url' => 'http://127.0.0.1:3000', 'components' => [], 'cache_invalidation' => FALSE],
    ], $this->config('canvas_headless.settings')->get('frontends'));

    $this->config('canvas_headless.settings')
      ->set('frontends', [
        ['url' => 'https://first.example/app', 'components' => ['js.heroBanner'], 'cache_invalidation' => FALSE],
        ['url' => 'http://127.0.0.1:3000', 'components' => ['js.card'], 'cache_invalidation' => FALSE],
      ])
      ->save();

    $response = $controller->patch(self::request([
      'frontends' => [
        ['url' => 'http://127.0.0.1:3000'],
        ['url' => 'https://first.example/app'],
      ],
    ]));
    self::assertSame([
      'frontends' => [
        ['url' => 'http://127.0.0.1:3000', 'components' => ['js.card'], 'cacheInvalidation' => FALSE, 'cacheInvalidationStatus' => NULL],
        ['url' => 'https://first.example/app', 'components' => ['js.heroBanner'], 'cacheInvalidation' => FALSE, 'cacheInvalidationStatus' => NULL],
      ],
    ], self::decode($response));
  }

  /**
   * Tests the cache invalidation switch of a frontend, and its status.
   */
  public function testCacheInvalidationSwitch(): void {
    $this->installConfig(['canvas_headless']);
    $this->setPermissions(['administer canvas headless frontends']);
    $controller = FrontendsController::create($this->container);
    $idle = ['state' => 'idle', 'lastSuccess' => NULL, 'lastFailure' => NULL, 'lastError' => NULL, 'failures' => 0];

    $response = $controller->patch(self::request([
      'frontends' => [
        ['url' => 'https://first.example/app', 'cacheInvalidation' => TRUE],
        ['url' => 'https://second.example', 'cacheInvalidation' => FALSE],
      ],
    ]));
    self::assertSame([
      'frontends' => [
        ['url' => 'https://first.example/app', 'components' => [], 'cacheInvalidation' => TRUE, 'cacheInvalidationStatus' => $idle],
        ['url' => 'https://second.example', 'components' => [], 'cacheInvalidation' => FALSE, 'cacheInvalidationStatus' => NULL],
      ],
    ], self::decode($response));
    self::assertSame(self::decode($response), self::decode($controller->get()));
    self::assertSame([
      ['url' => 'https://first.example/app', 'components' => [], 'cache_invalidation' => TRUE],
      ['url' => 'https://second.example', 'components' => [], 'cache_invalidation' => FALSE],
    ], $this->config('canvas_headless.settings')->get('frontends'));

    // The status is that of the canonical base URL.
    $states = $this->container->get('keyvalue')->get(FrontendCacheTagInvalidator::STATE_COLLECTION);
    $failing = [
      'missed_at' => 1700000000.5,
      'pending' => [],
      'failures' => 2,
      'last_error' => 'HTTP 500',
      'last_failure' => 1700000000,
      'last_success' => 1600000000,
      'retry_after' => 1700000020,
    ];
    $states->set(Crypt::hashBase64('https://first.example/app'), ['url' => 'https://first.example/app'] + $failing);
    // A frontend whose switch is off has no state; one that is turned on
    // starts without one.
    $states->set(Crypt::hashBase64('https://second.example'), ['url' => 'https://second.example'] + $failing);

    // An omitted switch keeps its value, also for another spelling of the URL;
    // a new frontend starts without cache invalidation.
    $response = $controller->patch(self::request([
      'frontends' => [
        ['url' => 'https://third.example'],
        ['url' => 'https://second.example', 'cacheInvalidation' => TRUE],
        ['url' => 'HTTPS://FIRST.EXAMPLE:443/app'],
      ],
    ]));
    $status = ['state' => 'failing', 'lastSuccess' => 1600000000, 'lastFailure' => 1700000000, 'lastError' => 'HTTP 500', 'failures' => 2];
    self::assertSame([
      'frontends' => [
        ['url' => 'https://third.example', 'components' => [], 'cacheInvalidation' => FALSE, 'cacheInvalidationStatus' => NULL],
        ['url' => 'https://second.example', 'components' => [], 'cacheInvalidation' => TRUE, 'cacheInvalidationStatus' => $idle],
        ['url' => 'HTTPS://FIRST.EXAMPLE:443/app', 'components' => [], 'cacheInvalidation' => TRUE, 'cacheInvalidationStatus' => $status],
      ],
    ], self::decode($response));
    self::assertSame(['https://first.example/app'], array_column($states->getAll(), 'url'));

    // Users who can only preview get no status: errors can name hosts of the
    // site's network.
    $this->setPermissions(['access canvas headless preview']);
    self::assertSame([NULL, NULL, NULL], array_column(self::decode(FrontendsController::create($this->container)->get())['frontends'], 'cacheInvalidationStatus'));

    // The state of a frontend is forgotten when its switch is turned off, and
    // when it is removed.
    $controller->patch(self::request([
      'frontends' => [
        ['url' => 'https://first.example/app', 'cacheInvalidation' => FALSE],
      ],
    ]));
    self::assertSame([], $states->getAll());
    $response = $controller->patch(self::request([
      'frontends' => [
        ['url' => 'https://first.example/app', 'cacheInvalidation' => TRUE],
      ],
    ]));
    self::assertSame($idle, self::decode($response)['frontends'][0]['cacheInvalidationStatus']);

    // Changing the settings in any other way, such as a config import, does
    // the same.
    $key = Crypt::hashBase64('https://first.example/app');
    $states->set($key, ['url' => 'https://first.example/app'] + $failing);
    $settings = $this->config('canvas_headless.settings');
    $settings->set('frontends.0.cache_invalidation', FALSE)->save();
    self::assertSame([], $states->getAll());
    $states->set($key, ['url' => 'https://first.example/app'] + $failing);
    $settings->set('frontends.0.cache_invalidation', TRUE)->save();
    self::assertSame([], $states->getAll());
    // A frontend whose switch stays on keeps its state.
    $states->set($key, ['url' => 'https://first.example/app'] + $failing);
    $settings->set('frontends.0.components', [])->set('assertion_expiration', 61)->save();
    self::assertSame([$key], \array_keys($states->getAll()));
  }

  /**
   * Tests announcing to one frontend, and reporting whether it read the tags.
   */
  public function testCacheInvalidationTest(): void {
    $this->installConfig(['canvas_headless']);
    $this->config('canvas_headless.settings')
      ->set('frontends', [
        // A frontend can be tested before cache invalidation is enabled.
        ['url' => 'https://first.example/app', 'components' => [], 'cache_invalidation' => FALSE],
      ])
      ->save();

    $route = $this->container->get(RouteProviderInterface::class)->getRouteByName('canvas_headless.frontends.cache_invalidation_test');
    self::assertSame('/canvas/api/v0/headless/frontends/cache-invalidation/test', $route->getPath());
    self::assertSame(['POST'], $route->getMethods());
    self::assertSame(FrontendsController::class . '::testCacheInvalidation', $route->getDefault('_controller'));
    self::assertSame('administer canvas headless frontends', $route->getRequirement('_permission'));
    self::assertSame('TRUE', $route->getRequirement('_csrf_request_header_token'));
    self::assertSame(['cookie'], $route->getOption('_auth'));
    self::assertTrue($route->getOption('no_cache'));

    $read = [];
    $invalidator = $this->container->get(FrontendCacheTagInvalidator::class);
    // The frontend reads the announced tags before it answers.
    $respond = static function (RequestInterface $request) use (&$read, $invalidator): FrontendResponse {
      $read[] = $invalidator->redeem(json_decode((string) $request->getBody(), TRUE, flags: JSON_THROW_ON_ERROR)['id']);
      return new FrontendResponse(204);
    };
    $history = [];
    $handler = HandlerStack::create(new MockHandler([$respond, new FrontendResponse(404), $respond]));
    $handler->push(Middleware::history($history));
    $this->container->set('http_client', new Client(['handler' => $handler]));
    $controller = FrontendsController::create($this->container);

    foreach (['not JSON', '[]', '{"url": 1}', '{"url": "https://first.example/app", "expireAll": "yes"}'] as $malformed) {
      $response = $controller->testCacheInvalidation(Request::create('/canvas/api/v0/headless/frontends/cache-invalidation/test', 'POST', content: $malformed));
      self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode(), $malformed);
    }
    foreach (['https://other.example', 'https://first.example', 'https://first.example/app/'] as $url) {
      $response = $controller->testCacheInvalidation(self::request(['url' => $url]));
      self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode(), $url);
      self::assertSame(['error' => \sprintf('"%s" is not a configured frontend.', $url)], self::decode($response));
    }
    \assert(\is_array($history));
    self::assertCount(0, $history);

    // Another spelling of a configured frontend is that frontend.
    $response = $controller->testCacheInvalidation(self::request(['url' => 'HTTPS://FIRST.EXAMPLE/app']));
    self::assertSame(Response::HTTP_OK, $response->getStatusCode());
    self::assertSame([
      'ok' => TRUE,
      'announced' => TRUE,
      'read' => TRUE,
      'message' => 'The frontend received the announcement and read the cache tags.',
    ], self::decode($response));

    $response = $controller->testCacheInvalidation(self::request(['url' => 'https://first.example/app', 'expireAll' => FALSE]));
    self::assertSame(Response::HTTP_OK, $response->getStatusCode());
    self::assertSame([
      'ok' => FALSE,
      'announced' => FALSE,
      'read' => FALSE,
      'message' => 'The frontend answered HTTP 404.',
    ], self::decode($response));

    $response = $controller->testCacheInvalidation(self::request(['url' => 'https://first.example/app', 'expireAll' => TRUE]));
    self::assertTrue(self::decode($response)['ok']);

    // Testing a frontend whose switch is off keeps no state.
    self::assertSame([[FrontendCacheTagInvalidator::TEST_TAG], ['http_response']], $read);
    self::assertSame([], $this->container->get('keyvalue')->get(FrontendCacheTagInvalidator::STATE_COLLECTION)->getAll());
    self::assertCount(3, $history);
    self::assertSame('https://first.example/app/api/canvas/revalidate', (string) $history[0]['request']->getUri());
  }

  /**
   * Tests malformed, invalid, and duplicate entries.
   */
  public function testInvalidPayloads(): void {
    $this->installConfig(['canvas_headless']);
    $controller = FrontendsController::create($this->container);

    $response = $controller->patch(self::request(['frontends' => 'not a list']));
    self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());

    $response = $controller->patch(self::request([
      'frontends' => [['url' => 'https://example.com/']],
    ]));
    self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());

    $response = $controller->patch(self::request([
      'frontends' => [
        ['url' => 'https://example.com'],
        ['url' => 'HTTPS://EXAMPLE.COM:443'],
      ],
    ]));
    self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
    self::assertSame([
      'error' => '"HTTPS://EXAMPLE.COM:443" is already in the list.',
    ], self::decode($response));

    foreach (['yes', 1, NULL] as $cache_invalidation) {
      $response = $controller->patch(self::request([
        'frontends' => [['url' => 'https://example.com', 'cacheInvalidation' => $cache_invalidation]],
      ]));
      self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
      self::assertSame([
        'error' => 'The "cacheInvalidation" of frontend 0 must be a boolean.',
      ], self::decode($response));
    }
    self::assertSame([], $this->config('canvas_headless.settings')->get('frontends'));
  }

  /**
   * Builds a JSON request.
   *
   * @param array<string, mixed> $payload
   *   The request payload.
   */
  private static function request(array $payload): Request {
    return Request::create(
      '/canvas/api/v0/headless/frontends',
      'PATCH',
      content: json_encode($payload, JSON_THROW_ON_ERROR),
    );
  }

  /**
   * Decodes a controller response.
   *
   * @return array<string, mixed>
   *   The decoded response.
   */
  private static function decode(Response $response): array {
    $content = $response->getContent();
    self::assertIsString($content);
    $decoded = json_decode($content, TRUE, flags: JSON_THROW_ON_ERROR);
    self::assertIsArray($decoded);
    return $decoded;
  }

  /**
   * Makes the current user one with the given permissions.
   *
   * @param list<string> $permissions
   *   The permissions.
   */
  private function setPermissions(array $permissions): void {
    $account = $this->createMock(AccountInterface::class);
    $account->method('hasPermission')->willReturnCallback(static fn (string $permission): bool => \in_array($permission, $permissions, TRUE));
    $this->container->set('current_user', $account);
  }

}
