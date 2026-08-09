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

  public const API_QUERY_PARAMETERS_KEY = '_canvas_headless_content_api_query_parameters';

  public const PREVIEW_VIEW_MODE_QUERY = 'viewMode';

  private const SUPPORTED_API_QUERY_PARAMETERS = [
    self::PREVIEW_VIEW_MODE_QUERY,
  ];

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
    $api_query_parameters = self::supportedApiQueryParameters($request);
    // Mirror supported API parameters into a namespaced query parameter so the
    // broad `url` cache context varies without replacing requested URI values.
    unset($target_query[self::API_QUERY_PARAMETERS_KEY]);
    if ($api_query_parameters !== []) {
      $target_query[self::API_QUERY_PARAMETERS_KEY] = $api_query_parameters;
    }
    $target_query_string = http_build_query($target_query);
    $target_path = (string) parse_url($request_uri, PHP_URL_PATH);
    $target_request_uri = $request->getBaseUrl() . $target_path;
    if ($target_query_string !== '') {
      $target_request_uri .= '?' . $target_query_string;
    }
    $target_request = $request->duplicate(
      $target_query,
      attributes: [
        ...$request->attributes->all(),
        self::REQUESTED_URI_ATTRIBUTE => $request_uri,
        self::API_QUERY_PARAMETERS_KEY => $api_query_parameters,
      ],
      server: [
        ...$request->server->all(),
        'QUERY_STRING' => $target_query_string,
        'REQUEST_URI' => $target_request_uri,
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

  /**
   * Reads an optional non-empty string query parameter.
   */
  private static function optionalStringQueryParameter(Request $request, string $name): ?string {
    $value = $request->query->all()[$name] ?? NULL;
    return \is_string($value) && $value !== '' ? $value : NULL;
  }

  /**
   * Returns supported content API query parameters for the routed request.
   *
   * @return array<string, string>
   */
  private static function supportedApiQueryParameters(Request $request): array {
    $parameters = [];
    foreach (self::SUPPORTED_API_QUERY_PARAMETERS as $name) {
      $value = self::optionalStringQueryParameter($request, $name);
      if ($value !== NULL) {
        $parameters[$name] = $value;
      }
    }
    return $parameters;
  }

}
