<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_headless\Unit;

use Drupal\canvas\CanvasClientCapabilities;
use Drupal\canvas_headless\Cache\HeadlessContentFormatCacheContext;
use Drupal\canvas_headless\CanvasHeadlessServiceProvider;
use Drupal\canvas_headless\EventSubscriber\DetachedPreviewRouteSubscriber;
use Drupal\canvas_headless\EventSubscriber\PreviewLanguageSubscriber;
use Drupal\canvas_headless\Grant\PreviewAssertionGrant;
use Drupal\canvas_headless\PageCache\DisallowCanvasContentApiRequests;
use Drupal\canvas_headless\Routing\DetachedPreviewRouteProcessor;
use Drupal\canvas_headless\StackMiddleware\CanvasContentApiRequest;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Language\Language;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\PageCache\RequestPolicyInterface;
use Drupal\Core\PathProcessor\InboundPathProcessorInterface;
use Drupal\Core\Routing\CacheableRouteProviderInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\language\ConfigurableLanguageManagerInterface;
use Drupal\language\Plugin\LanguageNegotiation\LanguageNegotiationUrl;
use Drupal\simple_oauth\Authentication\TokenAuthUserInterface;
use Drupal\simple_oauth\Entity\Oauth2TokenInterface;
use Drupal\simple_oauth\Oauth2ScopeInterface;
use Drupal\simple_oauth\Plugin\Field\FieldType\Oauth2ScopeReferenceItemListInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Tests content API request rewriting and detached preview routing.
 */
#[CoversClass(CanvasContentApiRequest::class)]
#[CoversClass(CanvasClientCapabilities::class)]
#[CoversClass(HeadlessContentFormatCacheContext::class)]
#[CoversClass(DisallowCanvasContentApiRequests::class)]
#[CoversClass(CanvasHeadlessServiceProvider::class)]
#[CoversClass(DetachedPreviewRouteProcessor::class)]
#[CoversClass(DetachedPreviewRouteSubscriber::class)]
#[CoversClass(PreviewLanguageSubscriber::class)]
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
                CanvasContentApiRequest::EXCLUDE_AUTO_SAVE_QUERY => 'true',
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
              CanvasContentApiRequest::EXCLUDE_AUTO_SAVE_QUERY => 'true',
            ],
          ], $request->query->all());
          self::assertSame(
            '/articles/example?campaign=test&viewMode=route-view-mode&componentId=route-component',
            $request->attributes->get(CanvasContentApiRequest::REQUESTED_URI_ATTRIBUTE),
          );
          self::assertSame(
            [
              CanvasContentApiRequest::PREVIEW_VIEW_MODE_QUERY => 'teaser',
              CanvasContentApiRequest::EXCLUDE_AUTO_SAVE_QUERY => 'true',
            ],
            $request->attributes->get(CanvasContentApiRequest::API_QUERY_PARAMETERS_KEY),
          );
          self::assertSame('Bearer preview-token', $request->headers->get('Authorization'));
          self::assertSame(
            'json-render',
            $request->headers->get(CanvasClientCapabilities::CAPABILITIES_HEADER),
          );
          $response = new Response();
          $response->setVary(['Origin', CanvasClientCapabilities::CAPABILITIES_HEADER]);
          return $response;
        },
      );
    $middleware = new CanvasContentApiRequest($kernel);
    $request = Request::create(
      'https://drupal.example/canvas/content-api?' .
      http_build_query([
        'requestUri' => '/articles/example?campaign=test&viewMode=route-view-mode&componentId=route-component',
        CanvasContentApiRequest::PREVIEW_VIEW_MODE_QUERY => 'teaser',
        CanvasContentApiRequest::EXCLUDE_AUTO_SAVE_QUERY => 'true',
      ]),
    );
    $request->headers->set('Authorization', 'Bearer preview-token');
    $request->headers->set(CanvasClientCapabilities::CAPABILITIES_HEADER, 'json-render');

    $response = $middleware->handle($request);
    self::assertSame(
      ['Origin', CanvasClientCapabilities::CAPABILITIES_HEADER],
      $response->getVary(),
    );
  }

  /**
   * Tests that Internal Page Cache skips the public content API endpoint.
   */
  public function testInternalPageCachePolicy(): void {
    $policy = new DisallowCanvasContentApiRequests();

    self::assertSame(
      RequestPolicyInterface::DENY,
      $policy->check(Request::create(CanvasContentApiRequest::API_PATH)),
    );
    self::assertSame(
      RequestPolicyInterface::DENY,
      $policy->check(Request::create('/canvas/content-api/entity')),
    );
    self::assertNull($policy->check(Request::create('/ordinary')));
    // Without the language module's path processor, prefixes stay untouched.
    self::assertNull($policy->check(Request::create('/francais/canvas/content-api/entity')));

    $language_manager = $this->createMock(ConfigurableLanguageManagerInterface::class);
    $language_manager->method('getLanguages')->willReturn([
      'en' => new Language(['id' => 'en']),
      'fr' => new Language(['id' => 'fr']),
    ]);
    $language_processor = new LanguageNegotiationUrl();
    $language_processor->setLanguageManager($language_manager);
    $language_processor->setConfig($this->getConfigFactoryStub([
      'language.negotiation' => [
        'url' => [
          'source' => LanguageNegotiationUrl::CONFIG_PATH_PREFIX,
          'prefixes' => ['en' => '', 'fr' => 'francais'],
        ],
      ],
    ]));
    $policy = new DisallowCanvasContentApiRequests($language_processor);
    foreach ([
      '/canvas/content-api',
      '/canvas/content-api/entity',
      '/francais/canvas/content-api',
      '/francais/canvas/content-api/entity',
      // cspell:ignore ncais Dapi
      '/fr%61ncais/canvas/content-api/entity',
      '/canvas/content%2Dapi/entity',
    ] as $path) {
      self::assertSame(RequestPolicyInterface::DENY, $policy->check(Request::create($path)), $path);
    }
    foreach ([
      '/ordinary',
      '/francais/ordinary',
      '/fr/canvas/content-api/entity',
      '/francais/canvas/content-api/entity/extra',
    ] as $path) {
      self::assertNull($policy->check(Request::create($path)), $path);
    }

    $request = Request::create('/francais/canvas/content-api/entity');
    $processor = $this->createMock(InboundPathProcessorInterface::class);
    $processor->expects($this->once())->method('processInbound')->willReturnCallback(
      static function (string $path, Request $processed_request) use ($request): string {
        self::assertNotSame($request, $processed_request);
        $processed_request->attributes->set('_language_processed', TRUE);
        return '/canvas/content-api/entity';
      },
    );
    self::assertSame(RequestPolicyInterface::DENY, (new DisallowCanvasContentApiRequests($processor))->check($request));
    self::assertFalse($request->attributes->has('_language_processed'));

    $container = new ContainerBuilder();
    $provider = new CanvasHeadlessServiceProvider();
    $provider->alter($container);
    self::assertFalse($container->hasDefinition('http_middleware.page_cache'));
    $shared_policy = $this->createMock(RequestPolicyInterface::class);
    $shared_policy->method('check')->willReturnCallback(
      static fn (Request $request): string => $request->isMethodCacheable() ? RequestPolicyInterface::ALLOW : RequestPolicyInterface::DENY,
    );
    $container->set('test.shared_policy', $shared_policy);
    $container->set(DisallowCanvasContentApiRequests::class, new DisallowCanvasContentApiRequests());
    $container->register('http_middleware.page_cache')
      ->setArguments([NULL, new Reference('test.shared_policy')]);
    $provider->alter($container);
    $scoped_policy = $container->getDefinition('http_middleware.page_cache')->getArgument(1);
    self::assertInstanceOf(Definition::class, $scoped_policy);
    $container->setDefinition('test.scoped_policy', $scoped_policy);
    $scoped_policy = $container->get('test.scoped_policy');
    self::assertInstanceOf(RequestPolicyInterface::class, $scoped_policy);
    foreach ([CanvasContentApiRequest::API_PATH, '/canvas/content-api/entity'] as $path) {
      $request = Request::create($path);
      self::assertSame(RequestPolicyInterface::ALLOW, $shared_policy->check($request));
      self::assertSame(RequestPolicyInterface::DENY, $scoped_policy->check($request));
    }
    self::assertSame(RequestPolicyInterface::ALLOW, $scoped_policy->check(Request::create('/ordinary')));
    self::assertSame(RequestPolicyInterface::DENY, $scoped_policy->check(Request::create('/ordinary', 'POST')));
  }

  /**
   * Tests capability tokens and their normalized content format cache variation.
   */
  #[DataProvider('jsonRenderSupportProvider')]
  public function testSupportsJsonRender(string|array|null $capabilities, bool $expected): void {
    $request = Request::create(CanvasContentApiRequest::API_PATH);
    if ($capabilities !== NULL) {
      $request->headers->set(CanvasClientCapabilities::CAPABILITIES_HEADER, $capabilities);
    }

    self::assertSame(
      $expected,
      CanvasClientCapabilities::has($request, 'json-render'),
    );
    $request_stack = new RequestStack();
    $request_stack->push($request);
    $cache_context = new HeadlessContentFormatCacheContext($request_stack);
    self::assertSame($expected ? 'json-render' : 'legacy', $cache_context->getContext());
  }

  /**
   * Provides capability headers and their json-render support.
   *
   * @return array<string, array{string|list<string|null>|null, bool}>
   */
  public static function jsonRenderSupportProvider(): array {
    return [
      'missing header' => [NULL, FALSE],
      'empty header' => ['', FALSE],
      'valueless header' => [[NULL], FALSE],
      'unknown capability' => ['future-feature', FALSE],
      'json-render' => ['json-render', TRUE],
      'multiple capabilities' => ['future-feature, json-render, another-feature', TRUE],
      'whitespace' => [" future-feature , \tjson-render\t ", TRUE],
      'duplicate capability' => ['json-render, json-render', TRUE],
      'empty members' => [', json-render, ,', TRUE],
      'repeated header' => [['future-feature', 'json-render'], TRUE],
      'capability in first header' => [['json-render', 'future-feature'], TRUE],
      'uppercase token' => ['JSON-RENDER', TRUE],
      'mixed-case token' => ['Json-Render', TRUE],
      'mixed-case repeated header' => [['future-feature', ' Json-Render '], TRUE],
      'token prefix' => ['json-render-extra', FALSE],
      'token suffix' => ['other-json-render', FALSE],
      'quoted token' => ['"json-render"', FALSE],
      'parameters are not tokens' => ['json-render;version=1', FALSE],
      'trailing newline' => ["json-render\n", FALSE],
    ];
  }

  /**
   * Tests capability lookup independently of headless response formats.
   */
  public function testOtherClientCapabilities(): void {
    $request = Request::create('/canvas/api/v0/artifacts/upload');
    $request->headers->set(strtolower(CanvasClientCapabilities::CAPABILITIES_HEADER), [
      ' content-hashed-artifacts, future-feature ',
      'another-feature',
    ]);

    self::assertTrue(CanvasClientCapabilities::has($request, 'content-hashed-artifacts'));
    self::assertTrue(CanvasClientCapabilities::has($request, 'future-feature'));
    self::assertTrue(CanvasClientCapabilities::has($request, 'another-feature'));
    self::assertFalse(CanvasClientCapabilities::has($request, 'json-render'));
    self::assertTrue(CanvasClientCapabilities::has($request, 'Future-Feature'));
    self::assertFalse(CanvasClientCapabilities::has($request, 'future'));
    self::assertFalse(CanvasClientCapabilities::has($request, ''));
  }

  /**
   * The preview hint cannot replace a route-owned language query parameter.
   */
  public function testPreviewLanguageIsNamespaced(): void {
    $kernel = $this->createMock(HttpKernelInterface::class);
    $kernel->expects($this->once())->method('handle')->willReturnCallback(static function (Request $request): Response {
      self::assertSame('route-owned', $request->query->get('language'));
      self::assertSame(['language' => 'fr'], $request->attributes->get(CanvasContentApiRequest::API_QUERY_PARAMETERS_KEY));
      return new Response();
    });
    (new CanvasContentApiRequest($kernel))->handle(Request::create('/canvas/content-api?' . http_build_query([
      'requestUri' => '/page/1?language=route-owned',
      'language' => 'fr',
    ])));
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
   * Missing optional language support never reaches negotiation services.
   */
  #[DataProvider('monolingualPreviewLanguageProvider')]
  public function testPreviewLanguageWithoutLanguageModule(string $langcode, bool $known): void {
    $language_manager = $this->createMock(LanguageManagerInterface::class);
    $language_manager->expects($this->once())->method('getLanguage')
      ->with($langcode)->willReturn($known ? new Language(['id' => 'en']) : NULL);
    $language_manager->expects($known ? $this->once() : $this->never())
      ->method('getCurrentLanguage')->willReturn(new Language(['id' => 'en']));
    $language_manager->expects($this->never())->method('getLanguageSwitchLinks');
    $current_user = $this->createMock(AccountProxyInterface::class);
    $current_user->method('getAccount')->willReturn($this->previewTokenAccount());
    $subscriber = new PreviewLanguageSubscriber(
      $language_manager,
      $current_user,
      NULL,
    );
    $request = Request::create('/page/1');
    $request->attributes->set(CanvasContentApiRequest::REQUESTED_URI_ATTRIBUTE, '/page/1');
    $request->attributes->set(CanvasContentApiRequest::API_QUERY_PARAMETERS_KEY, ['language' => $langcode]);
    if (!$known) {
      $this->expectException(NotFoundHttpException::class);
    }
    $subscriber->onRequest(new RequestEvent(
      $this->createMock(HttpKernelInterface::class),
      $request,
      HttpKernelInterface::MAIN_REQUEST,
    ));
    self::assertSame('/page/1', $request->getRequestUri());
  }

  public static function monolingualPreviewLanguageProvider(): iterable {
    yield 'default language without language module' => ['en', TRUE];
    yield 'unknown language still rejected' => ['unknown', FALSE];
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
    self::assertSame([CanvasClientCapabilities::CAPABILITIES_HEADER], $response->getVary());
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
