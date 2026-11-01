<?php

declare(strict_types=1);

namespace Drupal\canvas_headless\Cache;

use Drupal\canvas_headless\FrontendUrl;
use Drupal\Component\Utility\Crypt;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\DestructableInterface;
use Drupal\Core\Utility\Error;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Promise\Utils;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireServiceClosure;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Announces invalidated cache tags to the configured headless frontends.
 *
 * Tags invalidated during a request are stored under a random ID, and after
 * the response every configured frontend receives `POST {"id": "..."}` at
 * REVALIDATE_PATH. The frontend reads the tags from getTags(), on the Drupal
 * site it already trusts, and expires whatever it cached with them; the tags
 * come from the `cacheTags` of Canvas content API responses. The short-lived,
 * unguessable ID is the only credential, so no secret has to be shared: a
 * forged announcement makes a frontend ask Drupal, which answers 404.
 *
 * The service is destructed last, so it also announces the tags other
 * destructors invalidate, such as a deferred router rebuild.
 */
final class FrontendCacheTagInvalidator implements CacheTagsInvalidatorInterface, DestructableInterface {

  /**
   * The frontend path invalidations are announced to.
   */
  public const string REVALIDATE_PATH = '/api/canvas/revalidate';

  /**
   * The key-value collection storing announced tags.
   */
  private const string COLLECTION = 'canvas_headless.cache_invalidation';

  /**
   * How long frontends can read announced tags, in seconds.
   */
  private const int EXPIRE = 300;

  /**
   * The most tags announced individually.
   *
   * Beyond this, `http_response` is announced instead: every Canvas response
   * carries it, so frontends expire everything.
   */
  private const int MAX_TAGS = 1000;

  /**
   * The invalidated tags, as keys.
   *
   * @var array<string, true>
   */
  private array $tags = [];

  /**
   * Constructs a new FrontendCacheTagInvalidator.
   *
   * The dependencies are closures: every cache tag invalidator is
   * instantiated with the cache tags invalidator service, which the router
   * depends on, so eager dependencies would be wasteful and circular.
   *
   * @param \Closure(): \GuzzleHttp\ClientInterface $httpClient
   *   Returns the HTTP client.
   * @param \Closure(): \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   Returns the config factory.
   * @param \Closure(): \Drupal\Core\KeyValueStore\KeyValueExpirableFactoryInterface $keyValueExpirable
   *   Returns the expirable key-value factory.
   * @param \Closure(): \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerFactory
   *   Returns the logger factory.
   */
  public function __construct(
    #[AutowireServiceClosure('http_client')]
    private readonly \Closure $httpClient,
    #[AutowireServiceClosure('config.factory')]
    private readonly \Closure $configFactory,
    #[AutowireServiceClosure('keyvalue.expirable')]
    private readonly \Closure $keyValueExpirable,
    #[AutowireServiceClosure('logger.factory')]
    private readonly \Closure $loggerFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function invalidateTags(array $tags): void {
    // http_response already covers every tag.
    if (isset($this->tags['http_response'])) {
      return;
    }
    $this->tags += array_fill_keys($tags, TRUE);
    if (isset($this->tags['http_response']) || \count($this->tags) > self::MAX_TAGS) {
      $this->tags = ['http_response' => TRUE];
    }
  }

  /**
   * {@inheritdoc}
   */
  public function destruct(): void {
    if ($this->tags === []) {
      return;
    }
    // Numeric tags became integer keys.
    $tags = array_map(strval(...), array_keys($this->tags));
    $this->tags = [];
    try {
      $this->announce($tags);
    }
    catch (\Throwable $e) {
      // Later destructors, such as the router rebuild, must still run.
      Error::logException(($this->loggerFactory)()->get('canvas_headless'), $e);
    }
  }

  /**
   * Stores the tags under a new ID and announces it to every frontend.
   *
   * @param list<string> $tags
   *   The invalidated tags.
   */
  private function announce(array $tags): void {
    $urls = [];
    $frontends = ($this->configFactory)()->get('canvas_headless.settings')->get('frontends');
    foreach (\is_array($frontends) ? $frontends : [] as $item) {
      $frontend = FrontendUrl::fromConfig((string) ($item['url'] ?? ''));
      if ($frontend !== NULL) {
        $urls[] = $frontend->baseUrl . self::REVALIDATE_PATH;
      }
    }
    if ($urls === []) {
      return;
    }

    $id = Crypt::randomBytesBase64(32);
    ($this->keyValueExpirable)()->get(self::COLLECTION)->setWithExpire($id, $tags, self::EXPIRE);
    $client = ($this->httpClient)();
    $promises = [];
    foreach (array_unique($urls) as $url) {
      $promises[$url] = $client->requestAsync('POST', $url, [
        'json' => ['id' => $id],
        // A redirect is not followed: only configured URLs are contacted.
        'allow_redirects' => FALSE,
        'connect_timeout' => 2,
        'timeout' => 5,
      ]);
    }
    foreach (Utils::settle($promises)->wait() as $url => $result) {
      $reason = $result['state'] === 'rejected' ? $result['reason'] : $result['value'];
      // Not the message of an error response: it quotes the body.
      $message = match (TRUE) {
        // Redirects, which are not followed, and 1xx.
        $reason instanceof ResponseInterface => $reason->getStatusCode() >= 200 && $reason->getStatusCode() < 300 ? NULL : 'HTTP ' . $reason->getStatusCode(),
        $reason instanceof RequestException && $reason->hasResponse() => 'HTTP ' . $reason->getResponse()?->getStatusCode(),
        $reason instanceof \Throwable => $reason->getMessage(),
        default => 'unknown error',
      };
      if ($message !== NULL) {
        ($this->loggerFactory)()->get('canvas_headless')->warning('Could not announce invalidated cache tags to @url: @message', [
          '@url' => $url,
          '@message' => $message,
        ]);
      }
    }
  }

  /**
   * Returns the tags announced under an ID.
   */
  public function getTags(string $id): JsonResponse {
    $tags = ($this->keyValueExpirable)()->get(self::COLLECTION)->get($id);
    if (!\is_array($tags)) {
      throw new NotFoundHttpException();
    }
    return new JsonResponse(['tags' => $tags], headers: ['Cache-Control' => 'no-store']);
  }

}
