<?php

declare(strict_types=1);

namespace Drupal\canvas_personalization_vwo;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Utility\Error;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Answers "is this visitor in this VWO audience?" for the current request.
 *
 * All the degradation policy lives here, so that it is exercised whichever
 * resolver is injected:
 *
 * - No VWO cookie on the request (first-ever visit, localStorage mode, an
 *   opted-out visitor, a bot): NOT a member. VWO is never consulted, so the
 *   common cold-cache case costs nothing.
 * - A cookie whose visitor UUID is malformed: NOT a member, VWO is not
 *   consulted. VWO's own SDKs reject these, so sending them is pointless.
 * - A resolver that throws, times out or returns garbage: NOT a member, and
 *   the failure is negatively cached for `failure_ttl` seconds so an outage
 *   costs one attempt per TTL rather than one per render.
 * - A successful answer is cached for `membership_ttl` seconds, which is also
 *   the max-age the segment condition declares.
 *
 * Results are additionally memoized per request, because a page may reference
 * the same audience from several switches.
 *
 * @see \Drupal\canvas_personalization_vwo\Plugin\SegmentCondition\VwoAudience
 */
final class VwoAudienceMembership {

  /**
   * VWO's own SDKs accept exactly this shape for a browser-issued UUID.
   *
   * @see https://github.com/wingify/wingify-fme-php-sdk/blob/master/src/Utils/UuidUtil.php
   */
  private const string VISITOR_UUID_PATTERN = '/^[DJ][0-9A-Fa-f]{32}$/';

  /**
   * Memoized answers, keyed by the request they were resolved against.
   *
   * A \WeakMap and not a plain array: this service is a singleton, so in any
   * process serving more than one request — kernel and functional tests,
   * subrequests, a persistent-kernel runtime — a flag-keyed array would hand
   * one visitor's membership to the next, which is exactly the wrong-variant
   * outcome failing closed exists to prevent. Entries also disappear with the
   * request, so the store cannot grow without bound.
   *
   * @var \WeakMap<\Symfony\Component\HttpFoundation\Request, array<string, bool>>
   */
  private \WeakMap $memoized;

  public function __construct(
    private readonly VwoAudienceResolverInterface $resolver,
    private readonly CacheBackendInterface $cache,
    private readonly RequestStack $requestStack,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly TimeInterface $time,
    private readonly LoggerChannelInterface $logger,
  ) {
    $this->memoized = new \WeakMap();
  }

  /**
   * Whether the current visitor is in the audience behind $flag_key.
   *
   * Never throws: every failure mode resolves to FALSE.
   */
  public function isInAudience(string $flag_key): bool {
    $request = $this->requestStack->getCurrentRequest();
    if ($request === NULL) {
      // Without a request there is no visitor, and nothing to memoize against.
      return FALSE;
    }
    $answers = $this->memoized[$request] ?? [];
    if (!\array_key_exists($flag_key, $answers)) {
      $answers[$flag_key] = $this->lookUp($flag_key);
      $this->memoized[$request] = $answers;
    }
    return $answers[$flag_key];
  }

  /**
   * The VWO visitor UUID carried by the current request, if any.
   *
   * Returns NULL when there is no request, no cookie, or the cookie does not
   * carry a UUID VWO would accept.
   */
  public function getVisitorUuid(): ?string {
    $request = $this->requestStack->getCurrentRequest();
    if ($request === NULL) {
      return NULL;
    }
    $cookie = $request->cookies->get($this->getCookieName());
    return \is_string($cookie) ? self::parseVisitorUuid($cookie) : NULL;
  }

  /**
   * Extracts the visitor UUID from a raw VWO identity cookie value.
   *
   * VWO's SmartCode writes two pipe-separated fields — the visitor UUID and a
   * hash — and writes the pipe raw while its own read path tolerates a
   * percent-encoded one, so both are accepted here. A value that does not
   * carry a UUID in VWO's documented shape returns NULL rather than being
   * forwarded: VWO's SDKs reject it anyway, and guessing is how a wrong
   * variant gets served.
   *
   * Pure and static so the parsing is unit-testable without a container.
   */
  public static function parseVisitorUuid(string $cookie_value): ?string {
    $candidate = \explode('|', \rawurldecode($cookie_value))[0];
    return \preg_match(self::VISITOR_UUID_PATTERN, $candidate) === 1 ? $candidate : NULL;
  }

  /**
   * The cookie name VWO writes the visitor identity to.
   *
   * Configurable because VWO accounts created from 2026-06-14 write
   * `_wingify_uuid_v2` instead, and an account may carry a cookie prefix.
   */
  public function getCookieName(): string {
    $name = $this->settings()->get('cookie_name');
    return \is_string($name) && $name !== '' ? $name : '_vwo_uuid_v2';
  }

  /**
   * How long a membership answer stays valid, in seconds.
   */
  public function getMembershipTtl(): int {
    return \max(1, (int) $this->settings()->get('membership_ttl'));
  }

  private function lookUp(string $flag_key): bool {
    $visitor_uuid = $this->getVisitorUuid();
    if ($visitor_uuid === NULL) {
      return FALSE;
    }

    $cid = \sprintf('canvas_personalization_vwo:%s:%s', $flag_key, \hash('xxh128', $visitor_uuid));
    $cached = $this->cache->get($cid);
    if ($cached !== FALSE) {
      return (bool) $cached->data;
    }

    try {
      $member = $this->resolver->isInAudience($flag_key, $visitor_uuid);
      $ttl = $this->getMembershipTtl();
    }
    catch (\Throwable $exception) {
      // Fail closed, and remember the failure briefly: an outage must cost one
      // attempt per failure_ttl, not one per render. The evaluator would also
      // catch a rethrow, but then every render would pay the timeout again.
      Error::logException($this->logger, $exception, 'Resolving VWO audience %flag failed; treating the visitor as not a member.', ['%flag' => $flag_key]);
      $member = FALSE;
      $ttl = \max(1, (int) $this->settings()->get('failure_ttl'));
    }

    $this->cache->set($cid, $member, $this->time->getRequestTime() + $ttl);
    return $member;
  }

  private function settings(): ImmutableConfig {
    return $this->configFactory->get('canvas_personalization_vwo.settings');
  }

}
