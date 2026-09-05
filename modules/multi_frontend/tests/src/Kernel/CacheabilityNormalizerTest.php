<?php

declare(strict_types=1);

namespace Drupal\Tests\multi_frontend\Kernel;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\KernelTests\KernelTestBase;
use Drupal\multi_frontend\Envelope\CacheabilityNormalizer;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests that cacheability crosses the boundary in a usable form.
 */
#[Group('multi_frontend')]
#[RunTestsInSeparateProcesses]
final class CacheabilityNormalizerTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'multi_frontend'];

  /**
   * Drupal's permanent sentinel does not cross as a negative max-age.
   */
  public function testPermanentMaxAgeCrossesAsNull(): void {
    $normalized = $this->normalize((new CacheableMetadata())->setCacheMaxAge(-1));
    $this->assertNull($normalized['maxAge']);

    $normalized = $this->normalize((new CacheableMetadata())->setCacheMaxAge(60));
    $this->assertSame(60, $normalized['maxAge']);
  }

  /**
   * Contexts that only vary by URL keep a response publicly cacheable.
   */
  public function testUrlBorneContextsStayPublic(): void {
    $normalized = $this->normalize(
      (new CacheableMetadata())->setCacheContexts(['url.path', 'route']),
    );
    $this->assertTrue($normalized['varies']['public']);
    $this->assertSame([], $normalized['varies']['on']);
  }

  /**
   * Language is not URL-borne, so it varies on the negotiated header.
   *
   * Drupal can negotiate language from the URL, but also from a header, a
   * cookie, or the session. Treating it as URL-borne would let a shared cache
   * serve one language's response for another with no Vary at all. When
   * negotiation is by cookie or session, a cookie context is present too and
   * makes the response private anyway.
   */
  public function testLanguageContextVariesOnHeader(): void {
    $normalized = $this->normalize(
      (new CacheableMetadata())->setCacheContexts(['languages:language_interface']),
    );
    $this->assertSame(['accept-language'], $normalized['varies']['on']);
    // Not public: core's user negotiator reads the account's preferred
    // language and its session negotiator reads the session, so two visitors
    // sending the same Accept-Language can still be owed different responses.
    $this->assertFalse($normalized['varies']['public']);
  }

  /**
   * Per-visitor contexts are never reported as shared-cache safe.
   *
   * Each of these was once classified as safe on the reasoning that it is
   * constant for anonymous visitors. None of them is, and being wrong in the
   * permissive direction here puts one visitor's response in front of
   * another.
   */
  public function testPerVisitorContextsAreNotPublic(): void {
    foreach (['ip', 'theme', 'timezone', 'headers', 'headers:Cookie', 'session', 'user.permissions'] as $context) {
      $normalized = $this->normalize((new CacheableMetadata())->setCacheContexts([$context]));
      $this->assertFalse(
        $normalized['varies']['public'],
        \sprintf('Context "%s" must not be reported as publicly cacheable.', $context),
      );
    }
  }

  /**
   * A per-account context makes the response private and varies on cookie.
   */
  public function testAccountContextsAreNotPublic(): void {
    $normalized = $this->normalize(
      (new CacheableMetadata())->setCacheContexts(['user.permissions']),
    );
    $this->assertFalse($normalized['varies']['public']);
    $this->assertSame(['cookie'], $normalized['varies']['on']);
    // The opaque plugin ID is still there for Drupal-aware consumers, but it
    // is not what anybody else is expected to act on.
    $this->assertSame(['user.permissions'], $normalized['contexts']);
  }

  /**
   * A header context maps onto the header it varies on.
   */
  public function testHeaderContextMapsToVary(): void {
    $normalized = $this->normalize(
      (new CacheableMetadata())->setCacheContexts(['headers:X-Something']),
    );
    $this->assertSame(['x-something'], $normalized['varies']['on']);
    // Naming the dimension is not the same as making the response shareable.
    $this->assertFalse($normalized['varies']['public']);
  }

  /**
   * An unrecognized context is treated as private, which is the safe default.
   */
  public function testUnknownContextIsPrivate(): void {
    $normalized = $this->normalize(
      (new CacheableMetadata())->setCacheContexts(['some_module.thing']),
    );
    $this->assertFalse($normalized['varies']['public']);
  }

  /**
   * Drupal's tag-shaped internal sentinels do not cross the boundary.
   *
   * Every form carries one of these. It is not a cache tag anything can invalidate, so
   * emitting it would put a value a CDN cannot purge into the one header a
   * CDN acts on.
   */
  public function testSentinelTagsAreNotEmitted(): void {
    $normalized = $this->normalize(
      (new CacheableMetadata())->setCacheTags([
        'CACHE_MISS_IF_UNCACHEABLE_HTTP_METHOD:form',
        'config:system.site',
      ]),
    );
    $this->assertSame(['config:system.site'], $normalized['tags']);
  }

  /**
   * Normalizes cacheability.
   *
   * @return array<string, mixed>
   *   The normalized cacheability.
   */
  private function normalize(CacheableMetadata $metadata): array {
    return CacheabilityNormalizer::normalize($metadata);
  }

}
