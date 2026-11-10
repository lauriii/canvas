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
        ['url' => 'https://first.example/app', 'components' => [], 'cacheInvalidationStatus' => NULL],
        ['url' => 'http://127.0.0.1:3000', 'components' => [], 'cacheInvalidationStatus' => NULL],
      ],
    ], self::decode($response));
    self::assertSame(self::decode($response), self::decode($controller->get()));

    $this->config('canvas_headless.settings')
      ->set('frontends', [
        ['url' => 'https://first.example/app', 'components' => ['js.heroBanner']],
        ['url' => 'http://127.0.0.1:3000', 'components' => ['js.card']],
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
        ['url' => 'http://127.0.0.1:3000', 'components' => ['js.card'], 'cacheInvalidationStatus' => NULL],
        ['url' => 'https://first.example/app', 'components' => ['js.heroBanner'], 'cacheInvalidationStatus' => NULL],
      ],
    ], self::decode($response));
  }

  /**
   * Tests the cache invalidation status of the frontends.
   */
  public function testCacheInvalidationStatus(): void {
    $this->installConfig(['canvas_headless']);
    $this->setPermissions(['administer canvas headless frontends']);
    $controller = FrontendsController::create($this->container);
    $controller->patch(self::request([
      'frontends' => [
        ['url' => 'HTTPS://FIRST.EXAMPLE:443/app'],
        ['url' => 'https://second.example'],
        ['url' => 'https://third.example'],
      ],
    ]));

    $states = $this->container->get('keyvalue')->get(FrontendCacheTagInvalidator::STATE_COLLECTION);
    $state = [
      'missed_at' => 1700000000.5,
      'pending' => [],
      'failures' => 2,
      'last_error' => 'HTTP 500',
      'last_failure' => 1700000000,
      'last_success' => 1600000000,
      'retry_after' => 1700000020,
    ];
    // The status is that of the canonical base URL.
    $states->set(Crypt::hashBase64('https://first.example/app'), ['url' => 'https://first.example/app'] + $state);
    $states->set(Crypt::hashBase64('https://second.example'), ['url' => 'https://second.example', 'missed_at' => NULL, 'failures' => 0, 'last_error' => NULL] + $state);
    // A frontend that never read an announcement has no status: it may have
    // no such route.
    $states->set(Crypt::hashBase64('https://third.example'), ['url' => 'https://third.example', 'last_success' => NULL] + $state);
    self::assertSame([
      ['state' => 'failing', 'lastSuccess' => 1600000000, 'lastFailure' => 1700000000, 'lastError' => 'HTTP 500'],
      ['state' => 'ok', 'lastSuccess' => 1600000000, 'lastFailure' => 1700000000, 'lastError' => NULL],
      NULL,
    ], array_column(self::decode($controller->get())['frontends'], 'cacheInvalidationStatus'));

    // Users who can only preview get no status: errors can name hosts of the
    // site's network.
    $this->setPermissions(['access canvas headless preview']);
    self::assertSame([NULL, NULL, NULL], array_column(self::decode(FrontendsController::create($this->container)->get())['frontends'], 'cacheInvalidationStatus'));

    // The state of a removed frontend is forgotten, however the list
    // changes: also by a config import.
    $this->config('canvas_headless.settings')
      ->set('frontends', [['url' => 'https://second.example', 'components' => []]])
      ->save();
    self::assertSame(['https://second.example'], array_column($states->getAll(), 'url'));
    // Other changes keep it.
    $this->config('canvas_headless.settings')->set('assertion_expiration', 61)->save();
    self::assertCount(1, $states->getAll());
    // A frontend that is added never keeps a state, so that its first
    // announcement expires everything: here one left by a request that still
    // knew the removed frontend.
    $states->set(Crypt::hashBase64('https://third.example'), ['url' => 'https://third.example'] + $state);
    $this->config('canvas_headless.settings')
      ->set('frontends', [['url' => 'https://second.example', 'components' => []], ['url' => 'https://third.example', 'components' => []]])
      ->save();
    self::assertSame(['https://second.example'], array_column($states->getAll(), 'url'));
  }

  /**
   * Tests announcing to one frontend, and reporting whether it read the tags.
   */
  public function testCacheInvalidationTest(): void {
    $this->installConfig(['canvas_headless']);
    $this->config('canvas_headless.settings')
      ->set('frontends', [['url' => 'https://first.example/app', 'components' => []]])
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
    $handler = HandlerStack::create(new MockHandler([$respond, $respond, new FrontendResponse(404), $respond]));
    $handler->push(Middleware::history($history));
    $this->container->set('http_client', new Client(['handler' => $handler]));
    $controller = FrontendsController::create($this->container);

    foreach (['not JSON', '[]', '{"url": 1}'] as $malformed) {
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

    // Another spelling of a configured frontend is that frontend. A frontend
    // that never read an announcement may have missed anything: its first
    // one expires everything.
    $read_message = [
      'ok' => TRUE,
      'announced' => TRUE,
      'read' => TRUE,
      'message' => 'The frontend received the announcement and read the cache tags.',
    ];
    $response = $controller->testCacheInvalidation(self::request(['url' => 'HTTPS://FIRST.EXAMPLE/app']));
    self::assertSame(Response::HTTP_OK, $response->getStatusCode());
    self::assertSame($read_message, self::decode($response));

    // Then a test expires nothing.
    self::assertSame($read_message, self::decode($controller->testCacheInvalidation(self::request(['url' => 'https://first.example/app']))));

    $response = $controller->testCacheInvalidation(self::request(['url' => 'https://first.example/app']));
    self::assertSame(Response::HTTP_OK, $response->getStatusCode());
    self::assertSame([
      'ok' => FALSE,
      'announced' => FALSE,
      'read' => FALSE,
      'message' => 'The frontend answered HTTP 404.',
    ], self::decode($response));

    // After a failure, the frontend expires everything again.
    self::assertSame($read_message, self::decode($controller->testCacheInvalidation(self::request(['url' => 'https://first.example/app']))));

    self::assertSame([['http_response'], [FrontendCacheTagInvalidator::TEST_TAG], ['http_response']], $read);
    self::assertCount(4, $history);
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
