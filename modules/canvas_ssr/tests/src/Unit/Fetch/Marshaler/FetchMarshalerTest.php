<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_ssr\Unit\Fetch\Marshaler;

use Drupal\canvas_ssr\Fetch\Marshaler\FetchMarshaler;
use Drupal\canvas_ssr\Fetch\Message\Response;
use Drupal\Tests\UnitTestCase;
use GuzzleHttp\Psr7\Uri;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the PHP half of the fetch wire format.
 *
 * The kernel tests cover the JS half. This covers what a request may leave
 * out, and what gets dropped. It uses real PSR-7 messages, since what they
 * end up holding is the point.
 */
#[CoversClass(FetchMarshaler::class)]
#[Group('canvas_ssr')]
final class FetchMarshalerTest extends UnitTestCase {

  /**
   * Tests a request naming only a URL and a method needs nothing else.
   */
  public function testUrlAndMethodAreTheOnlyRequiredFields(): void {
    $request = self::marshaler()->unmarshalRequest('{"url":"/jsonapi/node/article","method":"GET"}');

    self::assertSame('/jsonapi/node/article', (string) $request->getUri());
    self::assertSame('GET', $request->getMethod());
    self::assertSame([], $request->withoutHeader('host')->getHeaders());
  }

  /**
   * Tests a request with no method is rejected, not defaulted.
   *
   * The JS side always sends the method, as the Fetch standard gives every
   * request one. So defaulting here would only hide a request that didn't
   * come from it.
   *
   * @param string $json
   *   The marshaled request to reject.
   */
  #[DataProvider('providerMissingMethods')]
  public function testRequestWithNoMethodIsRejected(string $json): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('SSR fetch requires a method.');

    self::marshaler()->unmarshalRequest($json);
  }

  /**
   * Provides requests with no usable method.
   *
   * @return array<string, array{string}>
   *   The marshaled request.
   */
  public static function providerMissingMethods(): array {
    return [
      'missing' => ['{"url":"/x"}'],
      'empty' => ['{"url":"/x","method":""}'],
      'not a string' => ['{"url":"/x","method":null}'],
    ];
  }

  /**
   * Tests an upper-case method is passed on as the component wrote it.
   */
  public function testMethodIsPassedOnAsWritten(): void {
    $request = self::marshaler()->unmarshalRequest('{"url":"/x","method":"POST"}');

    self::assertSame('POST', $request->getMethod());
  }

  /**
   * Tests a method that is not upper-case is rejected, not rewritten.
   *
   * The JS side normalizes methods per the Fetch standard, which leaves
   * "patch" alone. guzzlehttp/psr7 would upper-case it to PATCH, a different
   * method, so it's rejected instead.
   */
  public function testNonUpperCaseMethodIsRejected(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('SSR fetch method must be upper-case, got "patch".');

    self::marshaler()->unmarshalRequest('{"url":"/x","method":"patch"}');
  }

  /**
   * Tests headers survive, and only the ones that can be sent as headers.
   */
  public function testOnlySendableHeadersSurvive(): void {
    $json = \json_encode([
      'url' => '/x',
      'method' => 'GET',
      'headers' => [
        'accept' => 'application/vnd.api+json',
        'x-count' => 3,
        'x-flag' => TRUE,
        // Not valid headers: two bad values and a bad name. Coercing them
        // would send something the component never asked for.
        'x-nested' => ['a', 'b'],
        'x-null' => NULL,
        'not a name' => 'x',
      ],
    ], JSON_THROW_ON_ERROR);
    \assert(\is_string($json));

    $request = self::marshaler()->unmarshalRequest($json);

    // PSR-7 adds Host from the URL.
    $headers = $request->withoutHeader('host')->getHeaders();
    self::assertSame(['accept', 'x-count', 'x-flag'], \array_keys($headers));
    self::assertSame('application/vnd.api+json', $request->getHeaderLine('accept'));
    self::assertSame('3', $request->getHeaderLine('x-count'));
    self::assertSame('1', $request->getHeaderLine('x-flag'));
  }

  /**
   * Tests headers that are not an object at all are dropped, not fatal.
   */
  public function testNonObjectHeadersYieldNoHeaders(): void {
    $request = self::marshaler()->unmarshalRequest('{"url":"/x","method":"GET","headers":"accept"}');

    self::assertSame([], $request->withoutHeader('host')->getHeaders());
  }

  /**
   * Tests a request with no usable URL is rejected.
   *
   * @param string $json
   *   The marshaled request to reject.
   */
  #[DataProvider('providerUnusableUrls')]
  public function testRequestWithNoUsableUrlIsRejected(string $json): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('SSR fetch requires a URL.');

    self::marshaler()->unmarshalRequest($json);
  }

  /**
   * Provides request strings that name no URL.
   *
   * @return array<string, array{string}>
   *   Marshaled requests keyed by what is wrong with them.
   */
  public static function providerUnusableUrls(): array {
    return [
      'absent' => ['{"method":"GET"}'],
      'empty' => ['{"url":""}'],
      // Caught while still a string. A URI would percent-encode it into
      // something that looks like a path.
      'whitespace' => ['{"url":"   "}'],
      'null' => ['{"url":null}'],
      'not a string' => ['{"url":42}'],
      'an object' => ['{"url":{"href":"/x"}}'],
    ];
  }

  /**
   * Tests a request that is JSON but not an object is rejected.
   */
  public function testNonObjectRequestIsRejected(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('SSR fetch request payload must decode to an object.');

    self::marshaler()->unmarshalRequest('"/jsonapi/node/article"');
  }

  /**
   * Tests a request that is not JSON is rejected.
   */
  public function testNonJsonRequestIsRejected(): void {
    $this->expectException(\JsonException::class);

    self::marshaler()->unmarshalRequest('not json');
  }

  /**
   * Tests a request nested deeper than a request goes is rejected.
   *
   * Its headers are an object of values, which a list may be, though it's
   * dropped, and no part goes deeper.
   */
  public function testRequestNestedTooDeepIsRejected(): void {
    $this->expectException(\JsonException::class);

    self::marshaler()->unmarshalRequest('{"url":"/x","method":"GET","headers":{"accept":[["text/html"]]}}');
  }

  /**
   * Tests a request too long to be one is rejected before it's decoded.
   *
   * A component shapes the string, and a wide one, like headers with a
   * million names, takes PHP many times its length to decode.
   */
  public function testRequestTooLongIsRejected(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('SSR fetch request is longer than 262144 bytes.');

    self::marshaler()->unmarshalRequest('{"url":"/x","method":"GET","headers":{' . \str_repeat('"a":"",', 40000) . '"b":""}}');
  }

  /**
   * Tests a request with more headers than a real one has is rejected.
   *
   * Each header copies the request, so thousands, which fit in the length
   * allowed, would hold PHP for seconds.
   */
  public function testRequestWithTooManyHeadersIsRejected(): void {
    $headers = [];
    for ($i = 0; $i < 100; $i++) {
      $headers["x-header-$i"] = 'v';
    }
    $request = ['url' => '/x', 'method' => 'GET', 'headers' => $headers];
    $unmarshaled = self::marshaler()->unmarshalRequest(\json_encode($request, \JSON_THROW_ON_ERROR));
    $this->assertCount(100, $unmarshaled->getHeaders());

    $request['headers']['x-header-100'] = 'v';
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('SSR fetch request has more than 100 headers.');
    self::marshaler()->unmarshalRequest(\json_encode($request, \JSON_THROW_ON_ERROR));
  }

  /**
   * Tests the callers of a request too long to be one aren't looked for.
   */
  public function testCallersOfTooLongRequestAreNotLookedFor(): void {
    $stack = "Error\n    at f (/component.js:1:1)";
    $request = \json_encode(['url' => '/x', 'method' => 'GET', 'headers' => [], 'stack' => $stack], \JSON_THROW_ON_ERROR);
    $this->assertSame(['/component.js'], FetchMarshaler::callers($request, ['/component.js']));

    $padded = \json_encode(['url' => '/x' . \str_repeat('x', 300000), 'method' => 'GET', 'headers' => [], 'stack' => $stack], \JSON_THROW_ON_ERROR);
    $this->assertSame([], FetchMarshaler::callers($padded, ['/component.js']));
  }

  /**
   * Tests a response crosses as the fields JS reads, and only those.
   *
   * That's the status and reason phrase, joined headers, the body and the
   * URL.
   */
  public function testResponsesCrossAsFieldsJsReads(): void {
    $response = (new Response(
      200,
      [
        'Content-Type' => 'application/vnd.api+json',
        'X-Drupal-Cache-Tags' => ['node_list', 'http_response'],
      ],
      '{"data":[]}',
    ))->withUri(new Uri('https://example.com/jsonapi/node/article'));

    $marshaled = self::marshaler()->marshalResponse($response);

    self::assertSame([
      'status' => 200,
      'statusText' => 'OK',
      'headers' => [
        // Names lower-cased, repeated values joined.
        'content-type' => 'application/vnd.api+json',
        'x-drupal-cache-tags' => 'node_list, http_response',
      ],
      'body' => '{"data":[]}',
      'url' => 'https://example.com/jsonapi/node/article',
    ], \json_decode($marshaled, TRUE, 512, JSON_THROW_ON_ERROR));
  }

  /**
   * Returns a marshaler over a real PSR-7 implementation.
   *
   * @return \Drupal\canvas_ssr\Fetch\Marshaler\FetchMarshaler
   *   The marshaler under test.
   */
  private static function marshaler(): FetchMarshaler {
    return new FetchMarshaler(...FetchMarshaler::readJs());
  }

}
