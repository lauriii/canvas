<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_ssr\Kernel\Fetch;

use Drupal\canvas_ssr\Fetch\FetchInterface;
use Drupal\canvas_ssr\Fetch\Message\Response;
use Drupal\canvas_ssr\Fetch\Message\ResponseInterface;
use GuzzleHttp\Psr7\Uri;
use Psr\Http\Message\RequestInterface;

/**
 * A fetch that answers some paths with canned responses.
 *
 * It stands in for an endpoint that answers in a way the allowed ones
 * rarely do, such as without cacheability. Every other request goes to the
 * real fetch.
 *
 * @see \Drupal\Tests\canvas_ssr\Kernel\Fetch\FetchCacheabilityTest
 */
final class CannedFetch implements FetchInterface {

  /**
   * The canned responses, keyed by path.
   *
   * Static, so a test can set them without the container.
   *
   * @var array<string, \Drupal\canvas_ssr\Fetch\Message\ResponseInterface>
   */
  public static array $responses = [];

  /**
   * The last request it was given, so a test can see what was sent.
   */
  public static ?RequestInterface $lastRequest = NULL;

  /**
   * Constructs a CannedFetch.
   *
   * @param \Drupal\canvas_ssr\Fetch\FetchInterface $inner
   *   The real fetch.
   */
  public function __construct(private readonly FetchInterface $inner) {}

  /**
   * Returns a response with a status, and cacheability or none.
   *
   * @param int $status
   *   The status.
   * @param int|null $max_age
   *   Its max-age, or NULL for a response whose cacheability isn't known.
   */
  public static function response(int $status, ?int $max_age): ResponseInterface {
    $response = new Response($status, ['Content-Type' => 'application/json'], '{}');
    if ($max_age === NULL) {
      return $response->withoutCacheability();
    }
    $response->getCacheableMetadata()->setCacheMaxAge($max_age)->addCacheTags(['canned_fetch']);

    return $response;
  }

  /**
   * {@inheritdoc}
   */
  public function fetch(RequestInterface $request): ResponseInterface {
    self::$lastRequest = $request;
    $path = $request->getUri()->getPath();
    if (!isset(self::$responses[$path])) {
      return $this->inner->fetch($request);
    }

    return self::$responses[$path]->withUri(new Uri($path));
  }

}
