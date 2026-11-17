<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_ssr\Kernel\Fetch;

use Drupal\canvas_ssr\Fetch\FetchInterface;
use Drupal\canvas_ssr\Fetch\Message\Response;
use Drupal\canvas_ssr\Fetch\Message\ResponseInterface;
use GuzzleHttp\Psr7\Uri;
use Psr\Http\Message\RequestInterface;

/**
 * A fetch that renders an island while it answers one path.
 *
 * It stands in for a subrequest whose page has islands on it, as Drupal's
 * error page can, without building a whole page. Every other request goes
 * to the real fetch.
 *
 * @see \Drupal\Tests\canvas_ssr\Kernel\Fetch\NestedRenderTest
 */
final class NestingFetch implements FetchInterface {

  /**
   * The path it answers by rendering an island.
   */
  public const string PATH = '/jsonapi/nested';

  /**
   * The cache tag its response carries, besides the island's.
   */
  public const string TAG = 'nested_fetch';

  /**
   * Renders the island, returning its HTML and cacheability.
   *
   * Static, so a test can set it without the container.
   *
   * @var (\Closure(): array{string, \Drupal\Core\Cache\CacheableMetadata})|null
   */
  public static ?\Closure $render = NULL;

  /**
   * The HTML of each island it rendered, in order.
   *
   * @var list<string>
   */
  public static array $rendered = [];

  /**
   * Constructs a NestingFetch.
   *
   * @param \Drupal\canvas_ssr\Fetch\FetchInterface $inner
   *   The real fetch.
   */
  public function __construct(private readonly FetchInterface $inner) {}

  /**
   * {@inheritdoc}
   */
  public function fetch(RequestInterface $request): ResponseInterface {
    if ($request->getUri()->getPath() !== self::PATH || self::$render === NULL) {
      return $this->inner->fetch($request);
    }
    [$html, $cacheability] = (self::$render)();
    self::$rendered[] = $html;
    $response = (new Response(200, ['Content-Type' => 'text/html'], $html))
      ->withUri(new Uri(self::PATH));
    $response->addCacheableDependency($cacheability->addCacheTags([self::TAG]));

    return $response;
  }

}
