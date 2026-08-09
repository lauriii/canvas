<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_headless\Unit;

use Drupal\canvas_headless\StackMiddleware\CanvasContentApiRequest;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Tests content API request rewriting.
 */
#[CoversClass(CanvasContentApiRequest::class)]
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
            CanvasContentApiRequest::API_QUERY_PARAMETERS_KEY => [
              CanvasContentApiRequest::PREVIEW_VIEW_MODE_QUERY => 'teaser',
            ],
          ], $request->query->all());
          self::assertSame(
            '/articles/example?campaign=test&viewMode=route-view-mode',
            $request->attributes->get(CanvasContentApiRequest::REQUESTED_URI_ATTRIBUTE),
          );
          self::assertSame(
            [CanvasContentApiRequest::PREVIEW_VIEW_MODE_QUERY => 'teaser'],
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
        'requestUri' => '/articles/example?campaign=test&viewMode=route-view-mode',
        CanvasContentApiRequest::PREVIEW_VIEW_MODE_QUERY => 'teaser',
      ]),
    );
    $request->headers->set('Authorization', 'Bearer preview-token');

    $middleware->handle($request);
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

}
