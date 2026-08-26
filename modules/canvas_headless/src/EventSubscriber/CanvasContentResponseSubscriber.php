<?php

declare(strict_types=1);

namespace Drupal\canvas_headless\EventSubscriber;

// cspell:ignore Repr

use Drupal\canvas_headless\CanvasContentProblemResponse;
use Drupal\canvas_headless\StackMiddleware\CanvasContentApiRequest;
use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Cache\CacheableResponseInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use GuzzleHttp\Psr7\Uri;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Finalizes cacheability and redirects for Canvas content API responses.
 */
final class CanvasContentResponseSubscriber implements EventSubscriberInterface {

  /**
   * Headers that no longer describe a response after its body is replaced.
   */
  private const REPLACED_BODY_HEADERS = [
    'Accept-Ranges',
    'Content-Digest',
    'Content-Disposition',
    'Content-Encoding',
    'Content-Length',
    'Content-Location',
    'Content-MD5',
    'Content-Range',
    'Content-Type',
    'Digest',
    'ETag',
    'Last-Modified',
    'Repr-Digest',
    'Trailer',
    'Transfer-Encoding',
  ];

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Adds shared dependencies before Dynamic Page Cache stores the response.
   */
  public function addCacheability(ResponseEvent $event): void {
    $request = $event->getRequest();
    if (!self::isContentApiRequest($request)) {
      return;
    }

    $response = $event->getResponse();
    if (!$response instanceof CacheableResponseInterface) {
      return;
    }

    // Route rebuilds and path alias changes invalidate the route_match tag.
    $cacheability = (new CacheableMetadata())
      ->addCacheContexts([
        'languages:language_url',
        'url',
      ])
      ->addCacheTags([
        'config:core.extension',
        'route_match',
      ]);
    if ($this->entityTypeManager->hasDefinition('redirect')) {
      $cacheability
        ->addCacheTags(
          $this->entityTypeManager
            ->getDefinition('redirect')
            ->getListCacheTags(),
        )
        ->addCacheTags(['config:redirect.settings']);
    }
    $response->addCacheableDependency($cacheability);
  }

  /**
   * Converts every endpoint error to RFC 9457 Problem Details.
   */
  public static function convertError(ResponseEvent $event): void {
    $request = $event->getRequest();
    $original_response = $event->getResponse();
    $status_code = $original_response->getStatusCode();
    if (
      !self::isContentApiRequest($request) ||
      $status_code < 400 ||
      $original_response instanceof CanvasContentProblemResponse
    ) {
      return;
    }

    $response = new CanvasContentProblemResponse($status_code);
    if ($original_response instanceof CacheableResponseInterface) {
      $response->addCacheableDependency($original_response->getCacheableMetadata());
    }
    else {
      $response->getCacheableMetadata()->setCacheMaxAge(0);
    }
    self::copyHeadersExcept(
      $original_response,
      $response,
      self::REPLACED_BODY_HEADERS,
    );
    $response->headers->set('Content-Type', 'application/problem+json');
    $event->setResponse($response);
  }

  /**
   * Converts safe routed redirects after core finishes redirect handling.
   */
  public static function convertRedirect(ResponseEvent $event): void {
    $request = $event->getRequest();
    $original_response = $event->getResponse();
    if (
      \is_string($request->attributes->get(CanvasContentApiRequest::REQUESTED_URI_ATTRIBUTE)) &&
      $original_response instanceof RedirectResponse
    ) {
      $url = self::normalizeRedirectTarget($original_response->getTargetUrl(), $request);
      $response = new CacheableJsonResponse([
        'redirect' => [
          'external' => UrlHelper::isExternal($url),
          'url' => $url,
          'statusCode' => $original_response->getStatusCode(),
        ],
      ]);
      if ($original_response instanceof CacheableResponseInterface) {
        $response->addCacheableDependency($original_response->getCacheableMetadata());
      }
      else {
        $response->getCacheableMetadata()->setCacheMaxAge(0);
      }
      self::copyHeadersExcept(
        $original_response,
        $response,
        [...self::REPLACED_BODY_HEADERS, 'Location', 'Refresh'],
      );
      $response->headers->set('Content-Type', 'application/json');
      $event->setResponse($response);
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      KernelEvents::RESPONSE => [
        ['convertError', 9],
        ['addCacheability', 8],
        ['convertRedirect', -11],
      ],
    ];
  }

  /**
   * Copies headers that still describe the new response.
   *
   * @param \Symfony\Component\HttpFoundation\Response $source
   *   The response providing the header values.
   * @param \Symfony\Component\HttpFoundation\Response $target
   *   The response receiving the copied headers.
   * @param string[] $excluded_header_names
   *   The header names not to copy.
   */
  private static function copyHeadersExcept(
    Response $source,
    Response $target,
    array $excluded_header_names,
  ): void {
    $excluded_header_names = array_fill_keys(
      \array_map('strtolower', $excluded_header_names),
      TRUE,
    );
    foreach ($source->headers->all() as $header_name => $header_values) {
      if (isset($excluded_header_names[strtolower($header_name)])) {
        continue;
      }
      $values = [];
      foreach ($header_values as $value) {
        if ($value !== NULL) {
          $values[] = $value;
        }
      }
      if ($values === []) {
        continue;
      }
      $target->headers->set($header_name, $values);
    }
  }

  /**
   * Rewrites same-site absolute redirect targets into relative paths.
   */
  private static function normalizeRedirectTarget(
    string $url,
    Request $request,
  ): string {
    if (!UrlHelper::isExternal($url)) {
      return $url;
    }

    $base_url = $request->getSchemeAndHttpHost() . $request->getBasePath() . '/';
    if (!UrlHelper::externalIsLocal($url, $base_url)) {
      return $url;
    }

    $parts = parse_url($url);
    if (!\is_array($parts)) {
      return $url;
    }

    // Rebuild without scheme/host/port so the result is a relative reference.
    return (string) Uri::fromParts([
      'path' => self::stripBasePath((string) ($parts['path'] ?? '/'), $request->getBasePath()),
      'query' => $parts['query'] ?? '',
      'fragment' => $parts['fragment'] ?? '',
    ]);
  }

  /**
   * Removes the Drupal base path from a root-relative path.
   */
  private static function stripBasePath(string $path, string $base_path): string {
    if ($base_path === '') {
      return $path;
    }

    return match (TRUE) {
      $path === $base_path => '/',
      str_starts_with($path, $base_path . '/') => substr($path, strlen($base_path)),
      default => $path,
    };
  }

  /**
   * Checks both the public endpoint request and its routed target request.
   */
  private static function isContentApiRequest(
    Request $request,
  ): bool {
    return \is_string($request->attributes->get(CanvasContentApiRequest::REQUESTED_URI_ATTRIBUTE)) ||
      $request->getPathInfo() === CanvasContentApiRequest::API_PATH;
  }

}
