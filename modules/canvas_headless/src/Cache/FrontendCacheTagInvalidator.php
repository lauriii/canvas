<?php

declare(strict_types=1);

namespace Drupal\canvas_headless\Cache;

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas_headless\FrontendUrl;
use Drupal\Component\Utility\Crypt;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\DestructableInterface;
use Drupal\Core\Utility\Error;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Promise\Utils;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireServiceClosure;

/**
 * Announces invalidated cache tags to the frontends that opted in.
 *
 * Tags invalidated during a request are stored under a random ID, and after
 * the response every URL in `canvas_headless.settings:cache_invalidation_urls`
 * receives `POST {"id": "..."}`. The frontend reads the tags once from
 * CacheInvalidationController, on the Drupal site it already trusts, and
 * expires whatever it cached with them; the tags come from the `cacheTags` of
 * Canvas content API responses. The short-lived, single-use, unguessable ID is
 * the only credential, so no secret has to be shared: a forged announcement
 * makes a frontend ask Drupal, which answers 404.
 *
 * A URL whose announcement fails is remembered. Its next announcement, from
 * the next request that invalidates tags or from cron, is `http_response`:
 * the frontend expires everything, including what it missed.
 *
 * The service is destructed last, so it also announces the tags other
 * destructors invalidate, such as a deferred router rebuild.
 *
 * @see \Drupal\canvas_headless\Controller\CacheInvalidationController
 */
final class FrontendCacheTagInvalidator implements CacheTagsInvalidatorInterface, DestructableInterface {

  /**
   * The expirable key-value collection storing announced tags.
   */
  public const string COLLECTION = 'canvas_headless.cache_invalidation';

  /**
   * The key-value collection flagging URLs whose announcement failed.
   */
  private const string FAILED_COLLECTION = 'canvas_headless.cache_invalidation_failed';

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
   * @param \Closure(): \Drupal\Core\KeyValueStore\KeyValueFactoryInterface $keyValue
   *   Returns the key-value factory.
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
    #[AutowireServiceClosure('keyvalue')]
    private readonly \Closure $keyValue,
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
    // Published responses never carry the auto-save tag, and every editor
    // auto-save invalidates it: announcing it would only cost a round trip.
    $tags = array_diff($tags, [AutoSaveManager::CACHE_TAG]);
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
    $this->announce($tags);
  }

  /**
   * Announces `http_response` to the URLs whose last announcement failed.
   */
  public function retryFailed(): void {
    $this->announce(['http_response'], failed_only: TRUE);
  }

  /**
   * Stores the tags under a new ID per URL, and announces it.
   *
   * @param list<string> $tags
   *   The invalidated tags.
   * @param bool $failed_only
   *   Whether to announce only to URLs whose last announcement failed.
   */
  private function announce(array $tags, bool $failed_only = FALSE): void {
    try {
      $urls = [];
      $configured = ($this->configFactory)()->get('canvas_headless.settings')->get('cache_invalidation_urls');
      foreach (\is_array($configured) ? $configured : [] as $url) {
        $url = FrontendUrl::fromConfig((string) $url)?->baseUrl;
        if ($url !== NULL) {
          $urls[$url] = $url;
        }
      }
      if ($urls === []) {
        return;
      }
      $failed = ($this->keyValue)()->get(self::FAILED_COLLECTION);
      $missed = $failed->getMultiple(array_keys($urls));
      if ($failed_only) {
        $urls = array_intersect_key($urls, $missed);
        if ($urls === []) {
          return;
        }
      }

      $store = ($this->keyValueExpirable)()->get(self::COLLECTION);
      $client = ($this->httpClient)();
      $promises = [];
      foreach ($urls as $url) {
        // One ID per URL: each is read once.
        $id = Crypt::randomBytesBase64(32);
        // A frontend that missed an announcement expires everything.
        $store->setWithExpire($id, isset($missed[$url]) ? ['http_response'] : $tags, self::EXPIRE);
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
        if ($message === NULL) {
          if (isset($missed[$url])) {
            $failed->delete($url);
          }
          continue;
        }
        $failed->set($url, TRUE);
        ($this->loggerFactory)()->get('canvas_headless')->warning('Could not announce invalidated cache tags to @url: @message', [
          '@url' => $url,
          '@message' => $message,
        ]);
      }
    }
    catch (\Throwable $e) {
      // Later destructors, such as the router rebuild, must still run.
      Error::logException(($this->loggerFactory)()->get('canvas_headless'), $e);
    }
  }

}
