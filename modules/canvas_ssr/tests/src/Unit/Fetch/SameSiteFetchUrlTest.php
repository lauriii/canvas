<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_ssr\Unit\Fetch;

use Drupal\canvas_ssr\Fetch\Exception\FetchRefusal;
use Drupal\canvas_ssr\Fetch\Exception\FetchRefusedException;
use Drupal\canvas_ssr\Fetch\Policy\JsonApiPathPolicy;
use Drupal\canvas_ssr\Fetch\Policy\MenuLinksetPathPolicy;
use Drupal\canvas_ssr\Fetch\SameSiteFetch;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\PathProcessor\InboundPathProcessorInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\Tests\UnitTestCase;
use GuzzleHttp\Psr7\Request;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Psr\Http\Message\RequestInterface;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Tests SSR fetch URL resolution, same-site validation and path allowlisting.
 *
 * Checks which URLs resolve to which request URLs, and which are refused,
 * without a running site.
 */
#[CoversClass(SameSiteFetch::class)]
#[Group('canvas_ssr')]
final class SameSiteFetchUrlTest extends UnitTestCase {

  /**
   * The current request of the last fetcher built.
   */
  private SymfonyRequest $current;

  /**
   * The subrequest the kernel last handled.
   */
  private ?SymfonyRequest $handled = NULL;

  /**
   * Builds a fetcher whose subrequests always return a canned 200.
   *
   * @param string $current_url
   *   The current request's URL. Its scheme, host and base path are what
   *   same-site means.
   * @param callable|null $process_inbound
   *   Stands in for inbound path processing, mapping a path to its system
   *   path. Defaults to no change.
   * @param array<string, string> $server
   *   Server variables for the current request. Set SCRIPT_NAME and
   *   SCRIPT_FILENAME to model a subdirectory install.
   */
  private function fetcher(
    string $current_url = 'https://example.com/',
    ?callable $process_inbound = NULL,
    array $server = [],
  ): SameSiteFetch {
    $request = SymfonyRequest::create($current_url, 'GET', [], [], [], $server);

    $request_stack = $this->createMock(RequestStack::class);
    $request_stack->method('getCurrentRequest')->willReturn($request);
    $request_stack->method('getMainRequest')->willReturn($request);

    $kernel = $this->createMock(HttpKernelInterface::class);
    $kernel->method('handle')->willReturnCallback(function (SymfonyRequest $subrequest): SymfonyResponse {
      $this->handled = $subrequest;

      return new SymfonyResponse('{"data":[]}', 200);
    });
    $this->current = $request;

    $renderer = $this->createMock(RendererInterface::class);
    $renderer->method('executeInRenderContext')
      ->willReturnCallback(static fn($context, callable $callback) => $callback());

    $process_inbound ??= static fn(string $path): string => $path;
    $path_processor = $this->createMock(InboundPathProcessorInterface::class);
    $path_processor->method('processInbound')
      ->willReturnCallback(static fn(string $path, SymfonyRequest $given) => $process_inbound($path, $given));

    $language_manager = $this->createMock(LanguageManagerInterface::class);
    $language_manager->method('getLanguageTypes')->willReturn([]);

    return new SameSiteFetch(
      $kernel,
      $request_stack,
      $renderer,
      $path_processor,
      [new MenuLinksetPathPolicy(), new JsonApiPathPolicy('/jsonapi')],
      $language_manager,
    );
  }

  /**
   * Builds the request a component's fetch() call unmarshals to.
   *
   * @param string $url
   *   The URL as the component wrote it.
   * @param string $method
   *   The HTTP method.
   *
   * @return \Psr\Http\Message\RequestInterface
   *   The request to fetch.
   */
  private static function request(string $url, string $method = 'GET'): RequestInterface {
    return new Request($method, $url);
  }

  public function testAbsoluteSameSiteUrlIsCanonicalized(): void {
    $response = $this->fetcher()->fetch(self::request('https://example.com/jsonapi/node/article'));
    self::assertSame('https://example.com/jsonapi/node/article', (string) $response->getUri());
    self::assertSame(200, $response->getStatusCode());
  }

  public function testRootedPathResolvesAgainstRequestHost(): void {
    $response = $this->fetcher()->fetch(self::request('/jsonapi/node/article'));
    self::assertSame('https://example.com/jsonapi/node/article', (string) $response->getUri());
  }

  public function testQueryStringKeepsItsOrderAndFilterKeys(): void {
    // The query must keep its order and every key. PSR-7 percent-encodes
    // the square brackets, which Drupal decodes the same way.
    $response = $this->fetcher()->fetch(self::request('/jsonapi/node/article?filter[b]=2&filter[a]=1'));
    self::assertSame(
      'https://example.com/jsonapi/node/article?filter%5Bb%5D=2&filter%5Ba%5D=1',
      (string) $response->getUri(),
    );
  }

  public function testExplicitDefaultPortIsDropped(): void {
    // PSR-7 drops a default port. That's harmless: routing ignores it, and
    // the URL isn't used as a key.
    $response = $this->fetcher()->fetch(self::request('https://example.com:443/jsonapi/node/article'));
    self::assertSame('https://example.com/jsonapi/node/article', (string) $response->getUri());
  }

  public function testMenuLinksetPathIsAllowed(): void {
    $response = $this->fetcher()->fetch(self::request('/system/menu/main/linkset'));
    self::assertSame('https://example.com/system/menu/main/linkset', (string) $response->getUri());
  }

  public function testAllowlistingUsesProcessedSystemPath(): void {
    // The allowlist checks the path with "/es" stripped. The subrequest keeps
    // the prefix, so routing can negotiate the language.
    $fetcher = $this->fetcher(
      process_inbound: static fn(string $path): string => \str_starts_with($path, '/es/')
        ? \substr($path, 3)
        : $path,
    );
    $response = $fetcher->fetch(self::request('/es/jsonapi/node/article'));
    self::assertSame('https://example.com/es/jsonapi/node/article', (string) $response->getUri());
    self::assertSame(200, $response->getStatusCode());
  }

  public function testInboundProcessingChangesNeitherRequest(): void {
    // An inbound path processor may change the request it's given, as the
    // one for image styles does. It gets the fetch's own request, not the
    // current one, and a copy, since the router processes the subrequest
    // again.
    $seen = NULL;
    $fetcher = $this->fetcher(
      'https://example.com/page?page=2',
      static function (string $path, SymfonyRequest $given) use (&$seen): string {
        $seen = $given->getRequestUri();
        $given->query->set('file', 'changed');

        return $path;
      },
    );
    $fetcher->fetch(self::request('/jsonapi/node/article?sort=title'));

    self::assertSame('/jsonapi/node/article?sort=title', $seen);
    self::assertFalse($this->current->query->has('file'));
    self::assertNotNull($this->handled);
    self::assertFalse($this->handled->query->has('file'));

    // A refused URL doesn't change the current request either.
    try {
      $fetcher->fetch(self::request('/system/files/styles/large/private/a.png'));
      self::fail('The URL was allowed.');
    }
    catch (FetchRefusedException) {
      self::assertFalse($this->current->query->has('file'));
    }
  }

  public function testSubdirectoryInstallStripsBasePath(): void {
    // On a subdirectory install, components fetch "/subdir/jsonapi/...". The
    // allowlist checks the path without "/subdir", and the subrequest keeps
    // it for routing.
    $fetcher = $this->fetcher(
      current_url: 'https://example.com/subdir/',
      server: [
        'SCRIPT_NAME' => '/subdir/index.php',
        'SCRIPT_FILENAME' => '/subdir/index.php',
        'PHP_SELF' => '/subdir/index.php',
      ],
    );
    $response = $fetcher->fetch(self::request('https://example.com/subdir/jsonapi/node/article'));
    self::assertSame('https://example.com/subdir/jsonapi/node/article', (string) $response->getUri());
    self::assertSame(200, $response->getStatusCode());
  }

  public function testPathOutsideBasePathIsRefused(): void {
    // Without "/subdir", the URL is outside the install.
    $fetcher = $this->fetcher(
      current_url: 'https://example.com/subdir/',
      server: [
        'SCRIPT_NAME' => '/subdir/index.php',
        'SCRIPT_FILENAME' => '/subdir/index.php',
        'PHP_SELF' => '/subdir/index.php',
      ],
    );
    self::assertRefused(FetchRefusal::PathNotAllowed, $fetcher, self::request('https://example.com/jsonapi/node/article'));
  }

  public function testHeadRequestReturnsEmptyBody(): void {
    $response = $this->fetcher()->fetch(self::request('/jsonapi/node/article', 'HEAD'));
    self::assertSame('', (string) $response->getBody());
    self::assertSame(200, $response->getStatusCode());
  }

  public function testCrossSiteUrlIsRefused(): void {
    self::assertRefused(FetchRefusal::CrossSiteUrl, $this->fetcher(), self::request('https://evil.example.net/jsonapi/node/article'));
  }

  /**
   * Tests another port on the site's host is another site.
   *
   * Drupal would also take the base URL of what it generates next from the
   * subrequest, port included.
   */
  public function testAnotherPortIsRefused(): void {
    self::assertRefused(FetchRefusal::CrossSiteUrl, $this->fetcher(), self::request('https://example.com:9999/jsonapi/node/article'));
    self::assertRefused(FetchRefusal::CrossSiteUrl, $this->fetcher(), self::request('//example.com:80/jsonapi/node/article'));

    // The site's own port isn't.
    $fetcher = $this->fetcher(current_url: 'https://example.com:8443/');
    $response = $fetcher->fetch(self::request('https://example.com:8443/jsonapi/node/article'));
    self::assertSame('https://example.com:8443/jsonapi/node/article', (string) $response->getUri());
    self::assertRefused(FetchRefusal::CrossSiteUrl, $fetcher, self::request('https://example.com/jsonapi/node/article'));
  }

  public function testEmptyUrlIsRefused(): void {
    // The marshaler rejects empty URLs, but a PHP caller can still pass an
    // empty URI to the fetcher.
    self::assertRefused(FetchRefusal::EmptyUrl, $this->fetcher(), self::request(''));
  }

  public function testNonAllowlistedPathIsRefused(): void {
    self::assertRefused(FetchRefusal::PathNotAllowed, $this->fetcher(), self::request('/admin/content'));
  }

  public function testNonGetHeadMethodIsRefused(): void {
    self::assertRefused(FetchRefusal::MethodNotAllowed, $this->fetcher(), self::request('/jsonapi/node/article', 'POST'));
  }

  /**
   * Asserts a fetch is refused, and why.
   *
   * @param \Drupal\canvas_ssr\Fetch\Exception\FetchRefusal $reason
   *   The reason expected.
   * @param \Drupal\canvas_ssr\Fetch\SameSiteFetch $fetcher
   *   The fetcher.
   * @param \Psr\Http\Message\RequestInterface $request
   *   The request it refuses.
   */
  private static function assertRefused(FetchRefusal $reason, SameSiteFetch $fetcher, RequestInterface $request): void {
    try {
      $fetcher->fetch($request);
    }
    catch (FetchRefusedException $e) {
      self::assertSame($reason, $e->reason);

      return;
    }
    self::fail('The fetch was not refused.');
  }

}
