<?php

declare(strict_types=1);

namespace Drupal\canvas_personalization\SegmentCondition;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Utility\Error;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Base class for conditions resolving membership from an external provider.
 *
 * Every third-party segmentation provider needs the same policy around its
 * lookup: an identifier taken from the request, a bounded-TTL cache so the
 * provider is not consulted on every render, a shorter negative cache so an
 * outage costs one attempt per TTL rather than one per render, and a
 * fail-closed result on every error path. Getting that wrong is how a
 * personalized page ends up hanging on someone else's outage, so it lives
 * here rather than being rewritten by each integration.
 *
 * A subclass implements two methods:
 * - ::getVisitorIdentity() reads the provider's identifier off the current
 *   request — typically a first-party cookie. Returning NULL means "no
 *   identity", and the provider is then never consulted at all: the visitor
 *   is not a member. This is the common case on a first visit and for bots,
 *   and it must not cost a network call.
 * - ::resolveMembership() asks the provider. It is free to throw; a throwable
 *   is logged and negatively cached.
 *
 * A subclass MUST still declare ::getCacheContexts() — only it knows what its
 * identity varies by. ::getCacheMaxAge() defaults to the membership TTL, which
 * is the honest answer: the result stays valid exactly as long as the cached
 * answer does.
 *
 * ⚠️ Beware the cardinality of the declared cache context. A provider's
 * first-party cookie is usually a unique per-visitor identifier, so declaring
 * `cookies:<name>` gives every visitor their own dynamic_page_cache entry for
 * a personalized page. That is correct and never serves a wrong variant, but
 * it is not a shared cache. A provider that expects high anonymous traffic
 * should declare a derived, low-cardinality context instead — a calculated
 * cache context service resolving to membership rather than to identity — so
 * the page has as many cache entries as it has variants.
 *
 * @see \Drupal\canvas_personalization\SegmentCondition\SegmentConditionInterface
 * @see docs/personalization.md §6
 */
abstract class ExternalSegmentConditionBase extends SegmentConditionBase {

  /**
   * How long a membership answer is reused, in seconds.
   *
   * Also the declared max-age, so a longer TTL means a longer-lived cached
   * page. Override to make it configurable.
   */
  protected const int MEMBERSHIP_TTL = 300;

  /**
   * How long a failed lookup is remembered, in seconds.
   *
   * Shorter than the membership TTL: a recovered provider should take effect
   * quickly, but an outage must not cost one attempt per render.
   */
  protected const int FAILURE_TTL = 60;

  protected CacheBackendInterface $membershipCache;
  protected LoggerChannelInterface $logger;

  /**
   * {@inheritdoc}
   *
   * A condition needing services beyond the ones injected here overrides
   * ::create(), calls parent::create(), and assigns them as properties —
   * SegmentConditionBase::create() constructs `new static($configuration,
   * $plugin_id, $plugin_definition)`, so the constructor signature is fixed
   * and constructor injection is not available to plugins.
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->membershipCache = $container->get('cache.default');
    $instance->logger = $container->get('logger.channel.canvas_personalization');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  final protected function doEvaluate(): bool {
    $identity = $this->getVisitorIdentity();
    // No identity means the provider has nothing to answer about. Fail closed
    // without consulting it: this is every first visit and every bot.
    if ($identity === NULL || $identity === '') {
      return FALSE;
    }

    $cid = \sprintf('canvas_personalization.membership:%s:%s', $this->getPluginId(), \hash('xxh128', $this->getMembershipCacheKey() . ':' . $identity));
    $cached = $this->membershipCache->get($cid);
    if ($cached !== FALSE) {
      return (bool) $cached->data;
    }

    try {
      $member = $this->resolveMembership($identity);
      $ttl = $this->getMembershipTtl();
    }
    catch (\Throwable $exception) {
      // Fail closed, and remember the failure briefly. The evaluator would
      // also catch a rethrow, but then every render would pay the provider's
      // timeout again; negatively caching bounds an outage to one attempt per
      // identity per TTL.
      Error::logException($this->logger, $exception, 'Resolving membership for the %plugin_id segment condition failed; the visitor is treated as not a member.', ['%plugin_id' => $this->getPluginId()]);
      $member = FALSE;
      $ttl = $this->getFailureTtl();
    }

    $this->membershipCache->set($cid, $member, $this->time->getRequestTime() + $ttl);
    return $member;
  }

  /**
   * The provider's identifier for the visitor, or NULL if there is none.
   *
   * Implementations MUST NOT throw and MUST NOT contact the provider; this
   * runs on every evaluation, including ones that end up not consulting the
   * provider at all.
   */
  abstract protected function getVisitorIdentity(): ?string;

  /**
   * Asks the provider whether this visitor belongs to the configured audience.
   *
   * @throws \Throwable
   *   When the provider could not be consulted. The caller logs it and
   *   negatively caches the failure.
   */
  abstract protected function resolveMembership(string $identity): bool;

  /**
   * What, besides the visitor identity, the cached answer depends on.
   *
   * Defaults to the whole configuration, so two rules targeting different
   * audiences of the same provider never share a cache entry. Override only
   * to narrow it.
   */
  protected function getMembershipCacheKey(): string {
    return \serialize($this->getConfiguration());
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheMaxAge(): int {
    return $this->getMembershipTtl();
  }

  protected function getMembershipTtl(): int {
    return static::MEMBERSHIP_TTL;
  }

  protected function getFailureTtl(): int {
    return static::FAILURE_TTL;
  }

}
