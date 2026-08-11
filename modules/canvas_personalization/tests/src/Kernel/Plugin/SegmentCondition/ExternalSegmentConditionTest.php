<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_personalization\Kernel\Plugin\SegmentCondition;

use Drupal\canvas_personalization\SegmentCondition\SegmentConditionInterface;
use Drupal\canvas_personalization\SegmentCondition\SegmentConditionManager;
use Drupal\canvas_personalization_test\Plugin\SegmentCondition\TestExternalProvider;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;

/**
 * Tests the degradation policy every provider integration inherits.
 *
 * This is the contract docs/personalization.md §6 promises: an integration
 * supplies an identity and a lookup, and gets the bounded-TTL membership
 * cache, the negative cache and fail-closed behavior without writing them.
 */
#[Group('canvas')]
#[Group('canvas_personalization')]
final class ExternalSegmentConditionTest extends CanvasKernelTestBase {

  protected static $modules = [
    'canvas_personalization',
    'canvas_personalization_test',
    // @see https://www.drupal.org/i/3520484
    'canvas_dev_mode',
  ];

  private function setProvider(array|string $behavior): void {
    $this->container->get('state')->set(TestExternalProvider::BEHAVIOR_KEY, $behavior);
    $this->container->get('state')->set(TestExternalProvider::CALLS_KEY, 0);
  }

  private function calls(): int {
    return (int) $this->container->get('state')->get(TestExternalProvider::CALLS_KEY, 0);
  }

  private function setRequest(?string $identity): void {
    $request = new Request(cookies: $identity === NULL ? [] : [TestExternalProvider::COOKIE => $identity]);
    $request->setSession(new Session());
    $request_stack = $this->container->get(RequestStack::class);
    \assert($request_stack instanceof RequestStack);
    $request_stack->push($request);
  }

  private function condition(array $configuration = []): SegmentConditionInterface {
    $condition = $this->container->get(SegmentConditionManager::class)
      ->createInstance(TestExternalProvider::PLUGIN_ID, $configuration);
    \assert($condition instanceof SegmentConditionInterface);
    return $condition;
  }

  /**
   * One method on purpose: a kernel boot per case buys no isolation here.
   */
  public function testDegradationPolicy(): void {
    // A visitor the provider recognizes matches, and is looked up once.
    $this->setProvider(['visitor-1']);
    $this->setRequest('visitor-1');
    $this->assertTrue($this->condition()->evaluate());
    $this->assertSame(1, $this->calls());

    // A second render for the same visitor comes from the membership cache.
    $this->setRequest('visitor-1');
    $this->assertTrue($this->condition()->evaluate());
    $this->assertSame(1, $this->calls(), 'The membership cache prevents a second lookup.');

    // A visitor the provider does not recognize does not match.
    $this->setProvider(['visitor-1']);
    $this->setRequest('visitor-2');
    $this->assertFalse($this->condition()->evaluate());

    // No identity on the request: not a member, and the provider is never
    // consulted. This is every first visit and every bot, and it must not
    // cost a network call.
    $this->setProvider(['visitor-1']);
    $this->setRequest(NULL);
    $this->assertFalse($this->condition()->evaluate());
    $this->assertSame(0, $this->calls(), 'A missing identity must not cost a provider call.');

    // An unreachable provider fails closed rather than throwing, and the
    // failure is negatively cached.
    $this->setProvider('throw');
    $this->setRequest('visitor-3');
    $this->assertFalse($this->condition()->evaluate());
    $this->setRequest('visitor-3');
    $this->assertFalse($this->condition()->evaluate());
    $this->assertSame(1, $this->calls(), 'A failure is negatively cached.');

    // Negation is applied to the resolved answer, by SegmentConditionBase.
    $this->setProvider(['visitor-4']);
    $this->setRequest('visitor-4');
    $this->assertFalse($this->condition(['negate' => TRUE])->evaluate());
  }

  /**
   * Two rules of the same provider never share a cached answer.
   */
  public function testCacheIsKeyedByConfiguration(): void {
    $this->setProvider(['visitor-9']);
    $this->setRequest('visitor-9');
    $this->assertTrue($this->condition(['audience' => 'a'])->evaluate());
    $this->assertSame(1, $this->calls());
    // Different settings, same visitor: the provider is consulted again
    // rather than reusing the first rule's answer.
    $this->assertTrue($this->condition(['audience' => 'b'])->evaluate());
    $this->assertSame(2, $this->calls());
  }

  /**
   * The declared cacheability is the provider's, bounded by the TTL.
   */
  public function testDeclaredCacheability(): void {
    $condition = $this->condition();
    $this->assertSame(['cookies:' . TestExternalProvider::COOKIE], $condition->getCacheContexts());
    // The default max-age is the membership TTL: the result stays valid
    // exactly as long as the cached answer does.
    $this->assertSame(300, $condition->getCacheMaxAge());
    // Segment conditions MUST NOT set cache tags.
    $this->assertSame([], $condition->getCacheTags());
  }

}
