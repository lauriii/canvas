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
 * Announces invalidated cache tags to the frontends.
 *
 * Nothing is configured. Tags invalidated during a request are stored under a
 * random ID, and after the response every frontend in
 * `canvas_headless.settings:frontends` receives `POST {"id": "..."}` at
 * self::ROUTE_PATH. The frontend reads the tags once from
 * CacheInvalidationController, on the Drupal site it already trusts, and
 * expires whatever it cached with them; the tags come from the `cacheTags` of
 * Canvas content API responses. The short-lived, single-use, unguessable ID is
 * the only credential, so no secret has to be shared: a forged announcement
 * makes a frontend ask Drupal, which answers 404.
 *
 * An announcement that fails, or that the frontend does not read within
 * self::READ_TIMEOUT seconds, is remembered as a miss. Until the frontend has
 * read an announcement made after the miss, every announcement to it, from a
 * request that invalidates tags or from cron, is `http_response`: the frontend
 * expires everything, including what it missed. Announcements back off while a
 * frontend keeps failing.
 *
 * A frontend is connected once it has read an announcement. One that never
 * did, for example because it has no such route, is neither reported nor
 * logged. If it answers at all, it is announced to again without a wait: it
 * connects on the first change after its route exists.
 *
 * The service is destructed last, so it also announces the tags other
 * destructors invalidate, such as a deferred router rebuild.
 *
 * @phpstan-type FrontendState array{url?: string, missed_at: float|null, pending: array<string, float>, failures: int, last_error: string|null, last_failure: int|null, last_success: int|null, retry_after: int}
 *
 * @see \Drupal\canvas_headless\Controller\CacheInvalidationController
 */
final class FrontendCacheTagInvalidator implements CacheTagsInvalidatorInterface, DestructableInterface {

  /**
   * The expirable key-value collection storing announced tags.
   */
  public const string COLLECTION = 'canvas_headless.cache_invalidation';

  /**
   * The key-value collection storing each frontend's state.
   *
   * Keyed by self::stateKey(): a base URL can be longer than a key.
   */
  public const string STATE_COLLECTION = 'canvas_headless.cache_invalidation_state';

  /**
   * The path, below a frontend's base URL, that receives announcements.
   */
  public const string ROUTE_PATH = '/api/canvas/revalidate';

  /**
   * The tag announced by a test: no response carries it.
   */
  public const string TEST_TAG = 'canvas_headless:test';

  /**
   * How long frontends can read announced tags, in seconds.
   */
  private const int EXPIRE = 300;

  /**
   * After how many seconds unread announced tags count as missed.
   */
  private const int READ_TIMEOUT = 30;

  /**
   * How long a test waits for the frontend to read the tags, in seconds.
   */
  private const int TEST_TIMEOUT = 5;

  /**
   * The most tags announced individually.
   *
   * Beyond this, `http_response` is announced instead: every Canvas response
   * carries it, so frontends expire everything.
   */
  private const int MAX_TAGS = 1000;

  /**
   * The error of a frontend that accepted an announcement without reading it.
   */
  private const string NOT_READ = 'The frontend did not read the announced cache tags.';

  /**
   * The state of a frontend, without its `url`.
   *
   * A frontend without a stored state may have missed everything: its first
   * announcement is `http_response`.
   *
   * - missed_at: the microtime since which an invalidation may not have
   *   reached the frontend, or NULL.
   * - pending: the announce microtime of the IDs not read yet, by ID.
   * - failures: the number of consecutive failures.
   * - last_error: the last failure's error.
   * - last_failure: the Unix time of the last failure.
   * - last_success: the Unix time the frontend last read announced tags.
   * - retry_after: the Unix time before which nothing is announced, or 0.
   */
  private const array DEFAULT_STATE = [
    'missed_at' => NULL,
    'pending' => [],
    'failures' => 0,
    'last_error' => NULL,
    'last_failure' => NULL,
    'last_success' => NULL,
    'retry_after' => 0,
  ];

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
   * @param \Closure(): \Drupal\Core\Lock\LockBackendInterface $lock
   *   Returns the lock backend.
   * @param \Closure(): \Drupal\Component\Datetime\TimeInterface $time
   *   Returns the time service.
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
    #[AutowireServiceClosure('lock')]
    private readonly \Closure $lock,
    #[AutowireServiceClosure('datetime.time')]
    private readonly \Closure $time,
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
    $tags = \array_map(strval(...), \array_keys($this->tags));
    $this->tags = [];
    $this->announce($tags);
  }

  /**
   * Announces `http_response` to the frontends that missed an announcement.
   */
  public function retryFailed(): void {
    $this->announce(['http_response'], missed_only: TRUE);
  }

  /**
   * Returns and forgets the tags announced under an ID.
   *
   * @param string $id
   *   The announced ID.
   *
   * @return list<string>|null
   *   The tags, or NULL if the ID is unknown, expired, or was read before.
   */
  public function redeem(string $id): ?array {
    $store = ($this->keyValueExpirable)()->get(self::COLLECTION);
    $entry = $store->get($id);
    if (!\is_array($entry) || !\is_array($entry['tags'] ?? NULL) || !\is_string($entry['url'] ?? NULL)) {
      return NULL;
    }
    // A frontend whose state was forgotten gets none for reading old tags.
    return $this->update($entry['url'], create: FALSE, callback: function (array &$state) use ($store, $id): ?array {
      // Read again, under the lock: a replayed ID finds nothing.
      $entry = $store->get($id);
      if (!\is_array($entry)) {
        return NULL;
      }
      $store->delete($id);
      unset($state['pending'][$id]);
      $state['last_success'] = ($this->time)()->getCurrentTime();
      $state['failures'] = 0;
      $state['last_error'] = NULL;
      $state['retry_after'] = 0;
      // An earlier announcement does not cover what was missed after it.
      if ($entry['tags'] === ['http_response'] && $state['missed_at'] !== NULL && ($entry['time'] ?? 0.0) >= $state['missed_at']) {
        $state['missed_at'] = NULL;
      }
      return $entry['tags'];
    });
  }

  /**
   * Returns the status of the connected frontends.
   *
   * @return array<string, array{state: 'ok'|'failing', lastSuccess: int, lastFailure: int|null, lastError: string|null}>
   *   The status by base URL, of the frontends that ever read announced tags.
   *   The state is `failing` after a failure, until the frontend read an
   *   `http_response` announcement made after it.
   */
  public function getStatus(): array {
    $urls = $this->frontends();
    if ($urls === []) {
      return [];
    }
    $states = ($this->keyValue)()->get(self::STATE_COLLECTION)->getMultiple(\array_map(self::stateKey(...), array_values($urls)));
    $now = ($this->time)()->getCurrentMicroTime();
    $status = [];
    foreach ($urls as $url) {
      $stored = $states[self::stateKey($url)] ?? NULL;
      /** @phpstan-var FrontendState $state */
      $state = (\is_array($stored) ? $stored : []) + self::DEFAULT_STATE;
      if ($state['last_success'] === NULL) {
        continue;
      }
      // Not recorded yet: that waits for the next announcement.
      $unread = array_filter($state['pending'], static fn (float $announced): bool => $announced < $now - self::READ_TIMEOUT);
      $status[$url] = [
        'state' => $state['failures'] > 0 || $state['missed_at'] !== NULL || $unread !== [] ? 'failing' : 'ok',
        'lastSuccess' => $state['last_success'],
        'lastFailure' => $unread === [] ? $state['last_failure'] : (int) max($unread) + self::READ_TIMEOUT,
        'lastError' => $unread === [] ? $state['last_error'] : self::NOT_READ,
      ];
    }
    return $status;
  }

  /**
   * Announces to one frontend now, and waits for it to read the tags.
   *
   * Ignores the wait after a failure. The announced tag is self::TEST_TAG,
   * which expires nothing; a frontend that missed an announcement is
   * announced `http_response`.
   *
   * @param string $baseUrl
   *   The canonical base URL of a configured frontend.
   * @param bool $expire_all
   *   Whether to announce `http_response`, so the frontend expires everything.
   *
   * @return array{ok: bool, announced: bool, read: bool, message: string}
   *   Whether the frontend accepted the announcement and read the tags, and a
   *   message describing the outcome.
   *
   * @phpstan-impure
   */
  public function test(string $baseUrl, bool $expire_all = FALSE): array {
    $id = $this->prepare($baseUrl, [$expire_all ? 'http_response' : self::TEST_TAG], ignore_backoff: TRUE);
    \assert(\is_string($id));
    $error = $this->send([$baseUrl => $id])[$baseUrl];
    if ($error !== NULL) {
      return [
        'ok' => FALSE,
        'announced' => FALSE,
        'read' => FALSE,
        'message' => \is_int($error) ? "The frontend answered HTTP $error." : "The frontend could not be reached: $error",
      ];
    }
    // A frontend that defers the read answers before it reads the tags.
    // Only reading them removes the announced entry.
    $time = ($this->time)();
    $store = ($this->keyValueExpirable)()->get(self::COLLECTION);
    $deadline = $time->getCurrentMicroTime() + self::TEST_TIMEOUT;
    while ($store->get($id) !== NULL) {
      if ($time->getCurrentMicroTime() >= $deadline) {
        // The outcome is known now: do not leave it to a later announcement.
        $unread = $this->update($baseUrl, function (array &$state) use ($id, $store, $time): bool {
          // Read since the last check.
          if ($store->get($id) === NULL) {
            return FALSE;
          }
          unset($state['pending'][$id]);
          self::fail($state, self::NOT_READ, $time->getCurrentMicroTime());
          $this->logFailure($state, self::NOT_READ);
          return TRUE;
        });
        if (!$unread) {
          break;
        }
        $store->delete($id);
        return [
          'ok' => FALSE,
          'announced' => TRUE,
          'read' => FALSE,
          'message' => 'The frontend accepted the announcement but did not read the cache tags from Drupal. Check that CANVAS_SITE_URL on the frontend points at this site.',
        ];
      }
      usleep(100000);
    }
    return [
      'ok' => TRUE,
      'announced' => TRUE,
      'read' => TRUE,
      'message' => 'The frontend received the announcement and read the cache tags.',
    ];
  }

  /**
   * Forgets the state of the frontends that were removed or added.
   *
   * A frontend that is added (again) starts without a state, so that its
   * first announcement expires everything.
   *
   * @param mixed $previous
   *   The `frontends` of canvas_headless.settings before they changed, if
   *   known.
   */
  public function deleteUnusedStates(mixed $previous = NULL): void {
    $states = ($this->keyValue)()->get(self::STATE_COLLECTION);
    $frontends = $this->frontends();
    if ($previous !== NULL) {
      $frontends = array_intersect_key($frontends, self::baseUrls($previous));
    }
    $unused = array_filter($states->getAll(), static fn (mixed $state): bool => !\is_array($state) || !isset($frontends[$state['url'] ?? '']));
    $states->deleteMultiple(\array_keys($unused));
  }

  /**
   * Returns the key a frontend's state is stored under.
   */
  private static function stateKey(string $baseUrl): string {
    return Crypt::hashBase64($baseUrl);
  }

  /**
   * Returns the frontends.
   *
   * @return array<string, string>
   *   The canonical base URLs, keyed by themselves.
   */
  private function frontends(): array {
    return self::baseUrls(($this->configFactory)()->get('canvas_headless.settings')->get('frontends'));
  }

  /**
   * Returns the canonical base URLs of a frontend list.
   *
   * @param mixed $frontends
   *   The `frontends` of canvas_headless.settings.
   *
   * @return array<string, string>
   *   The canonical base URLs, keyed by themselves.
   */
  private static function baseUrls(mixed $frontends): array {
    $urls = [];
    foreach (\is_array($frontends) ? $frontends : [] as $frontend) {
      $url = \is_array($frontend) ? FrontendUrl::fromConfig((string) ($frontend['url'] ?? ''))?->baseUrl : NULL;
      if ($url !== NULL) {
        $urls[$url] = $url;
      }
    }
    return $urls;
  }

  /**
   * Announces the tags to the frontends.
   *
   * @param list<string> $tags
   *   The invalidated tags.
   * @param bool $missed_only
   *   Whether to announce only to frontends that missed an announcement.
   */
  private function announce(array $tags, bool $missed_only = FALSE): void {
    try {
      $ids = [];
      foreach ($this->frontends() as $url) {
        try {
          $id = $this->prepare($url, $tags, $missed_only);
          if ($id !== NULL) {
            $ids[$url] = $id;
          }
        }
        catch (\Throwable $e) {
          // The other frontends are still announced to.
          Error::logException(($this->loggerFactory)()->get('canvas_headless'), $e);
        }
      }
      if ($ids !== []) {
        $this->send($ids);
      }
    }
    catch (\Throwable $e) {
      // Later destructors, such as the router rebuild, must still run.
      Error::logException(($this->loggerFactory)()->get('canvas_headless'), $e);
    }
  }

  /**
   * Stores the tags to announce to a frontend under a new ID.
   *
   * @param string $url
   *   The frontend's canonical base URL.
   * @param list<string> $tags
   *   The invalidated tags.
   * @param bool $missed_only
   *   Whether to announce only if the frontend missed an announcement.
   * @param bool $ignore_backoff
   *   Whether to announce even while announcements back off.
   *
   * @return string|null
   *   The ID to announce, or NULL if there is nothing to announce now.
   */
  private function prepare(string $url, array $tags, bool $missed_only = FALSE, bool $ignore_backoff = FALSE): ?string {
    return $this->update($url, function (array &$state) use ($url, $tags, $missed_only, $ignore_backoff): ?string {
      $time = ($this->time)();
      $now = $time->getCurrentMicroTime();
      $unread = array_filter($state['pending'], static fn (float $announced): bool => $announced < $now - self::READ_TIMEOUT);
      if ($unread !== []) {
        $state['pending'] = array_diff_key($state['pending'], $unread);
        // The wait starts when the read timed out, not when that is noticed:
        // a miss noticed after the wait is repaired by this announcement.
        self::fail($state, self::NOT_READ, $now, max($unread) + self::READ_TIMEOUT);
        $this->logFailure($state, self::NOT_READ);
      }
      if ($missed_only && $state['missed_at'] === NULL) {
        return NULL;
      }
      if (!$ignore_backoff && (int) $now < $state['retry_after']) {
        // These tags are skipped.
        $state['missed_at'] = $now;
        return NULL;
      }
      // One ID per frontend: each is read once.
      $id = Crypt::randomBytesBase64(32);
      ($this->keyValueExpirable)()->get(self::COLLECTION)->setWithExpire($id, [
        // A frontend that missed an announcement expires everything.
        'tags' => $state['missed_at'] === NULL ? $tags : ['http_response'],
        'url' => $url,
        'time' => $now,
      ], self::EXPIRE);
      // Before the request: a frontend may read the tags while answering it.
      $state['pending'][$id] = $now;
      return $id;
    });
  }

  /**
   * Announces IDs to their frontends, and records the failures.
   *
   * @param array<string, string> $ids
   *   The IDs to announce, by canonical frontend base URL.
   *
   * @return array<string, int|string|null>
   *   By frontend base URL: NULL if the frontend accepted the announcement,
   *   the HTTP status code it answered otherwise, or an error message.
   */
  private function send(array $ids): array {
    $client = ($this->httpClient)();
    $promises = [];
    foreach ($ids as $url => $id) {
      $promises[$url] = $client->requestAsync('POST', $url . self::ROUTE_PATH, [
        'json' => ['id' => $id],
        // A redirect is not followed: only configured frontends are contacted.
        'allow_redirects' => FALSE,
        'connect_timeout' => 2,
        'timeout' => 5,
      ]);
    }
    $errors = [];
    foreach (Utils::settle($promises)->wait() as $url => $result) {
      $reason = $result['state'] === 'rejected' ? $result['reason'] : $result['value'];
      // Not the message of an error response: it quotes the body.
      $error = match (TRUE) {
        // Redirects, which are not followed, and 1xx.
        $reason instanceof ResponseInterface => $reason->getStatusCode() >= 200 && $reason->getStatusCode() < 300 ? NULL : $reason->getStatusCode(),
        $reason instanceof RequestException && $reason->getResponse() !== NULL => $reason->getResponse()->getStatusCode(),
        // Without the link to the cURL documentation and the URL, which the
        // administrator already sees.
        $reason instanceof \Throwable => preg_replace('~ \(see https?://curl\.se/[^)]*\)| for \S+$~', '', $reason->getMessage()) ?? $reason->getMessage(),
        default => 'unknown error',
      };
      $errors[$url] = $error;
      // Success is recorded when the frontend reads the tags.
      if ($error === NULL) {
        continue;
      }
      $id = $ids[$url];
      $message = \is_int($error) ? "HTTP $error" : $error;
      $this->update($url, function (array &$state) use ($id, $message, $error): void {
        unset($state['pending'][$id]);
        self::fail($state, $message, ($this->time)()->getCurrentMicroTime());
        $this->logFailure($state, $message);
        // A frontend that never read an announcement, but answers, may only
        // lack the route: that is no failure. Asking again is cheap, and
        // connects it on the first change after the route exists.
        if ($state['last_success'] === NULL && \is_int($error)) {
          $state['failures'] = 0;
          $state['retry_after'] = 0;
        }
      });
      ($this->keyValueExpirable)()->get(self::COLLECTION)->delete($id);
    }
    return $errors;
  }

  /**
   * Logs a failure of a connected frontend.
   *
   * A frontend that never read an announcement is not failing: it may have
   * no such route.
   *
   * @param array $state
   *   The frontend's state.
   * @param string $error
   *   The error.
   *
   * @phpstan-param FrontendState $state
   */
  private function logFailure(array $state, string $error): void {
    if ($state['last_success'] !== NULL) {
      ($this->loggerFactory)()->get('canvas_headless')->warning('Could not announce invalidated cache tags to @url: @message', [
        '@url' => ($state['url'] ?? '') . self::ROUTE_PATH,
        '@message' => $error,
      ]);
    }
  }

  /**
   * Records a failure in a frontend's state, and backs off.
   *
   * @param array $state
   *   The frontend's state.
   * @param string $error
   *   The error.
   * @param float $now
   *   The current microtime: an invalidation may not have reached the
   *   frontend since then.
   * @param float|null $since
   *   The microtime the wait before the next announcement starts at, if not
   *   now.
   *
   * @phpstan-param FrontendState $state
   */
  private static function fail(array &$state, string $error, float $now, ?float $since = NULL): void {
    $state['missed_at'] = $now;
    $state['failures']++;
    $state['last_failure'] = (int) $now;
    $state['last_error'] = $error;
    // 10, 20, 40, … seconds, at most five minutes.
    $state['retry_after'] = (int) ($since ?? $now) + (int) min(300, 10 * 2 ** min($state['failures'] - 1, 5));
  }

  /**
   * Changes a frontend's state under a lock.
   *
   * The lock is never held across an HTTP request.
   *
   * @param string $url
   *   The frontend's canonical base URL.
   * @param callable $callback
   *   Receives the state by reference, and changes it.
   * @param bool $create
   *   Whether to store a state for a frontend that has none.
   *
   * @return mixed
   *   What the callback returns.
   *
   * @phpstan-param callable(FrontendState &$state): mixed $callback
   */
  private function update(string $url, callable $callback, bool $create = TRUE): mixed {
    $lock = ($this->lock)();
    $key = self::stateKey($url);
    $name = 'canvas_headless.cache_invalidation:' . $key;
    if (!$lock->acquire($name)) {
      $lock->wait($name, 1);
      // A lock held this long is stale: losing an update is better than
      // losing an announcement.
      $lock->acquire($name);
    }
    try {
      $states = ($this->keyValue)()->get(self::STATE_COLLECTION);
      $stored = $states->get($key);
      $stored = \is_array($stored) ? $stored + self::DEFAULT_STATE : NULL;
      // Without a state, the frontend may have missed everything until now.
      /** @phpstan-var FrontendState $state */
      $state = $stored ?? ['url' => $url, 'missed_at' => ($this->time)()->getCurrentMicroTime()] + self::DEFAULT_STATE;
      $result = $callback($state);
      if ($state !== $stored && ($create || $stored !== NULL)) {
        $states->set($key, $state);
      }
      return $result;
    }
    finally {
      $lock->release($name);
    }
  }

}
