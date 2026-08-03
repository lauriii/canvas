<?php

declare(strict_types=1);

namespace Drupal\canvas_headless\StackMiddleware;

use Drupal\canvas_headless\CanvasContentProblemResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Routes Canvas content API requests through the requested Drupal URI.
 */
final class CanvasContentApiRequest implements HttpKernelInterface {

  public const API_PATH = '/canvas/content-api';

  public const REQUEST_FORMAT = 'canvas_headless';

  public const REQUESTED_URI_ATTRIBUTE = '_canvas_headless_content_api_request_uri';

  public function __construct(
    private readonly HttpKernelInterface $httpKernel,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function handle(
    Request $request,
    int $type = self::MAIN_REQUEST,
    bool $catch = TRUE,
  ): Response {
    if (
      $type !== self::MAIN_REQUEST ||
      $request->getMethod() !== 'GET' ||
      $request->getPathInfo() !== self::API_PATH
    ) {
      return $this->httpKernel->handle($request, $type, $catch);
    }

    $request_uri = self::validatedRequestUri($request);
    if ($request_uri === NULL) {
      $response = new CanvasContentProblemResponse(
        400,
        'The requestUri query parameter must be a site-relative URI without a fragment.',
      );
      $response->getCacheableMetadata()->setCacheMaxAge(0);
      return $response;
    }
    $query_string = (string) (parse_url($request_uri, PHP_URL_QUERY) ?? '');
    parse_str($query_string, $target_query);
    $target_request = $request->duplicate(
      $target_query,
      attributes: [
        ...$request->attributes->all(),
        self::REQUESTED_URI_ATTRIBUTE => $request_uri,
      ],
      server: [
        ...$request->server->all(),
        'QUERY_STRING' => $query_string,
        'REQUEST_URI' => $request->getBaseUrl() . $request_uri,
      ],
    );
    // Dynamic Page Cache varies by request format, keeping this response
    // separate from `html` and `custom_elements` responses for the target.
    $target_request->setRequestFormat(self::REQUEST_FORMAT);
    $target_request->headers->add(
      $request->headers->all() + $target_request->headers->all(),
    );

    return $this->httpKernel->handle($target_request, $type, $catch);
  }

  /**
   * Reads a safe site-relative Drupal URI without a fragment.
   */
  private static function validatedRequestUri(Request $request): ?string {
    $request_uri = $request->query->all()['requestUri'] ?? NULL;
    if (
      !\is_string($request_uri) ||
      $request_uri === '' ||
      !str_starts_with($request_uri, '/') ||
      str_starts_with($request_uri, '//') ||
      str_contains($request_uri, '\\') ||
      str_contains($request_uri, '#')
    ) {
      return NULL;
    }
    return $request_uri;
  }

}
