<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_headless\Unit;

use Drupal\canvas_headless\Cache\FrontendCacheTagInvalidator;
use Drupal\canvas_headless\FrontendUrl;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Utility\Crypt;
use Drupal\Core\KeyValueStore\KeyValueExpirableFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueStoreExpirableInterface;
use Drupal\Core\KeyValueStore\MemoryStorage;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Tests\UnitTestCase;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Psr\Http\Message\RequestInterface;
use Psr\Log\LogLevel;

/**
 * Tests announcing invalidated cache tags to headless frontends.
 */
#[CoversClass(FrontendCacheTagInvalidator::class)]
#[Group('canvas_headless')]
final class FrontendCacheTagInvalidatorTest extends UnitTestCase {

  /**
   * The frontend most tests announce to.
   */
  private const string FRONTEND = 'https://www.example.com';

  /**
   * The current time.
   */
  private float $now = 1000.0;

  /**
   * By how many seconds reading the current microtime advances the time.
   */
  private float $tick = 0.0;

  /**
   * The announced tags, URL and time, by ID; expiry is not covered.
   *
   * @var array<string, array<string, mixed>>
   */
  private array $values = [];

  /**
   * The state of the frontends, by base URL.
   */
  private MemoryStorage $states;

  /**
   * Whether the frontends have a state: nothing was missed before the test.
   */
  private bool $known = TRUE;

  /**
   * Runs before every lock is acquired, to interleave another request.
   *
   * @var (\Closure(): void)|null
   */
  private ?\Closure $beforeLock = NULL;

  /**
   * The state key whose writes fail.
   */
  private ?string $brokenKey = NULL;

  /**
   * The requests sent to the frontends, and their options.
   *
   * @var list<array{request: \Psr\Http\Message\RequestInterface, options: array<string, mixed>}>
   */
  private array $history = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->states = new MemoryStorage('state');
  }

  /**
   * Tests that tags are announced once per request to every enabled frontend.
   */
  public function testAnnouncesCollectedTags(): void {
    $warnings = [];
    $logger = $this->createMock(LoggerChannelInterface::class);
    $logger->method('warning')->willReturnCallback(function (string $message, array $context) use (&$warnings): void {
      $warnings[] = $context;
    });

    $invalidator = $this->invalidator(
      [
        // The canonical base URL is announced to.
        ['url' => 'HTTPS://WWW.EXAMPLE.COM:443', 'components' => [], 'cache_invalidation' => TRUE],
        ['url' => 'https://down.example/app', 'components' => [], 'cache_invalidation' => TRUE],
        ['url' => 'https://broken.example', 'components' => [], 'cache_invalidation' => TRUE],
        ['url' => 'https://moved.example', 'components' => [], 'cache_invalidation' => TRUE],
        ['url' => 'javascript:alert(1)', 'components' => [], 'cache_invalidation' => TRUE],
        ['url' => 'https://disabled.example', 'components' => [], 'cache_invalidation' => FALSE],
        // An entry stored before the switch existed.
        ['url' => 'https://legacy.example', 'components' => []],
      ],
      [
        new Response(204),
        new ConnectException('Connection refused', new Request('POST', 'https://down.example/app/api/canvas/revalidate')),
        new Response(500, [], 'A body that is not logged'),
        new Response(301, ['Location' => 'https://elsewhere.example/']),
      ],
      $logger,
    );
    $invalidator->invalidateTags(['node:1', 'node_list']);
    $invalidator->invalidateTags(['node:1', 'config:system.site', '123']);
    $invalidator->destruct();
    // Nothing is left to announce.
    $invalidator->destruct();

    self::assertCount(4, $this->history);
    $ids = [];
    foreach ($this->history as $index => $transaction) {
      $request = $transaction['request'];
      self::assertSame('POST', $request->getMethod());
      self::assertSame([self::FRONTEND, 'https://down.example/app', 'https://broken.example', 'https://moved.example'][$index] . '/api/canvas/revalidate', (string) $request->getUri());
      self::assertFalse($request->hasHeader('Authorization'));
      self::assertFalse($transaction['options']['allow_redirects']);
      $ids[] = json_decode((string) $request->getBody(), TRUE, flags: JSON_THROW_ON_ERROR)['id'];
    }
    // One ID per frontend; numeric tags stay strings. The tags of a failed
    // announcement are forgotten.
    self::assertCount(4, array_unique($ids));
    self::assertMatchesRegularExpression('/^[\w-]{43}$/', $ids[0]);
    self::assertSame([
      $ids[0] => ['tags' => ['node:1', 'node_list', 'config:system.site', '123'], 'url' => self::FRONTEND, 'time' => 1000.0],
    ], $this->values);
    // In completion order.
    self::assertEqualsCanonicalizing([
      ['@url' => 'https://down.example/app/api/canvas/revalidate', '@message' => 'Connection refused'],
      ['@url' => 'https://broken.example/api/canvas/revalidate', '@message' => 'HTTP 500'],
      ['@url' => 'https://moved.example/api/canvas/revalidate', '@message' => 'HTTP 301'],
    ], $warnings);

    // An accepted announcement is pending until it is read; a failed one is
    // remembered as a miss.
    $failed = [
      'missed_at' => 1000.0,
      'pending' => [],
      'failures' => 1,
      'last_error' => 'HTTP 500',
      'last_failure' => 1000,
      'last_success' => NULL,
      'retry_after' => 1010,
    ];
    self::assertEquals([
      self::FRONTEND => [
        'missed_at' => NULL,
        'pending' => [$ids[0] => 1000.0],
        'failures' => 0,
        'last_error' => NULL,
        'last_failure' => NULL,
        'last_success' => NULL,
        'retry_after' => 0,
      ],
      'https://down.example/app' => ['last_error' => 'Connection refused'] + $failed,
      'https://broken.example' => $failed,
      'https://moved.example' => ['last_error' => 'HTTP 301'] + $failed,
    ], $this->allStates());
    self::assertSame([
      self::FRONTEND => ['state' => 'idle', 'lastSuccess' => NULL, 'lastFailure' => NULL, 'lastError' => NULL, 'failures' => 0],
      'https://down.example/app' => ['state' => 'failing', 'lastSuccess' => NULL, 'lastFailure' => 1000, 'lastError' => 'Connection refused', 'failures' => 1],
      'https://broken.example' => ['state' => 'failing', 'lastSuccess' => NULL, 'lastFailure' => 1000, 'lastError' => 'HTTP 500', 'failures' => 1],
      'https://moved.example' => ['state' => 'failing', 'lastSuccess' => NULL, 'lastFailure' => 1000, 'lastError' => 'HTTP 301', 'failures' => 1],
    ], $invalidator->getStatus());
  }

  /**
   * Tests that announced tags are read once, which records the success.
   */
  public function testRedeem(): void {
    $invalidator = $this->invalidator([self::frontend()], [new Response(204)]);
    $invalidator->invalidateTags(['node:1']);
    $invalidator->destruct();
    $id = $this->announcedId(0);

    $this->now = 1002.0;
    self::assertNull($invalidator->redeem('unknown'));
    self::assertSame(['node:1'], $invalidator->redeem($id));
    // Single use.
    self::assertNull($invalidator->redeem($id));
    self::assertSame([], $this->values);
    self::assertSame([
      self::FRONTEND => ['state' => 'ok', 'lastSuccess' => 1002, 'lastFailure' => NULL, 'lastError' => NULL, 'failures' => 0],
    ], $invalidator->getStatus());
    self::assertSame([], $this->state()['pending']);
  }

  /**
   * Tests that a frontend that missed an announcement expires everything.
   */
  public function testFailureThenHttpResponse(): void {
    $invalidator = $this->invalidator([self::frontend()], [
      new Response(500),
      new Response(204),
      new Response(204),
    ]);

    // Nothing was missed yet: cron announces nothing.
    $invalidator->retryFailed();
    self::assertSame([], $this->history);

    $invalidator->invalidateTags(['node:1']);
    $invalidator->destruct();
    self::assertSame('failing', $invalidator->getStatus()[self::FRONTEND]['state']);
    self::assertSame(1010, $this->state()['retry_after']);

    // Announcements back off: nothing is sent, and these tags are missed too.
    $this->now = 1005.0;
    $invalidator->invalidateTags(['node:2']);
    $invalidator->destruct();
    $invalidator->retryFailed();
    self::assertCount(1, $this->history);
    self::assertSame(1005.0, $this->state()['missed_at']);
    self::assertSame(1, $this->state()['failures']);

    // The next announcement, here from cron, expires everything.
    $this->now = 1010.0;
    $invalidator->retryFailed();
    self::assertCount(2, $this->history);
    self::assertSame(['http_response'], $invalidator->redeem($this->announcedId(1)));
    self::assertSame([
      self::FRONTEND => ['state' => 'ok', 'lastSuccess' => 1010, 'lastFailure' => 1000, 'lastError' => NULL, 'failures' => 0],
    ], $invalidator->getStatus());
    self::assertNull($this->state()['missed_at']);
    self::assertSame(0, $this->state()['retry_after']);

    // Then announcements carry the invalidated tags again.
    $invalidator->retryFailed();
    $invalidator->invalidateTags(['node:3']);
    $invalidator->destruct();
    self::assertCount(3, $this->history);
    self::assertSame(['node:3'], $invalidator->redeem($this->announcedId(2)));
  }

  /**
   * Tests that the delay between announcements doubles, up to five minutes.
   */
  public function testBackoff(): void {
    $invalidator = $this->invalidator([self::frontend()], \array_map(static fn (): Response => new Response(503), range(1, 7)));
    $delays = [];
    foreach (range(1, 7) as $failures) {
      $invalidator->invalidateTags(['node:1']);
      $invalidator->destruct();
      $state = $this->state();
      self::assertSame($failures, $state['failures']);
      $delays[] = $state['retry_after'] - (int) $this->now;
      $this->now = (float) $state['retry_after'];
    }
    self::assertSame([10, 20, 40, 80, 160, 300, 300], $delays);
    self::assertCount(7, $this->history);
  }

  /**
   * Tests that announced tags that are not read count as missed.
   */
  public function testUnreadAnnouncementIsMissed(): void {
    $invalidator = $this->invalidator([self::frontend()], [new Response(204), new Response(204), new Response(204)]);
    $invalidator->invalidateTags(['node:1']);
    $invalidator->destruct();
    $unread = $this->announcedId(0);

    // The frontend can still read the tags.
    $this->now = 1030.0;
    $invalidator->retryFailed();
    self::assertSame([$unread => 1000.0], $this->state()['pending']);
    self::assertSame('idle', $invalidator->getStatus()[self::FRONTEND]['state']);

    // It did not: that is a failure, so announcements back off.
    $this->now = 1031.0;
    $invalidator->invalidateTags(['node:2']);
    $invalidator->destruct();
    self::assertCount(1, $this->history);
    self::assertSame([
      'missed_at' => 1031.0,
      'pending' => [],
      'failures' => 1,
      'last_error' => 'The frontend did not read the announced cache tags.',
      'last_failure' => 1031,
      'last_success' => NULL,
      // The wait started when the read timed out.
      'retry_after' => 1040,
    ], $this->state());

    $this->now = 1040.0;
    $invalidator->retryFailed();
    self::assertCount(2, $this->history);
    self::assertSame(['http_response'], $this->values[$this->announcedId(1)]['tags']);

    // That is not read either. Noticed after the wait, the miss is repaired
    // by the announcement that notices it.
    $this->now = 1200.0;
    $invalidator->invalidateTags(['node:3']);
    $invalidator->destruct();
    self::assertCount(3, $this->history);
    self::assertSame(2, $this->state()['failures']);
    self::assertSame(['http_response'], $invalidator->redeem($this->announcedId(2)));
    self::assertSame('ok', $invalidator->getStatus()[self::FRONTEND]['state']);
  }

  /**
   * Tests that a frontend without a state expires everything first.
   */
  public function testFrontendWithoutState(): void {
    $this->known = FALSE;
    $invalidator = $this->invalidator([self::frontend()], [new Response(204), new Response(204), new Response(204)]);
    self::assertSame('idle', $invalidator->getStatus()[self::FRONTEND]['state']);

    // What changed before, for example while cache invalidation was disabled,
    // was not announced. Cron repairs that.
    $invalidator->retryFailed();
    self::assertCount(1, $this->history);
    // That is no failure.
    self::assertSame('idle', $invalidator->getStatus()[self::FRONTEND]['state']);
    self::assertSame(['http_response'], $invalidator->redeem($this->announcedId(0)));
    self::assertSame('ok', $invalidator->getStatus()[self::FRONTEND]['state']);

    // Forgetting the state starts over.
    $invalidator->forget(self::FRONTEND);
    $invalidator->invalidateTags(['node:1']);
    $invalidator->destruct();
    self::assertSame(['http_response'], $invalidator->redeem($this->announcedId(1)));
    // Reading tags creates no state.
    $invalidator->invalidateTags(['node:2']);
    $invalidator->destruct();
    $invalidator->forget(self::FRONTEND);
    self::assertSame(['node:2'], $invalidator->redeem((string) array_key_last($this->values)));
    self::assertSame([], $this->states->getAll());
  }

  /**
   * Tests that reading an earlier announcement does not clear a later miss.
   */
  public function testEarlierHttpResponseDoesNotClearLaterMiss(): void {
    $invalidator = $this->invalidator([self::frontend()], [
      new Response(500),
      new Response(204),
      new Response(500),
      new Response(204),
    ]);
    $invalidator->invalidateTags(['node:1']);
    $invalidator->destruct();

    // Announces http_response, which the frontend has not read yet.
    $this->now = 1010.0;
    $invalidator->invalidateTags(['node:2']);
    $invalidator->destruct();
    $earlier = $this->announcedId(1);
    self::assertSame(['http_response'], $this->values[$earlier]['tags']);

    // Meanwhile, another announcement is missed.
    $this->now = 1011.0;
    $invalidator->invalidateTags(['node:3']);
    $invalidator->destruct();
    self::assertSame(2, $this->state()['failures']);

    $this->now = 1012.0;
    self::assertSame(['http_response'], $invalidator->redeem($earlier));
    self::assertSame(1011.0, $this->state()['missed_at']);
    self::assertSame([
      self::FRONTEND => ['state' => 'failing', 'lastSuccess' => 1012, 'lastFailure' => 1011, 'lastError' => NULL, 'failures' => 0],
    ], $invalidator->getStatus());

    // Reading resets the backoff. An announcement made after the miss clears
    // it.
    $invalidator->invalidateTags(['node:4']);
    $invalidator->destruct();
    self::assertCount(4, $this->history);
    self::assertSame(['http_response'], $invalidator->redeem($this->announcedId(3)));
    self::assertSame('ok', $invalidator->getStatus()[self::FRONTEND]['state']);
  }

  /**
   * Tests that http_response replaces other tags, and too many tags.
   */
  public function testAnnouncesHttpResponseForEverything(): void {
    $invalidator = $this->invalidator([self::frontend()], [new Response(204), new Response(204)]);

    // Auto-save invalidations are not announced, so announce nothing for them.
    $invalidator->invalidateTags(['canvas__auto_save']);
    $invalidator->destruct();
    self::assertSame([], $this->values);

    $invalidator->invalidateTags(['node:1', 'http_response']);
    $invalidator->invalidateTags(['node:2']);
    $invalidator->destruct();
    $invalidator->invalidateTags(\array_map(static fn (int $i): string => "node:$i", range(1, 1001)));
    $invalidator->destruct();

    self::assertSame([['http_response'], ['http_response']], array_column($this->values, 'tags'));
  }

  /**
   * Tests that a failure is logged, not thrown to the kernel.
   */
  public function testLogsFailures(): void {
    $logger = $this->createMock(LoggerChannelInterface::class);
    $logger->expects($this->once())->method('log')->with(LogLevel::ERROR);
    $invalidator = $this->invalidator(
      [self::frontend()],
      static fn () => throw new \RuntimeException('The HTTP client is broken.'),
      $logger,
    );
    $invalidator->invalidateTags(['node:1']);
    $invalidator->destruct();
  }

  /**
   * Tests that nothing is announced without enabled frontends.
   */
  public function testWithoutFrontends(): void {
    $invalidator = $this->invalidator(
      [
        self::frontend(FALSE),
        ['url' => 'https://legacy.example', 'components' => []],
      ],
      fn () => $this->fail('The HTTP client is not used.'),
    );
    $invalidator->invalidateTags(['node:1']);
    $invalidator->destruct();
    $invalidator->retryFailed();
    self::assertSame([], $invalidator->getStatus());
    self::assertSame([], $this->states->getAll());

    // The state of a frontend that receives no announcements is forgotten.
    $this->states->set('unused', ['url' => 'https://removed.example']);
    $invalidator->deleteUnusedStates();
    self::assertSame([], $this->states->getAll());
  }

  /**
   * Tests testing a frontend that does not accept the announcement.
   */
  public function testTestNotAnnounced(): void {
    $invalidator = $this->invalidator([self::frontend()], [
      new Response(404, [], 'A body that is not reported'),
      new ConnectException('Connection refused', new Request('POST', self::FRONTEND . '/api/canvas/revalidate')),
    ]);

    self::assertSame([
      'ok' => FALSE,
      'announced' => FALSE,
      'read' => FALSE,
      'message' => 'The frontend answered HTTP 404.',
    ], $invalidator->test(self::FRONTEND));
    self::assertSame('failing', $invalidator->getStatus()[self::FRONTEND]['state']);

    // A test ignores the backoff.
    self::assertSame([
      'ok' => FALSE,
      'announced' => FALSE,
      'read' => FALSE,
      'message' => 'The frontend could not be reached: Connection refused',
    ], $invalidator->test(self::FRONTEND));
    self::assertCount(2, $this->history);
    self::assertSame(2, $this->state()['failures']);
    self::assertSame([], $this->values);
  }

  /**
   * Tests testing a frontend that does not read the announced tags.
   */
  public function testTestNotRead(): void {
    $invalidator = $this->invalidator([self::frontend()], [new Response(204)]);
    // Do not wait for the frontend.
    $this->tick = 5.0;

    self::assertSame([
      'ok' => FALSE,
      'announced' => TRUE,
      'read' => FALSE,
      'message' => 'The frontend accepted the announcement but did not read the cache tags from Drupal. Check that CANVAS_SITE_URL on the frontend points at this site.',
    ], $invalidator->test(self::FRONTEND));
    // Recorded at once, so that no later announcement counts it again.
    self::assertSame([], $this->values);
    self::assertSame([], $this->state()['pending']);
    self::assertSame('The frontend did not read the announced cache tags.', $invalidator->getStatus()[self::FRONTEND]['lastError']);
  }

  /**
   * Tests a test whose tags are read just before it gives up.
   */
  public function testTestReadAtTheDeadline(): void {
    $invalidator = $this->invalidator([self::frontend()], [new Response(204)]);
    // The poll still finds the entry; the frontend reads it while the test
    // waits for the lock to record the failure.
    $this->tick = 5.0;
    $this->beforeLock = function () use ($invalidator): void {
      if ($this->values !== [] && $this->history !== []) {
        $this->beforeLock = NULL;
        $invalidator->redeem($this->announcedId(0));
      }
    };
    self::assertTrue($invalidator->test(self::FRONTEND)['ok']);
    self::assertSame('ok', $invalidator->getStatus()[self::FRONTEND]['state']);
  }

  /**
   * Tests that one frontend's broken state does not stop the others.
   */
  public function testOneFrontendFails(): void {
    $logger = $this->createMock(LoggerChannelInterface::class);
    $logger->expects($this->once())->method('log')->with(LogLevel::ERROR);
    $invalidator = $this->invalidator(
      [
        ['url' => 'https://broken.example', 'components' => [], 'cache_invalidation' => TRUE],
        self::frontend(),
      ],
      [
        new ConnectException(
          'cURL error 7: Failed to connect to www.example.com port 443 after 3 ms: Could not connect to server (see https://curl.se/libcurl/c/libcurl-errors.html) for https://www.example.com/api/canvas/revalidate',
          new Request('POST', self::FRONTEND . '/api/canvas/revalidate'),
        ),
      ],
      $logger,
    );
    $this->brokenKey = Crypt::hashBase64('https://broken.example');
    $invalidator->invalidateTags(['node:1']);
    $invalidator->destruct();

    self::assertCount(1, $this->history);
    self::assertSame(self::FRONTEND . '/api/canvas/revalidate', (string) $this->history[0]['request']->getUri());
    // Without the link to the cURL documentation and the URL.
    self::assertSame('cURL error 7: Failed to connect to www.example.com port 443 after 3 ms: Could not connect to server', $invalidator->getStatus()[self::FRONTEND]['lastError']);
  }

  /**
   * Tests testing a frontend that reads the announced tags.
   */
  public function testTestRead(): void {
    $responses = new MockHandler();
    $invalidator = $this->invalidator([self::frontend(), ['url' => 'https://disabled.example', 'components' => [], 'cache_invalidation' => FALSE]], $responses);
    $read = [];
    // The frontend reads the tags before it answers.
    $respond = static function (RequestInterface $request) use (&$read, $invalidator): Response {
      $read[] = $invalidator->redeem(json_decode((string) $request->getBody(), TRUE, flags: JSON_THROW_ON_ERROR)['id']);
      return new Response(204);
    };
    $responses->append($respond, $respond, new Response(500), $respond, $respond, $respond);
    $expected = [
      'ok' => TRUE,
      'announced' => TRUE,
      'read' => TRUE,
      'message' => 'The frontend received the announcement and read the cache tags.',
    ];

    self::assertSame($expected, $invalidator->test(self::FRONTEND));
    self::assertSame($expected, $invalidator->test(self::FRONTEND, TRUE));
    // A frontend that missed an announcement expires everything.
    self::assertFalse($invalidator->test(self::FRONTEND)['ok']);
    self::assertSame($expected, $invalidator->test(self::FRONTEND));
    self::assertSame([[FrontendCacheTagInvalidator::TEST_TAG], ['http_response'], ['http_response']], $read);
    self::assertSame(self::FRONTEND . '/api/canvas/revalidate', (string) $this->history[0]['request']->getUri());
    self::assertNull($this->state()['missed_at']);

    // A test that notices an unread announcement repairs the miss itself,
    // also when time passes while it runs.
    $this->states->set(Crypt::hashBase64(self::FRONTEND), ['pending' => ['unread' => $this->now - 40]] + (array) $this->states->get(Crypt::hashBase64(self::FRONTEND)));
    $this->tick = 0.001;
    self::assertSame($expected, $invalidator->test(self::FRONTEND));
    $this->tick = 0.0;
    self::assertSame(['http_response'], end($read));
    self::assertNull($this->state()['missed_at']);
    self::assertSame('ok', $invalidator->getStatus()[self::FRONTEND]['state']);

    // Cache invalidation does not have to be enabled. Such a frontend keeps
    // no state, and has nothing to catch up on.
    self::assertSame($expected, $invalidator->test('https://disabled.example'));
    self::assertSame([FrontendCacheTagInvalidator::TEST_TAG], end($read));
    self::assertSame([self::FRONTEND], \array_keys($this->allStates()));
  }

  /**
   * Returns a frontend entry of canvas_headless.settings.
   *
   * @return array{url: string, components: list<string>, cache_invalidation: bool}
   *   The frontend.
   */
  private static function frontend(bool $cache_invalidation = TRUE): array {
    return ['url' => self::FRONTEND, 'components' => [], 'cache_invalidation' => $cache_invalidation];
  }

  /**
   * Returns a lock backend that runs self::$beforeLock first.
   */
  private function lock(): LockBackendInterface {
    $lock = $this->createMock(LockBackendInterface::class);
    $lock->method('acquire')->willReturnCallback(function (): bool {
      if ($this->beforeLock !== NULL) {
        ($this->beforeLock)();
      }
      return TRUE;
    });
    return $lock;
  }

  /**
   * Returns the stored state of a frontend, without its URL.
   *
   * @return array<string, mixed>
   *   The state.
   */
  private function state(string $url = self::FRONTEND): array {
    $state = $this->allStates()[$url] ?? NULL;
    self::assertIsArray($state);
    return $state;
  }

  /**
   * Returns the stored states without their URL, by base URL.
   *
   * @return array<string, array<string, mixed>>
   *   The states.
   */
  private function allStates(): array {
    $states = [];
    foreach ($this->states->getAll() as $key => $state) {
      self::assertSame($key, Crypt::hashBase64($state['url']));
      $states[$state['url']] = array_diff_key($state, ['url' => TRUE]);
    }
    return $states;
  }

  /**
   * Returns the ID announced by a request.
   */
  private function announcedId(int $index): string {
    return json_decode((string) $this->history[$index]['request']->getBody(), TRUE, flags: JSON_THROW_ON_ERROR)['id'];
  }

  /**
   * Creates the invalidator with in-memory storage and a controlled time.
   *
   * @param list<array<string, mixed>> $frontends
   *   The frontends of canvas_headless.settings.
   * @param array<int, mixed>|\GuzzleHttp\Handler\MockHandler|\Closure $responses
   *   The responses of the frontends, in order, or a closure returning the
   *   HTTP client.
   * @param \Drupal\Core\Logger\LoggerChannelInterface|null $logger
   *   The logger of the canvas_headless channel.
   */
  private function invalidator(array $frontends, array|MockHandler|\Closure $responses, ?LoggerChannelInterface $logger = NULL): FrontendCacheTagInvalidator {
    if (!$responses instanceof \Closure) {
      $handler = HandlerStack::create(\is_array($responses) ? new MockHandler($responses) : $responses);
      $handler->push(Middleware::tap(function (RequestInterface $request, array $options): void {
        $this->history[] = ['request' => $request, 'options' => $options];
      }));
      $responses = static fn () => new Client(['handler' => $handler]);
    }

    $store = $this->createMock(KeyValueStoreExpirableInterface::class);
    $store->method('setWithExpire')->willReturnCallback(function (string $key, array $value): void {
      $this->values[$key] = $value;
    });
    $store->method('get')->willReturnCallback(fn (string $key): ?array => $this->values[$key] ?? NULL);
    $store->method('delete')->willReturnCallback(function (string $key): void {
      unset($this->values[$key]);
    });
    $key_value_expirable = $this->createMock(KeyValueExpirableFactoryInterface::class);
    $key_value_expirable->method('get')->with(FrontendCacheTagInvalidator::COLLECTION)->willReturn($store);
    if ($this->known) {
      foreach ($frontends as $frontend) {
        $url = FrontendUrl::fromConfig($frontend['url'])?->baseUrl;
        if ($url !== NULL && ($frontend['cache_invalidation'] ?? FALSE)) {
          $this->states->set(Crypt::hashBase64($url), [
            'url' => $url,
            'missed_at' => NULL,
            'pending' => [],
            'failures' => 0,
            'last_error' => NULL,
            'last_failure' => NULL,
            'last_success' => NULL,
            'retry_after' => 0,
          ]);
        }
      }
    }
    $states = new class('state', $this) extends MemoryStorage {

      public function __construct(string $collection, private readonly FrontendCacheTagInvalidatorTest $test) {
        parent::__construct($collection);
      }

      /**
       * {@inheritdoc}
       */
      public function set($key, $value): void {
        if ($key === $this->test->brokenKey()) {
          throw new \RuntimeException('The state cannot be saved.');
        }
        $this->test->states()->set($key, $value);
      }

      /**
       * {@inheritdoc}
       */
      public function get($key, $default = NULL): mixed {
        return $this->test->states()->get($key, $default);
      }

      /**
       * {@inheritdoc}
       */
      public function getMultiple(array $keys): array {
        return $this->test->states()->getMultiple($keys);
      }

      /**
       * {@inheritdoc}
       */
      public function getAll(): array {
        return $this->test->states()->getAll();
      }

      /**
       * {@inheritdoc}
       */
      public function delete($key): void {
        $this->test->states()->delete($key);
      }

      /**
       * {@inheritdoc}
       */
      public function deleteMultiple(array $keys): void {
        $this->test->states()->deleteMultiple($keys);
      }

    };
    $key_value = $this->createMock(KeyValueFactoryInterface::class);
    $key_value->method('get')->with(FrontendCacheTagInvalidator::STATE_COLLECTION)->willReturn($states);

    $logger_factory = $this->createMock(LoggerChannelFactoryInterface::class);
    $logger_factory->method('get')->with('canvas_headless')->willReturn($logger ?? $this->createMock(LoggerChannelInterface::class));

    $time = $this->createMock(TimeInterface::class);
    $time->method('getCurrentTime')->willReturnCallback(fn (): int => (int) $this->now);
    $time->method('getCurrentMicroTime')->willReturnCallback(function (): float {
      $this->now += $this->tick;
      return $this->now;
    });

    return new FrontendCacheTagInvalidator(
      $responses,
      fn () => $this->getConfigFactoryStub(['canvas_headless.settings' => ['frontends' => $frontends]]),
      static fn () => $key_value_expirable,
      static fn () => $key_value,
      static fn () => $logger_factory,
      fn () => $this->lock(),
      static fn () => $time,
    );
  }

  /**
   * Returns the stored states.
   */
  public function states(): MemoryStorage {
    return $this->states;
  }

  /**
   * Returns the state key whose writes fail.
   */
  public function brokenKey(): ?string {
    return $this->brokenKey;
  }

}
