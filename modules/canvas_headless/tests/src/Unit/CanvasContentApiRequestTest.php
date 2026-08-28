<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_headless\Unit;

use Drupal\canvas_headless\EventSubscriber\DetachedPreviewRouteSubscriber;
use Drupal\canvas_headless\Grant\PreviewAssertionGrant;
use Drupal\canvas_headless\Routing\DetachedPreviewRouteProcessor;
use Drupal\canvas_headless\StackMiddleware\CanvasContentApiRequest;
use Drupal\Core\Routing\CacheableRouteProviderInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\simple_oauth\Authentication\TokenAuthUserInterface;
use Drupal\simple_oauth\Entity\Oauth2TokenInterface;
use Drupal\simple_oauth\Oauth2ScopeInterface;
use Drupal\simple_oauth\Plugin\Field\FieldType\Oauth2ScopeReferenceItemListInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Tests content API request rewriting and detached preview routing.
 */
#[CoversClass(CanvasContentApiRequest::class)]
#[CoversClass(DetachedPreviewRouteProcessor::class)]
#[CoversClass(DetachedPreviewRouteSubscriber::class)]
#[Group('canvas_headless')]
final class CanvasContentApiRequestTest extends UnitTestCase {

  /**
   * Tests that the requested URI becomes the kernel-routed request.
   */
  public function testRequestRewrite(): void {
    $kernel = $this->createMock(HttpKernelInterface::class);
    $kernel->expects($this->once())
      ->method('handle')
      ->willReturnCallback(
        static function (Request $request, int $type, bool $catch): Response {
          self::assertSame(HttpKernelInterface::MAIN_REQUEST, $type);
          self::assertTrue($catch);
          self::assertSame('/articles/example', $request->getPathInfo());
          self::assertSame(
            '/articles/example?' . http_build_query([
              'campaign' => 'test',
              CanvasContentApiRequest::PREVIEW_VIEW_MODE_QUERY => 'route-view-mode',
              CanvasContentApiRequest::COMPONENT_PREVIEW_QUERY => 'route-component',
              CanvasContentApiRequest::API_QUERY_PARAMETERS_KEY => [
                CanvasContentApiRequest::PREVIEW_VIEW_MODE_QUERY => 'teaser',
              ],
            ]),
            $request->getRequestUri(),
          );
          self::assertSame(
            CanvasContentApiRequest::REQUEST_FORMAT,
            $request->getRequestFormat(),
          );
          self::assertSame([
            'campaign' => 'test',
            CanvasContentApiRequest::PREVIEW_VIEW_MODE_QUERY => 'route-view-mode',
            CanvasContentApiRequest::COMPONENT_PREVIEW_QUERY => 'route-component',
            CanvasContentApiRequest::API_QUERY_PARAMETERS_KEY => [
              CanvasContentApiRequest::PREVIEW_VIEW_MODE_QUERY => 'teaser',
            ],
          ], $request->query->all());
          self::assertSame(
            '/articles/example?campaign=test&viewMode=route-view-mode&componentId=route-component',
            $request->attributes->get(CanvasContentApiRequest::REQUESTED_URI_ATTRIBUTE),
          );
          self::assertSame(
            [
              CanvasContentApiRequest::PREVIEW_VIEW_MODE_QUERY => 'teaser',
            ],
            $request->attributes->get(CanvasContentApiRequest::API_QUERY_PARAMETERS_KEY),
          );
          self::assertSame('Bearer preview-token', $request->headers->get('Authorization'));
          return new Response();
        },
      );
    $middleware = new CanvasContentApiRequest($kernel);
    $request = Request::create(
      'https://drupal.example/canvas/content-api?' .
      http_build_query([
        'requestUri' => '/articles/example?campaign=test&viewMode=route-view-mode&componentId=route-component',
        CanvasContentApiRequest::PREVIEW_VIEW_MODE_QUERY => 'teaser',
      ]),
    );
    $request->headers->set('Authorization', 'Bearer preview-token');

    $middleware->handle($request);
  }

  /**
   * Tests internal routing for page and detached preview requests.
   *
   * @param array<string, string> $api_query_parameters
   *   Content API query parameters.
   * @param bool $is_detached_preview
   *   Whether the request carries a detached preview selector.
   * @param string $expected_path
   *   The path passed to the wrapped kernel.
   */
  #[DataProvider('previewRoutingProvider')]
  public function testPreviewRouting(array $api_query_parameters, bool $is_detached_preview, string $expected_path): void {
    $kernel = $this->createMock(HttpKernelInterface::class);
    $kernel->expects($this->once())
      ->method('handle')
      ->willReturnCallback(
        static function (Request $request) use ($is_detached_preview, $expected_path): Response {
          self::assertSame($expected_path, $request->getPathInfo());
          self::assertSame(
            '/',
            $request->attributes->get(CanvasContentApiRequest::REQUESTED_URI_ATTRIBUTE),
          );
          self::assertSame(
            $is_detached_preview,
            $request->attributes->get(CanvasContentApiRequest::DETACHED_PREVIEW_ATTRIBUTE),
          );
          return new Response();
        },
      );

    $request = Request::create(
      CanvasContentApiRequest::API_PATH . '?' . http_build_query([
        'requestUri' => '/',
        ...$api_query_parameters,
      ]),
    );

    (new CanvasContentApiRequest($kernel))->handle($request);
  }

  /**
   * Provides content API requests with different preview selectors.
   */
  public static function previewRoutingProvider(): array {
    return [
      'normal page' => [[], FALSE, '/'],
      'component preview' => [
        [
          CanvasContentApiRequest::COMPONENT_PREVIEW_QUERY => 'external-card',
        ],
        TRUE,
        '/',
      ],
      'page variant preview' => [
        [
          CanvasContentApiRequest::PAGE_VARIANT_PREVIEW_QUERY => 'alternate',
        ],
        TRUE,
        '/',
      ],
    ];
  }

  /**
   * Tests that only authenticated previews use the Canvas-owned route.
   */
  #[DataProvider('previewScopeProvider')]
  public function testDetachedPreviewRouteProcessing(bool $has_preview_scope, string $expected_path): void {
    $account = $this->createMock(AccountInterface::class);
    if ($has_preview_scope) {
      $account = $this->previewTokenAccount();
    }
    $current_user = $this->createMock(AccountProxyInterface::class);
    $current_user->method('getAccount')->willReturn($account);
    $processor = new DetachedPreviewRouteProcessor($current_user);
    $request = Request::create('/');
    $request->attributes->set(
      CanvasContentApiRequest::DETACHED_PREVIEW_ATTRIBUTE,
      TRUE,
    );

    self::assertSame($expected_path, $processor->processInbound('/', $request));
  }

  /**
   * Provides preview-scope route processing decisions.
   */
  public static function previewScopeProvider(): array {
    return [
      'public request' => [FALSE, '/'],
      'authenticated preview' => [TRUE, CanvasContentApiRequest::API_PATH],
    ];
  }

  /**
   * Tests request preparation for detached preview routing.
   */
  #[DataProvider('previewScopeProvider')]
  public function testPrepareDetachedPreviewRouting(bool $has_preview_scope, string $expected_path): void {
    $account = $this->createMock(AccountInterface::class);
    if ($has_preview_scope) {
      $account = $this->previewTokenAccount();
    }
    $current_user = $this->createMock(AccountProxyInterface::class);
    $current_user->method('getAccount')->willReturn($account);
    $route_provider = $this->createMock(CacheableRouteProviderInterface::class);
    $route_provider->expects($this->once())
      ->method('addExtraCacheKeyPart')
      ->with('canvas_headless_detached_preview', $has_preview_scope ? '1' : '0');
    $subscriber = new DetachedPreviewRouteSubscriber($current_user, $route_provider);
    $processor = new DetachedPreviewRouteProcessor($current_user);
    $request = Request::create('/');
    $request->attributes->set(
      CanvasContentApiRequest::DETACHED_PREVIEW_ATTRIBUTE,
      TRUE,
    );

    $subscriber->prepareDetachedPreviewRouting(new RequestEvent(
      $this->createMock(HttpKernelInterface::class),
      $request,
      HttpKernelInterface::MAIN_REQUEST,
    ));

    self::assertSame(
      $has_preview_scope,
      $request->attributes->get('_disable_route_normalizer', FALSE),
    );
    self::assertSame($expected_path, $processor->processInbound('/', $request));
  }

  /**
   * Tests malformed request URI values.
   */
  #[DataProvider('invalidRequestUriProvider')]
  public function testInvalidRequestUri(mixed $request_uri): void {
    $kernel = $this->createMock(HttpKernelInterface::class);
    $kernel->expects($this->never())->method('handle');
    $middleware = new CanvasContentApiRequest($kernel);
    $request = Request::create(CanvasContentApiRequest::API_PATH);
    if ($request_uri !== NULL) {
      $request->query->set('requestUri', $request_uri);
    }

    $response = $middleware->handle($request);

    self::assertSame(400, $response->getStatusCode());
    self::assertSame(
      'application/problem+json',
      $response->headers->get('Content-Type'),
    );
    self::assertSame([
      'type' => 'about:blank',
      'title' => 'Bad Request',
      'status' => 400,
      'detail' => 'The requestUri query parameter must be a site-relative URI without a fragment.',
    ], json_decode((string) $response->getContent(), TRUE, flags: JSON_THROW_ON_ERROR));
  }

  /**
   * Provides malformed request URI values.
   */
  public static function invalidRequestUriProvider(): array {
    return [
      'missing' => [NULL],
      'empty' => [''],
      'relative' => ['node/1'],
      'protocol relative' => ['//example.com'],
      'backslash' => ['/node\\1'],
      'fragment' => ['/node/1#content'],
      'non-string' => [['/node/1']],
    ];
  }

  /**
   * Tests that requests outside the rewrite boundary pass through unchanged.
   */
  #[DataProvider('passThroughRequestProvider')]
  public function testRequestPassesThrough(
    Request $request,
    int $request_type,
  ): void {
    $response = new Response();
    $kernel = $this->createMock(HttpKernelInterface::class);
    $kernel->expects($this->once())
      ->method('handle')
      ->with($request, $request_type, TRUE)
      ->willReturn($response);

    self::assertSame(
      $response,
      (new CanvasContentApiRequest($kernel))->handle($request, $request_type),
    );
  }

  /**
   * Provides requests that the middleware must not rewrite.
   */
  public static function passThroughRequestProvider(): array {
    return [
      'unrelated path' => [
        Request::create('/another-route'),
        HttpKernelInterface::MAIN_REQUEST,
      ],
      'unsupported method' => [
        Request::create(CanvasContentApiRequest::API_PATH, 'POST'),
        HttpKernelInterface::MAIN_REQUEST,
      ],
      'subrequest' => [
        Request::create(CanvasContentApiRequest::API_PATH),
        HttpKernelInterface::SUB_REQUEST,
      ],
    ];
  }

  /**
   * Creates an account carrying the Canvas preview scope.
   */
  private function previewTokenAccount(): TokenAuthUserInterface {
    $preview_scope = $this->createMock(Oauth2ScopeInterface::class);
    $preview_scope->method('getName')->willReturn(PreviewAssertionGrant::SCOPE);
    $scopes = $this->createMock(Oauth2ScopeReferenceItemListInterface::class);
    $scopes->method('getScopes')->willReturn([$preview_scope]);
    $token = $this->createMock(Oauth2TokenInterface::class);
    $token->method('get')->with('scopes')->willReturn($scopes);
    $account = $this->createMock(TokenAuthUserInterface::class);
    $account->method('getToken')->willReturn($token);
    return $account;
  }

}
