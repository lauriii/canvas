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
      (new CacheableMetadata())->setCacheContexts(['url.path', 'languages:language_interface', 'route']),
    );
    $this->assertTrue($normalized['varies']['public']);
    $this->assertSame([], $normalized['varies']['on']);
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
    $this->assertTrue($normalized['varies']['public']);
    $this->assertSame(['x-something'], $normalized['varies']['on']);
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
   * Normalizes cacheability.
   *
   * @return array<string, mixed>
   *   The normalized cacheability.
   */
  private function normalize(CacheableMetadata $metadata): array {
    return CacheabilityNormalizer::normalize($metadata);
  }

}
