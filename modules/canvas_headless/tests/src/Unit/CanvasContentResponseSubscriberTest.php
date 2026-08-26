<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_headless\Unit;

use Drupal\canvas_headless\CanvasContentProblemResponse;
use Drupal\canvas_headless\EventSubscriber\CanvasContentResponseSubscriber;
use Drupal\canvas_headless\StackMiddleware\CanvasContentApiRequest;
use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Tests Canvas content API response finalization.
 */
#[CoversClass(CanvasContentResponseSubscriber::class)]
#[CoversClass(CanvasContentProblemResponse::class)]
#[Group('canvas_headless')]
final class CanvasContentResponseSubscriberTest extends UnitTestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $container = new ContainerBuilder();
    $container->set('cache_contexts_manager', new class() {

      public function assertValidTokens(array $tokens): bool {
        return TRUE;
      }

    });
    \Drupal::setContainer($container);
  }

  /**
   * Tests that a routed redirect becomes a cacheable JSON result.
   */
  public function testRelativeRedirect(): void {
    $redirect = new TrustedRedirectResponse('/new-path?source=old', 301);
    $redirect->headers->set('Cache-Control', 'public, max-age=300');
    $redirect->headers->set('Content-Language', 'en');
    $redirect->headers->set('Content-Disposition', 'attachment');
    $redirect->headers->set('Content-Length', '123');
    $redirect->headers->set('ETag', 'old-response');
    $redirect->headers->set('Refresh', '0; url=/unexpected');
    $redirect->headers->set('X-Drupal-Cache-Tags', 'redirect:1 route_match');
    $redirect->headers->set('X-Frame-Options', 'SAMEORIGIN');
    $redirect->headers->set('X-Routed-Header', 'preserved');
    $redirect->headers->setCookie(new Cookie('routed-response', 'value'));
    $redirect->addCacheableDependency(
      (new CacheableMetadata())->setCacheTags(['redirect:1']),
    );
    $event = $this->event($redirect);

    $subscriber = $this->subscriber();
    $subscriber->addCacheability($event);
    CanvasContentResponseSubscriber::convertRedirect($event);

    $response = $event->getResponse();
    self::assertInstanceOf(CacheableJsonResponse::class, $response);
    self::assertSame(200, $response->getStatusCode());
    self::assertSame([
      'redirect' => [
        'external' => FALSE,
        'url' => '/new-path?source=old',
        'statusCode' => 301,
      ],
    ], json_decode((string) $response->getContent(), TRUE, flags: JSON_THROW_ON_ERROR));
    self::assertContains('redirect:1', $response->getCacheableMetadata()->getCacheTags());
    self::assertContains('url', $response->getCacheableMetadata()->getCacheContexts());
    self::assertSame(
      'max-age=300, public',
      $response->headers->get('Cache-Control'),
    );
    self::assertSame('en', $response->headers->get('Content-Language'));
    self::assertSame(
      'redirect:1 route_match',
      $response->headers->get('X-Drupal-Cache-Tags'),
    );
    self::assertSame('SAMEORIGIN', $response->headers->get('X-Frame-Options'));
    self::assertSame('preserved', $response->headers->get('X-Routed-Header'));
    self::assertFalse($response->headers->has('Location'));
    self::assertFalse($response->headers->has('Refresh'));
    self::assertFalse($response->headers->has('Content-Disposition'));
    self::assertFalse($response->headers->has('Content-Length'));
    self::assertFalse($response->headers->has('ETag'));
    self::assertSame(
      'routed-response',
      $response->headers->getCookies()[0]->getName(),
    );
    self::assertSame('value', $response->headers->getCookies()[0]->getValue());
  }

  /**
   * Tests that same-site absolute redirects become relative metadata.
   */
  public function testSameSiteAbsoluteRedirect(): void {
    $redirect = new TrustedRedirectResponse(
      'https://drupal.example/about-us?preview=1#hero',
      301,
    );
    $event = $this->event(
      $redirect,
      Request::create(
        CanvasContentApiRequest::API_PATH,
        server: [
          'HTTP_HOST' => 'drupal.example',
          'HTTPS' => 'on',
        ],
      ),
    );

    CanvasContentResponseSubscriber::convertRedirect($event);

    $response = $event->getResponse();
    self::assertInstanceOf(CacheableJsonResponse::class, $response);
    self::assertSame([
      'redirect' => [
        'external' => FALSE,
        'url' => '/about-us?preview=1#hero',
        'statusCode' => 301,
      ],
    ], json_decode((string) $response->getContent(), TRUE, flags: JSON_THROW_ON_ERROR));
  }

  /**
   * Tests that same-site absolute redirects drop the Drupal subdirectory.
   */
  public function testSameSiteAbsoluteRedirectWithSubdirectoryInstall(): void {
    $redirect = new TrustedRedirectResponse(
      'https://drupal.example/subdir/about-us?preview=1#hero',
      301,
    );
    $event = $this->event(
      $redirect,
      Request::create(
        '/subdir' . CanvasContentApiRequest::API_PATH,
        server: [
          'SCRIPT_NAME' => '/subdir/index.php',
          'SCRIPT_FILENAME' => '/var/www/html/index.php',
          'HTTP_HOST' => 'drupal.example',
          'HTTPS' => 'on',
        ],
      ),
    );

    CanvasContentResponseSubscriber::convertRedirect($event);

    $response = $event->getResponse();
    self::assertInstanceOf(CacheableJsonResponse::class, $response);
    self::assertSame([
      'redirect' => [
        'external' => FALSE,
        'url' => '/about-us?preview=1#hero',
        'statusCode' => 301,
      ],
    ], json_decode((string) $response->getContent(), TRUE, flags: JSON_THROW_ON_ERROR));
  }

  /**
   * Tests that truly external redirects stay absolute in metadata.
   */
  public function testExternalRedirect(): void {
    $redirect = new TrustedRedirectResponse(
      'https://example.com/landing?campaign=canvas',
      302,
    );
    $event = $this->event(
      $redirect,
      Request::create(
        CanvasContentApiRequest::API_PATH,
        server: [
          'HTTP_HOST' => 'drupal.example',
          'HTTPS' => 'on',
        ],
      ),
    );

    CanvasContentResponseSubscriber::convertRedirect($event);

    $response = $event->getResponse();
    self::assertInstanceOf(CacheableJsonResponse::class, $response);
    self::assertSame([
      'redirect' => [
        'external' => TRUE,
        'url' => 'https://example.com/landing?campaign=canvas',
        'statusCode' => 302,
      ],
    ], json_decode((string) $response->getContent(), TRUE, flags: JSON_THROW_ON_ERROR));
  }

  /**
   * Tests common cacheability on a content result.
   */
  public function testContentCacheability(): void {
    $event = $this->event(new CacheableJsonResponse(['content' => []]));

    $this->subscriber(with_redirect: TRUE)->addCacheability($event);

    $response = $event->getResponse();
    self::assertInstanceOf(CacheableJsonResponse::class, $response);
    $metadata = $response->getCacheableMetadata();
    self::assertContains('config:core.extension', $metadata->getCacheTags());
    self::assertContains('config:redirect.settings', $metadata->getCacheTags());
    self::assertContains('redirect_list', $metadata->getCacheTags());
    self::assertContains('route_match', $metadata->getCacheTags());
    self::assertContains('languages:language_url', $metadata->getCacheContexts());
    self::assertContains('url', $metadata->getCacheContexts());
  }

  /**
   * Tests that the global subscriber does not change unrelated responses.
   */
  public function testOrdinaryResponsesAreUnchanged(): void {
    $content = new CacheableJsonResponse(['content' => 'ordinary']);
    $content_event = $this->event(
      $content,
      Request::create('/ordinary'),
      FALSE,
    );
    $this->subscriber()->addCacheability($content_event);
    self::assertSame($content, $content_event->getResponse());
    self::assertSame([], $content->getCacheableMetadata()->getCacheTags());

    $redirect = new TrustedRedirectResponse('/another-route');
    $redirect_event = $this->event(
      $redirect,
      Request::create('/ordinary'),
      FALSE,
    );
    CanvasContentResponseSubscriber::convertRedirect($redirect_event);
    self::assertSame($redirect, $redirect_event->getResponse());

    $error = new Response('ordinary error', 404);
    $error_event = $this->event(
      $error,
      Request::create('/ordinary'),
      FALSE,
    );
    CanvasContentResponseSubscriber::convertError($error_event);
    self::assertSame($error, $error_event->getResponse());
  }

  /**
   * Tests Problem Details conversion and cacheability preservation.
   */
  public function testErrors(): void {
    $unmatched_event = $this->event(new Response('HTML error', 404));
    CanvasContentResponseSubscriber::convertError($unmatched_event);

    $unmatched_response = $unmatched_event->getResponse();
    self::assertInstanceOf(CacheableJsonResponse::class, $unmatched_response);
    self::assertSame([
      'type' => 'about:blank',
      'title' => 'Not Found',
      'status' => 404,
    ], json_decode((string) $unmatched_response->getContent(), TRUE, flags: JSON_THROW_ON_ERROR));
    self::assertSame(
      'application/problem+json',
      $unmatched_response->headers->get('Content-Type'),
    );
    self::assertSame(0, $unmatched_response->getCacheableMetadata()->getCacheMaxAge());

    $request = Request::create('/archive/2026');
    $problem_response = new CanvasContentProblemResponse(
      404,
      'The requested resource was not found.',
      'https://example.com/problems/not-found',
    );
    $problem_response->addCacheableDependency(
      (new CacheableMetadata())->setCacheTags(['route:error']),
    );
    $matched_event = $this->event($problem_response, $request);
    CanvasContentResponseSubscriber::convertError($matched_event);

    $matched_response = $matched_event->getResponse();
    self::assertSame($problem_response, $matched_response);
    self::assertSame([
      'type' => 'https://example.com/problems/not-found',
      'title' => 'Not Found',
      'status' => 404,
      'detail' => 'The requested resource was not found.',
    ], json_decode((string) $matched_response->getContent(), TRUE, flags: JSON_THROW_ON_ERROR));
    self::assertContains('route:error', $matched_response->getCacheableMetadata()->getCacheTags());

    $denied_response = new CacheableJsonResponse(['ignored' => TRUE], 403);
    $denied_response->addCacheableDependency(
      (new CacheableMetadata())->setCacheContexts(['user.permissions']),
    );
    $denied_event = $this->event($denied_response, $request);
    CanvasContentResponseSubscriber::convertError($denied_event);
    $denied_response = $denied_event->getResponse();
    self::assertInstanceOf(CacheableJsonResponse::class, $denied_response);
    self::assertSame([
      'type' => 'about:blank',
      'title' => 'Forbidden',
      'status' => 403,
    ], json_decode(
      (string) $denied_response->getContent(),
      TRUE,
      flags: JSON_THROW_ON_ERROR,
    ));
    self::assertContains(
      'user.permissions',
      $denied_response->getCacheableMetadata()->getCacheContexts(),
    );

    $method_response = new Response('HTML error', 405, [
      'Allow' => 'GET',
      'Content-Disposition' => 'attachment',
      'Content-Encoding' => 'gzip',
      'Content-Length' => '123',
      'ETag' => 'old-response',
      'X-Drupal-Cache-Tags' => 'route:error',
      'X-Routed-Header' => 'preserved',
    ]);
    $method_response->headers->setCookie(
      new Cookie('routed-response', 'value'),
    );
    $method_event = $this->event(
      $method_response,
      Request::create(CanvasContentApiRequest::API_PATH, 'POST'),
      FALSE,
    );
    CanvasContentResponseSubscriber::convertError($method_event);
    self::assertSame([
      'type' => 'about:blank',
      'title' => 'Method Not Allowed',
      'status' => 405,
    ], json_decode(
      (string) $method_event->getResponse()->getContent(),
      TRUE,
      flags: JSON_THROW_ON_ERROR,
    ));
    self::assertSame('GET', $method_event->getResponse()->headers->get('Allow'));
    self::assertFalse(
      $method_event->getResponse()->headers->has('Content-Disposition'),
    );
    self::assertFalse(
      $method_event->getResponse()->headers->has('Content-Encoding'),
    );
    self::assertFalse(
      $method_event->getResponse()->headers->has('Content-Length'),
    );
    self::assertFalse($method_event->getResponse()->headers->has('ETag'));
    self::assertSame(
      'route:error',
      $method_event->getResponse()->headers->get('X-Drupal-Cache-Tags'),
    );
    self::assertSame(
      'preserved',
      $method_event->getResponse()->headers->get('X-Routed-Header'),
    );
    self::assertSame(
      'routed-response',
      $method_event->getResponse()->headers->getCookies()[0]->getName(),
    );

    $unauthorized_event = $this->event(new Response('', 401, [
      'WWW-Authenticate' => 'Bearer realm="Canvas"',
    ]));
    CanvasContentResponseSubscriber::convertError($unauthorized_event);
    self::assertSame(
      'Bearer realm="Canvas"',
      $unauthorized_event->getResponse()->headers->get('WWW-Authenticate'),
    );

    $unavailable_event = $this->event(new Response('', 503, [
      'Retry-After' => '120',
    ]));
    CanvasContentResponseSubscriber::convertError($unavailable_event);
    self::assertSame(
      '120',
      $unavailable_event->getResponse()->headers->get('Retry-After'),
    );

    $server_error_event = $this->event(new Response('HTML error', 500));
    CanvasContentResponseSubscriber::convertError($server_error_event);
    self::assertSame([
      'type' => 'about:blank',
      'title' => 'Internal Server Error',
      'status' => 500,
    ], json_decode(
      (string) $server_error_event->getResponse()->getContent(),
      TRUE,
      flags: JSON_THROW_ON_ERROR,
    ));
  }

  /**
   * Creates the response subscriber with optional Redirect integration.
   */
  private function subscriber(
    bool $with_redirect = FALSE,
  ): CanvasContentResponseSubscriber {
    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->method('hasDefinition')
      ->with('redirect')
      ->willReturn($with_redirect);
    if ($with_redirect) {
      $redirect = $this->createMock(EntityTypeInterface::class);
      $redirect->method('getListCacheTags')->willReturn(['redirect_list']);
      $entity_type_manager->method('getDefinition')
        ->with('redirect')
        ->willReturn($redirect);
    }
    return new CanvasContentResponseSubscriber($entity_type_manager);
  }

  /**
   * Creates a response event for a marked content API request.
   */
  private function event(
    Response $response,
    ?Request $request = NULL,
    bool $marked = TRUE,
  ): ResponseEvent {
    $request ??= Request::create('/old-path');
    if ($marked) {
      $request->attributes->set(
        CanvasContentApiRequest::REQUESTED_URI_ATTRIBUTE,
        '/old-path',
      );
    }
    return new ResponseEvent(
      $this->createMock(HttpKernelInterface::class),
      $request,
      HttpKernelInterface::MAIN_REQUEST,
      $response,
    );
  }

}
