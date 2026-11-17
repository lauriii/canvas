<?php

declare(strict_types=1);

namespace Drupal\canvas_ssr\Fetch;

use Drupal\canvas_ssr\Fetch\Exception\FetchRefusal;
use Drupal\canvas_ssr\Fetch\Exception\FetchRefusedException;
use Drupal\canvas_ssr\Fetch\Message\Response;
use Drupal\canvas_ssr\Fetch\Message\ResponseInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Cache\CacheableResponseInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\PathProcessor\InboundPathProcessorInterface;
use Drupal\Core\Render\RenderContext;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Routing\RouteObjectInterface;
use Drupal\language\ConfigurableLanguageManagerInterface;
use GuzzleHttp\Psr7\Uri;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\UriInterface;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpFoundation\UrlHelper;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Routing\Route;

/**
 * The same-site fetch: answers allowed requests with Drupal subrequests.
 *
 * The path policies decide what's allowed. Subrequests carry the current
 * request's cookies and session.
 *
 * @see docs/adr/0006-fetch-is-a-drupal-subrequest.md
 */
final readonly class SameSiteFetch implements FetchInterface {

  /**
   * Headers a component's fetch() call can't set.
   *
   * The host, credentials and session come from the current request, and
   * GET and HEAD have no body.
   */
  private const array HEADERS_NOT_FORWARDED = [
    'authorization',
    'connection',
    'content-length',
    'cookie',
    'host',
    'transfer-encoding',
  ];

  /**
   * What a response varies by when one of these methods chose a language.
   *
   * Each reads the visitor and not the URL, so the response is for that
   * visitor's language. Keyed by negotiation method ID.
   */
  private const array VISITOR_NEGOTIATION = [
    'language-browser' => ['headers:Accept-Language'],
    'language-user' => ['user'],
    'language-user-admin' => ['user'],
    'language-session' => ['session', 'url.query_args'],
  ];

  /**
   * Constructs a SameSiteFetch.
   *
   * @param \Symfony\Component\HttpKernel\HttpKernelInterface $httpKernel
   *   Runs the subrequests.
   * @param \Symfony\Component\HttpFoundation\RequestStack $requestStack
   *   The request stack.
   * @param \Drupal\Core\Render\RendererInterface $renderer
   *   Runs subrequests in their own render context.
   * @param \Drupal\Core\PathProcessor\InboundPathProcessorInterface $pathProcessor
   *   Turns a path into its system path for the policies, as the router
   *   will.
   * @param iterable<\Drupal\canvas_ssr\Fetch\Policy\PathPolicyInterface> $pathPolicies
   *   The policies deciding which paths are allowed.
   * @param \Drupal\Core\Language\LanguageManagerInterface $languageManager
   *   Negotiates the languages again for a subrequest, and after it.
   */
  public function __construct(
    private HttpKernelInterface $httpKernel,
    private RequestStack $requestStack,
    private RendererInterface $renderer,
    private InboundPathProcessorInterface $pathProcessor,
    private iterable $pathPolicies,
    private LanguageManagerInterface $languageManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function fetch(RequestInterface $request): ResponseInterface {
    $current_request = $this->requestStack->getCurrentRequest();
    if ($current_request === NULL) {
      throw new \RuntimeException('No request is active, so SSR cannot fetch.');
    }

    $method = $request->getMethod();
    if ($method !== 'GET' && $method !== 'HEAD') {
      self::refuse(
        FetchRefusal::MethodNotAllowed,
        \sprintf('SSR fetch only supports GET/HEAD, got "%s".', $method)
      );
    }

    $uri = $this->toSameSiteUrl($request->getUri(), $current_request);
    $subrequest = self::buildSubrequest($current_request, $uri, $request);
    $this->checkPath($uri, $subrequest, $current_request, $request->getUri());

    // The language manager keeps what it negotiates for the whole PHP
    // request, whichever request asked first. So the subrequest negotiates
    // its own, as a URL with another language's prefix needs, and the
    // current request negotiates again after, or else the rest of the page
    // would render in the subrequest's languages.
    $this->resetLanguages();
    try {
      $response = $this->renderer->executeInRenderContext(
        new RenderContext(),
        fn(): SymfonyResponse => $this->httpKernel->handle($subrequest, HttpKernelInterface::SUB_REQUEST),
      );
      $language_contexts = $this->languageContexts($request->hasHeader('Accept-Language'));
    }
    finally {
      $this->resetLanguages();
    }
    if ($method === 'HEAD') {
      // HEAD has no body. NULL, because a streamed response accepts only
      // that.
      $response->setContent(NULL);
    }

    return self::toFetchResponse($response, $subrequest, $uri, $language_contexts);
  }

  /**
   * Returns what the subrequest's languages vary by, besides its URL.
   *
   * A URL without a language prefix may leave the language to the visitor's
   * browser, account or session, which the subrequest carries. The
   * response then holds for visitors with the same, and not for all who
   * see the page in its language.
   *
   * @param bool $sets_accept_language
   *   Whether the component's request set Accept-Language itself. Then the
   *   subrequest carried that, and not the visitor's.
   *
   * @return list<string>
   *   The cache contexts.
   */
  private function languageContexts(bool $sets_accept_language): array {
    if (!$this->languageManager instanceof ConfigurableLanguageManagerInterface) {
      return [];
    }
    $contexts = [];
    foreach ($this->languageTypes() as $type) {
      $method = $this->languageManager->getNegotiatedLanguageMethod($type);
      if ($method === 'language-browser' && $sets_accept_language) {
        continue;
      }
      $contexts = [...$contexts, ...self::VISITOR_NEGOTIATION[$method] ?? []];
    }

    return \array_values(\array_unique($contexts));
  }

  /**
   * Returns every language type.
   *
   * @return list<string>
   *   The types.
   */
  private function languageTypes(): array {
    return \array_values(\array_unique([
      LanguageInterface::TYPE_INTERFACE,
      LanguageInterface::TYPE_CONTENT,
      LanguageInterface::TYPE_URL,
      ...$this->languageManager->getLanguageTypes(),
    ]));
  }

  /**
   * Makes the language manager negotiate every language type again.
   */
  private function resetLanguages(): void {
    foreach ($this->languageTypes() as $type) {
      $this->languageManager->reset($type);
    }
    // The negotiator keeps what each method found, too.
    if ($this->languageManager instanceof ConfigurableLanguageManagerInterface) {
      $this->languageManager->getNegotiator()?->reset();
    }
  }

  /**
   * Converts a subrequest's response for the fetch.
   *
   * @param \Symfony\Component\HttpFoundation\Response $response
   *   The subrequest's response.
   * @param \Symfony\Component\HttpFoundation\Request $subrequest
   *   The subrequest, with the route it matched.
   * @param \Psr\Http\Message\UriInterface $uri
   *   The URL it came from.
   * @param list<string> $language_contexts
   *   What the subrequest's languages vary by, besides its URL.
   *
   * @return \Drupal\canvas_ssr\Fetch\Message\Response
   *   The PSR-7 response, with the URL and the subrequest's cacheability, if
   *   it's known.
   */
  private static function toFetchResponse(SymfonyResponse $response, SymfonyRequest $subrequest, UriInterface $uri, array $language_contexts): Response {
    // all() includes cookies, which fetch() in a browser doesn't show a
    // script, and which could carry a session ID into cached markup. A
    // NULL value is sent as empty.
    $headers = [];
    foreach ($response->headers->all() as $name => $values) {
      if (\in_array(\strtolower($name), ['set-cookie', 'set-cookie2'], TRUE)) {
        continue;
      }
      $headers[$name] = \array_map(
        static fn(?string $value): string => (string) $value,
        $values,
      );
    }

    $fetch_response = (new Response($response->getStatusCode(), $headers, self::body($response)))
      ->withUri($uri);
    $cacheability = self::cacheabilityFromResponse($response, $subrequest);
    if ($cacheability === NULL) {
      return $fetch_response->withoutCacheability();
    }
    $fetch_response->addCacheableDependency($cacheability->addCacheContexts($language_contexts));

    return $fetch_response;
  }

  /**
   * Returns a subrequest response's body.
   *
   * @param \Symfony\Component\HttpFoundation\Response $response
   *   The subrequest's response.
   *
   * @return string
   *   The body.
   */
  private static function body(SymfonyResponse $response): string {
    $content = $response->getContent();
    if ($content !== FALSE) {
      return $content;
    }

    // A streamed response returns FALSE. The allowed endpoints don't stream,
    // but other policies might allow one that does, so capture its output.
    \ob_start();
    $response->sendContent();

    return (string) \ob_get_clean();
  }

  /**
   * Builds a subrequest with the current request's context.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request, for its server variables, cookies and session.
   * @param \Psr\Http\Message\UriInterface $uri
   *   The checked absolute URL.
   * @param \Psr\Http\Message\RequestInterface $fetch_request
   *   The component's request, whose method has been checked. Its headers
   *   are passed as HTTP_* server variables, so code reading either the
   *   headers or the server variables sees them.
   *
   * @return \Symfony\Component\HttpFoundation\Request
   *   The subrequest.
   */
  private static function buildSubrequest(SymfonyRequest $request, UriInterface $uri, RequestInterface $fetch_request): SymfonyRequest {
    $server = $request->server->all();
    foreach (\array_keys($fetch_request->getHeaders()) as $name) {
      $name = \strtolower($name);
      if (\in_array($name, self::HEADERS_NOT_FORWARDED, TRUE)) {
        continue;
      }
      $server['HTTP_' . \strtoupper(\str_replace('-', '_', $name))] = $fetch_request->getHeaderLine($name);
    }

    $subrequest = SymfonyRequest::create(
      (string) $uri,
      $fetch_request->getMethod(),
      [],
      $request->cookies->all(),
      [],
      $server,
    );
    if ($request->hasPreviousSession()) {
      $subrequest->setSession($request->getSession());
    }
    return $subrequest;
  }

  /**
   * Makes a URL absolute, and checks that it's same-site.
   *
   * @param \Psr\Http\Message\UriInterface $requested_uri
   *   The URL as the component gave it.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   *
   * @return \Psr\Http\Message\UriInterface
   *   The absolute URL.
   *
   * @throws \Drupal\canvas_ssr\Fetch\Exception\FetchRefusedException
   *   When the URL is refused.
   */
  private function toSameSiteUrl(
    UriInterface $requested_uri,
    SymfonyRequest $request,
  ): UriInterface {
    $requested_url = (string) $requested_uri;
    if ($requested_url === '') {
      self::refuse(FetchRefusal::EmptyUrl, 'SSR fetch was given an empty URL.');
    }

    // Resolve against the current request. Absolute URLs are unchanged.
    $absolute_url = (new UrlHelper($this->requestStack))->getAbsoluteUrl($requested_url);
    try {
      $absolute_uri = new Uri($absolute_url);
    }
    catch (\InvalidArgumentException) {
      self::refuse(
        FetchRefusal::InvalidUrl,
        \sprintf('SSR fetch cannot parse the URL "%s".', $requested_url)
      );
    }

    // Another port is another site. Drupal also takes the base URL of
    // everything it generates next from the last request it handled.
    $port = $absolute_uri->getPort() ?? ($absolute_uri->getScheme() === 'https' ? 443 : 80);
    if (
      $absolute_uri->getScheme() !== $request->getScheme()
      || $absolute_uri->getHost() !== $request->getHost()
      || $port !== (int) $request->getPort()
    ) {
      self::refuse(
        FetchRefusal::CrossSiteUrl,
        \sprintf('SSR fetch only supports same-site URLs, got "%s".', $requested_url)
      );
    }

    return $absolute_uri;
  }

  /**
   * Checks a subrequest's path against the path policies.
   *
   * A policy must allow its system path.
   *
   * @param \Psr\Http\Message\UriInterface $uri
   *   The subrequest's absolute URL.
   * @param \Symfony\Component\HttpFoundation\Request $subrequest
   *   The subrequest.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   * @param \Psr\Http\Message\UriInterface $requested_uri
   *   The URL as the component gave it.
   *
   * @throws \Drupal\canvas_ssr\Fetch\Exception\FetchRefusedException
   *   When no policy allows the path.
   */
  private function checkPath(UriInterface $uri, SymfonyRequest $subrequest, SymfonyRequest $request, UriInterface $requested_uri): void {
    $path = $uri->getPath() === '' ? '/' : $uri->getPath();
    $relative = self::toBaseRelativePath($path, $request);
    if ($relative !== NULL) {
      // Check the system path, without a language prefix like "/es" or an
      // alias. The subrequest still uses the original URL, so language
      // negotiation sees the prefix. An inbound path processor may change
      // the request it's given, as the one for image styles does, and the
      // router runs them again on the subrequest. So they get a copy here,
      // and never the current request, whose path this isn't.
      $system_path = $this->pathProcessor->processInbound($relative, clone $subrequest);
      foreach ($this->pathPolicies as $policy) {
        if ($policy->isAllowed($system_path)) {
          return;
        }
      }
    }

    self::refuse(
      FetchRefusal::PathNotAllowed,
      \sprintf(
        'SSR fetch only supports paths a path policy allows, got "%s".',
        (string) $requested_uri
      )
    );
  }

  /**
   * Strips the site's base path, for a site installed in a subdirectory.
   *
   * @param string $path
   *   The request path, including any base path.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   *
   * @return string|null
   *   The path without the base path, or NULL if it's outside the base path.
   */
  private static function toBaseRelativePath(string $path, SymfonyRequest $request): ?string {
    $base_path = \rtrim($request->getBaseUrl(), '/');
    if ($base_path === '') {
      return $path;
    }
    if ($path === $base_path) {
      return '/';
    }
    if (\str_starts_with($path, $base_path . '/')) {
      return \substr($path, \strlen($base_path));
    }
    return NULL;
  }

  /**
   * Refuses the request.
   *
   * @param \Drupal\canvas_ssr\Fetch\Exception\FetchRefusal $reason
   *   Which rule refused it.
   * @param string $message
   *   Why, in words.
   */
  private static function refuse(FetchRefusal $reason, string $message): never {
    throw new FetchRefusedException($reason, $message);
  }

  /**
   * Returns a subrequest response's cacheability, if it's known.
   *
   * The allowed endpoints give cacheable responses. One that isn't, like
   * the error page core gives a fatal error, says nothing of how long it
   * holds. Nor does one from a route marked no_cache, which mustn't be
   * cached whatever it says, as Drupal's page caches treat it.
   *
   * @param \Symfony\Component\HttpFoundation\Response $response
   *   The subrequest's response.
   * @param \Symfony\Component\HttpFoundation\Request $subrequest
   *   The subrequest, with the route it matched.
   *
   * @return \Drupal\Core\Cache\CacheableMetadata|null
   *   Its cacheability, or NULL if that isn't known.
   *
   * @see \Drupal\Core\PageCache\ResponsePolicy\DenyNoCacheRoutes
   */
  private static function cacheabilityFromResponse(SymfonyResponse $response, SymfonyRequest $subrequest): ?CacheableMetadata {
    $route = $subrequest->attributes->get(RouteObjectInterface::ROUTE_OBJECT);
    if (!$response instanceof CacheableResponseInterface || ($route instanceof Route && $route->getOption('no_cache'))) {
      return NULL;
    }

    return CacheableMetadata::createFromObject($response->getCacheableMetadata());
  }

}
