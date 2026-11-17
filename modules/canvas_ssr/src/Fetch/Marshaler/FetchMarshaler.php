<?php

declare(strict_types=1);

namespace Drupal\canvas_ssr\Fetch\Marshaler;

use Drupal\canvas_ssr\Fetch\Message\ResponseInterface;
use GuzzleHttp\Psr7\Request;
use Psr\Http\Message\RequestInterface;

/**
 * Converts fetch() requests and responses to and from strings.
 *
 * A binding carries only strings. JS marshals the request and PHP
 * unmarshals it. PHP marshals the response and JS unmarshals it. The two JS
 * halves live in files beside this class, read when the container is built.
 *
 * @see \Drupal\canvas_ssr\JsRunner\RunnerInterface::bindFunction()
 * @see docs/adr/0005-bindings-are-synchronous-and-carry-strings.md
 */
final class FetchMarshaler {

  /**
   * The JS file that turns a fetch() call into a string.
   */
  private const string REQUEST_MARSHALER_FILE = 'marshal-fetch-request.js';

  /**
   * The JS file that turns a response string into a Response.
   */
  private const string RESPONSE_UNMARSHALER_FILE = 'unmarshal-fetch-response.js';

  /**
   * The longest request string decoded, in bytes.
   *
   * A request is a URL, a few headers and a short call stack. A component
   * shapes the string, and a wide one, like headers with a million names,
   * takes PHP many times its length to decode.
   */
  private const int REQUEST_LIMIT = 256 * 1024;

  /**
   * The most headers a request may have.
   *
   * Each one copies the request with all the headers before it, so a
   * component that sent thousands would hold PHP for seconds.
   */
  private const int HEADER_LIMIT = 100;

  /**
   * Constructs a FetchMarshaler.
   *
   * @param string $requestMarshalerJs
   *   The JS that turns a fetch() call into a string.
   * @param string $responseUnmarshalerJs
   *   The JS that turns a response string into a Response.
   *
   * @see self::readJs()
   */
  public function __construct(
    private readonly string $requestMarshalerJs,
    private readonly string $responseUnmarshalerJs,
  ) {}

  /**
   * Reads the two JS halves from their files.
   *
   * The service provider calls this when the container is built, so no
   * request reads the files.
   *
   * @return array{string, string}
   *   The constructor's arguments.
   *
   * @throws \RuntimeException
   *   When a file can't be read.
   *
   * @see \Drupal\canvas_ssr\CanvasSsrServiceProvider::alter()
   */
  public static function readJs(): array {
    $read = static function (string $file_name): string {
      $path = __DIR__ . '/' . $file_name;
      $source = \file_get_contents($path);
      if ($source === FALSE) {
        throw new \RuntimeException("Failed to read SSR fetch marshaler from $path");
      }
      return $source;
    };

    return [
      $read(self::REQUEST_MARSHALER_FILE),
      $read(self::RESPONSE_UNMARSHALER_FILE),
    ];
  }

  /**
   * Returns the JS that turns a fetch() call into a request string.
   *
   * @return string
   *   A self-contained JS function expression.
   */
  public function marshalRequestJs(): string {
    return $this->requestMarshalerJs;
  }

  /**
   * Returns the JS that turns a response string into a Response.
   *
   * @return string
   *   A self-contained JS function expression.
   */
  public function unmarshalResponseJs(): string {
    return $this->responseUnmarshalerJs;
  }

  /**
   * Reads a request string from the JS side.
   *
   * A component's code shaped the string, so everything is checked.
   *
   * @param string $json
   *   The marshaled request.
   *
   * @return \Psr\Http\Message\RequestInterface
   *   The request.
   *
   * @throws \InvalidArgumentException
   *   When the string is too long, isn't an object, the URL is missing or
   *   invalid, the method is missing or isn't upper-case, or there are too
   *   many headers.
   * @throws \JsonException
   *   When the string isn't JSON, or is nested deeper than a request.
   */
  public static function unmarshalRequest(string $json): RequestInterface {
    $decoded = self::decodeRequest($json);
    if (!\is_array($decoded)) {
      throw new \InvalidArgumentException('SSR fetch request payload must decode to an object.');
    }

    $url = $decoded['url'] ?? NULL;
    // Trim before it becomes a URI, which would percent-encode whitespace.
    $url = \is_string($url) ? \trim($url) : '';
    if ($url === '') {
      throw new \InvalidArgumentException('SSR fetch requires a URL.');
    }

    // The JS side always sends one: as in the Fetch standard, a request's
    // method is GET unless the call gives another. So a missing one means
    // the request didn't come from it.
    $method = $decoded['method'] ?? NULL;
    if (!\is_string($method) || $method === '') {
      throw new \InvalidArgumentException('SSR fetch requires a method.');
    }
    // The JS side normalizes the method as the Fetch standard does, which
    // leaves "patch" lowercase. Guzzle's request would upper-case it,
    // sending a different method, so refuse it instead.
    if ($method !== \strtoupper($method)) {
      throw new \InvalidArgumentException("SSR fetch method must be upper-case, got \"$method\".");
    }

    return self::withHeaders(
      new Request($method, $url),
      $decoded['headers'] ?? NULL,
    );
  }

  /**
   * Decodes a request string, refusing one longer or deeper than a request.
   *
   * @param string $json
   *   The marshaled request.
   *
   * @return mixed
   *   What it decodes to.
   *
   * @throws \InvalidArgumentException
   *   When the string is too long.
   * @throws \JsonException
   *   When the string isn't JSON, or is nested deeper than a request: an
   *   object, whose headers are an object of values. A value that isn't a
   *   string, such as a list, is dropped later, so one level more decodes.
   */
  private static function decodeRequest(string $json): mixed {
    if (\strlen($json) > self::REQUEST_LIMIT) {
      throw new \InvalidArgumentException('SSR fetch request is longer than ' . self::REQUEST_LIMIT . ' bytes.');
    }
    return \json_decode($json, TRUE, 4, JSON_THROW_ON_ERROR);
  }

  /**
   * Tells which of some modules made a fetch() call, from its request string.
   *
   * The request carries the call stack, which names each frame's module by
   * its URL. Only the frames of the call itself count, not those of
   * callers awaiting it, which not every runner gives.
   *
   * @param string $json
   *   The marshaled request.
   * @param list<string> $module_urls
   *   The module URLs to look for.
   *
   * @return list<string>
   *   Those with code on the stack. None when the stack can't be read, or
   *   names none of them, as after the call was deferred.
   */
  public static function callers(string $json, array $module_urls): array {
    try {
      $decoded = self::decodeRequest($json);
    }
    catch (\InvalidArgumentException | \JsonException) {
      return [];
    }
    $stack = \is_array($decoded) ? ($decoded['stack'] ?? NULL) : NULL;
    if (!\is_string($stack)) {
      return [];
    }
    $callers = [];
    foreach (\explode("\n", $stack) as $frame) {
      // A frame ends with its module URL, line and column, in brackets if
      // a function name comes first. A component could name a function
      // anything, so only the end is read.
      if (\preg_match('/^\s*at (?!async )(.*):\d+:\d+\)?$/', $frame, $matches) !== 1) {
        continue;
      }
      foreach ($module_urls as $url) {
        if ($matches[1] === $url || \str_ends_with($matches[1], " ($url")) {
          $callers[$url] = TRUE;
        }
      }
    }

    return \array_keys($callers);
  }

  /**
   * Turns a response into a string for the JS side.
   *
   * @param \Drupal\canvas_ssr\Fetch\Message\ResponseInterface $response
   *   The response.
   *
   * @return string
   *   The marshaled response.
   *
   * @throws \JsonException
   *   When the response can't be encoded.
   */
  public static function marshalResponse(ResponseInterface $response): string {
    // The JS side looks headers up by lowercase name.
    $headers = [];
    foreach (\array_keys($response->getHeaders()) as $name) {
      $headers[\strtolower($name)] = $response->getHeaderLine($name);
    }

    $encoded = \json_encode([
      'status' => $response->getStatusCode(),
      'statusText' => $response->getReasonPhrase(),
      'headers' => $headers,
      'body' => (string) $response->getBody(),
      'url' => (string) $response->getUri(),
    ], JSON_THROW_ON_ERROR);
    \assert(\is_string($encoded));

    return $encoded;
  }

  /**
   * Converts a failed fetch to a string for the JS side.
   *
   * The JS side rejects with it, as the browser's fetch() does, rather than
   * throwing, which a bound callback's exception would.
   *
   * @param \Throwable $e
   *   Why the fetch failed.
   *
   * @return string
   *   The marshaled failure.
   */
  public static function marshalFailure(\Throwable $e): string {
    return \json_encode(['error' => $e->getMessage()], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
  }

  /**
   * Adds a fetch() call's valid headers to a request.
   *
   * A header without a valid name and a scalar value is dropped rather
   * than failing the fetch.
   *
   * @param \Psr\Http\Message\RequestInterface $request
   *   The request to add them to.
   * @param mixed $headers
   *   The headers as they arrived.
   *
   * @return \Psr\Http\Message\RequestInterface
   *   The request with the headers.
   *
   * @throws \InvalidArgumentException
   *   When there are too many headers.
   */
  private static function withHeaders(RequestInterface $request, mixed $headers): RequestInterface {
    if (!\is_array($headers)) {
      return $request;
    }
    if (\count($headers) > self::HEADER_LIMIT) {
      throw new \InvalidArgumentException('SSR fetch request has more than ' . self::HEADER_LIMIT . ' headers.');
    }

    foreach ($headers as $name => $value) {
      if (!\is_string($name) || !\is_scalar($value)) {
        continue;
      }
      try {
        $request = $request->withHeader($name, (string) $value);
      }
      catch (\InvalidArgumentException) {
        // Not a valid header name.
        continue;
      }
    }

    return $request;
  }

}
