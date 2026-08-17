<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_personalization\Kernel;

use Drupal\canvas_personalization\Entity\Segment;
use Drupal\canvas_personalization\SegmentEvaluator;
use Drupal\canvas_personalization_test\Plugin\SegmentCondition\TestUnreachableProvider;
use Drupal\Core\Cache\Cache;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;

/**
 * Tests segment evaluation and its cacheability collection.
 */
#[Group('canvas')]
#[Group('canvas_personalization')]
final class SegmentEvaluatorTest extends CanvasKernelTestBase {

  protected static $modules = [
    'canvas_personalization',
    'canvas_personalization_test',
    // @see https://www.drupal.org/i/3520484
    'canvas_dev_mode',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['canvas_personalization']);
  }

  private function setRequest(array $query = []): void {
    $request = new Request($query);
    $request->setSession(new Session());
    // Push onto the existing stack: the evaluator holds the injected
    // RequestStack instance, so replacing the service would not reach it.
    $request_stack = $this->container->get(RequestStack::class);
    \assert($request_stack instanceof RequestStack);
    $request_stack->push($request);
  }

  private function evaluator(): SegmentEvaluator {
    return $this->container->get(SegmentEvaluator::class);
  }

  /**
   * The locked default segment has no rules, so it matches every visitor.
   */
  public function testDefaultSegmentAlwaysMatches(): void {
    $this->setRequest();
    $match = $this->evaluator()->evaluate(Segment::DEFAULT_ID);
    $this->assertTrue($match->matched);
    $this->assertSame(['config:canvas_personalization.segment.default'], $match->cacheability->getCacheTags());
    $this->assertSame([], $match->cacheability->getCacheContexts());
    $this->assertSame(Cache::PERMANENT, $match->cacheability->getCacheMaxAge());
  }

  /**
   * A missing segment fails closed but still carries its config cache tag.
   */
  public function testMissingSegmentFailsClosedWithCacheTag(): void {
    $this->setRequest();
    $match = $this->evaluator()->evaluate('does_not_exist_yet');
    $this->assertFalse($match->matched);
    $this->assertSame(['config:canvas_personalization.segment.does_not_exist_yet'], $match->cacheability->getCacheTags());
  }

  /**
   * A disabled segment never matches and hides its conditions' contexts.
   */
  public function testDisabledSegmentFailsClosed(): void {
    Segment::create([
      'id' => 'disabled_segment',
      'label' => 'Disabled',
      'status' => FALSE,
      'rules' => [
        'query_parameter' => [
          'id' => 'query_parameter',
          'negate' => FALSE,
          'parameter' => 'coupon',
          'value' => 'BLACKFRIDAY',
          'matching' => 'exact',
        ],
      ],
    ])->save();
    $this->setRequest(['coupon' => 'BLACKFRIDAY']);
    $match = $this->evaluator()->evaluate('disabled_segment');
    $this->assertFalse($match->matched);
    // A disabled segment's result cannot vary by request context, so no
    // contexts — but the tag stays, so enabling it invalidates pages.
    $this->assertSame([], $match->cacheability->getCacheContexts());
    $this->assertSame(['config:canvas_personalization.segment.disabled_segment'], $match->cacheability->getCacheTags());
  }

  /**
   * Rules AND together; cacheability is collected from every rule regardless.
   */
  public function testAllRulesMustMatchAndCacheabilityIsCollectedFromAll(): void {
    Segment::create([
      'id' => 'weekend_coupon',
      'label' => 'Weekend coupon',
      'status' => TRUE,
      'rules' => [
        'query_parameter' => [
          'id' => 'query_parameter',
          'negate' => FALSE,
          'parameter' => 'coupon',
          'value' => 'BLACKFRIDAY',
          'matching' => 'exact',
        ],
        'day_of_week' => [
          'id' => 'day_of_week',
          'negate' => TRUE,
          // Negated all-days never matches, deterministically.
          'days' => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'],
        ],
      ],
    ])->save();
    $this->setRequest(['coupon' => 'BLACKFRIDAY']);
    $match = $this->evaluator()->evaluate('weekend_coupon');
    // The query parameter matches but the negated day-of-week rule cannot.
    $this->assertFalse($match->matched);
    // Both rules' cacheability was collected even though the outcome was
    // already decided: contexts from the query rule, max-age from the day
    // rule (seconds until next midnight, bounded by a day).
    $this->assertSame(['url.query_args:coupon'], $match->cacheability->getCacheContexts());
    $this->assertGreaterThan(0, $match->cacheability->getCacheMaxAge());
    $this->assertLessThanOrEqual(86400, $match->cacheability->getCacheMaxAge());
  }

  /**
   * Evaluation stops at the first non-match, without changing cacheability.
   *
   * The same segment is evaluated twice: once where the first rule already
   * decides the outcome and the provider condition is never consulted, once
   * where it is. Cacheability is derived from configuration, so it is
   * identical either way — which is what makes it safe to skip a third-party
   * segmentation provider call on every render of a page whose segment a
   * cheaper rule has already ruled out.
   */
  public function testEvaluationShortCircuitsWithoutChangingCacheability(): void {
    Segment::create([
      'id' => 'coupon_and_provider',
      'label' => 'Coupon and provider',
      'status' => TRUE,
      'rules' => [
        'query_parameter' => [
          'id' => 'query_parameter',
          'negate' => FALSE,
          'parameter' => 'coupon',
          'value' => 'BLACKFRIDAY',
          'matching' => 'exact',
        ],
        'test_unreachable_provider' => [
          'id' => 'test_unreachable_provider',
          'negate' => FALSE,
        ],
      ],
    ])->save();

    // Without the coupon the first rule decides it: the provider is not
    // consulted, saving a network call the outcome cannot depend on.
    $this->setRequest();
    TestUnreachableProvider::$evaluations = 0;
    $short_circuited = $this->evaluator()->evaluate('coupon_and_provider');
    $this->assertFalse($short_circuited->matched);
    $this->assertSame(0, TestUnreachableProvider::$evaluations);

    // With the coupon, evaluation reaches the provider — which throws, and
    // still fails closed.
    $this->setRequest(['coupon' => 'BLACKFRIDAY']);
    $evaluated_everything = $this->evaluator()->evaluate('coupon_and_provider');
    $this->assertFalse($evaluated_everything->matched);
    $this->assertSame(1, TestUnreachableProvider::$evaluations);

    // Both runs carry every rule's declared cacheability, identical to what
    // the page-cache integration consumes on requests where nothing evaluates.
    $this->assertEquals($short_circuited->cacheability, $evaluated_everything->cacheability);
    $this->assertEquals($this->evaluator()->getDeclaredCacheability(['coupon_and_provider']), $short_circuited->cacheability);
    $this->assertEqualsCanonicalizing(['url.query_args:coupon', 'cookies:canvas_test_provider'], $short_circuited->cacheability->getCacheContexts());
    $this->assertSame(300, $short_circuited->cacheability->getCacheMaxAge());
  }

  /**
   * A throwing condition (unreachable provider) fails closed.
   */
  public function testThrowingConditionFailsClosed(): void {
    Segment::create([
      'id' => 'provider_segment',
      'label' => 'Provider segment',
      'status' => TRUE,
      'rules' => [
        'test_unreachable_provider' => [
          'id' => 'test_unreachable_provider',
          'negate' => FALSE,
        ],
      ],
    ])->save();
    $this->setRequest();
    $match = $this->evaluator()->evaluate('provider_segment');
    $this->assertFalse($match->matched);
    // The provider's declared cacheability still reaches the response, so a
    // recovered provider takes effect within its own bounds.
    $this->assertSame(['cookies:canvas_test_provider'], $match->cacheability->getCacheContexts());
    $this->assertSame(300, $match->cacheability->getCacheMaxAge());
  }

  /**
   * Results are memoized per request; new requests get a fresh scope.
   */
  public function testMemoizationPerRequest(): void {
    $this->setRequest();
    $evaluator = $this->evaluator();
    $first = $evaluator->evaluate(Segment::DEFAULT_ID);
    $this->assertSame($first, $evaluator->evaluate(Segment::DEFAULT_ID));

    $this->setRequest(['other' => 'request']);
    $this->assertNotSame($first, $evaluator->evaluate(Segment::DEFAULT_ID));
  }

  /**
   * Sequential requests share no result, even when PHP recycles object IDs.
   *
   * Results are memoized against the request object itself. PHP hands a
   * garbage collected object's ID to the next object it allocates, so a key
   * derived from that ID would serve the first request's result to the
   * second — a silently wrong variant, the one outcome fail closed exists to
   * prevent.
   */
  public function testMemoizationSurvivesRecycledRequestObjectIds(): void {
    Segment::create([
      'id' => 'coupon',
      'label' => 'Coupon',
      'status' => TRUE,
      'rules' => [
        'query_parameter' => [
          'id' => 'query_parameter',
          'negate' => FALSE,
          'parameter' => 'coupon',
          'value' => 'BLACKFRIDAY',
          'matching' => 'exact',
        ],
      ],
    ])->save();
    $request_stack = $this->container->get(RequestStack::class);
    \assert($request_stack instanceof RequestStack);

    $first = new Request(['coupon' => 'BLACKFRIDAY']);
    $first->setSession(new Session());
    $request_stack->push($first);
    $first_object_id = \spl_object_id($first);
    $this->assertTrue($this->evaluator()->evaluate('coupon')->matched);

    // Let the first request go before the second one exists, which is what
    // frees its object ID for reuse — the two are never alive at once.
    $request_stack->pop();
    unset($first);
    $second = new Request();
    $second->setSession(new Session());
    $request_stack->push($second);
    $this->assertSame($first_object_id, \spl_object_id($second), 'PHP recycled the object ID, so this test exercises the collision.');

    $this->assertFalse($this->evaluator()->evaluate('coupon')->matched);
  }

  /**
   * Declared cacheability derives from configuration, without evaluation.
   *
   * This is what the page-cache integration consumes — including on requests
   * where a context-aware cache served the response and nothing evaluated.
   */
  public function testDeclaredCacheability(): void {
    Segment::create([
      'id' => 'geo_segment',
      'label' => 'Geo',
      'status' => TRUE,
      'rules' => [
        'geolocation' => [
          'id' => 'geolocation',
          'negate' => FALSE,
          'countries' => ['BE'],
          'regions' => [],
        ],
      ],
    ])->save();
    // Deliberately no request is involved at all.
    $declared = $this->evaluator()->getDeclaredCacheability(['geo_segment', 'missing_one', Segment::DEFAULT_ID]);
    $this->assertSame(['headers:X-Country-Code'], $declared->getCacheContexts());
    $this->assertEqualsCanonicalizing([
      'config:canvas_personalization.segment.geo_segment',
      'config:canvas_personalization.segment.missing_one',
      'config:canvas_personalization.segment.default',
    ], $declared->getCacheTags());

    $this->assertSame(
      ['geo_segment', 'missing_one'],
      SegmentEvaluator::extractSegmentIds([
        'config:canvas_personalization.segment.geo_segment',
        'node:5',
        'config:canvas_personalization.segment.missing_one',
        'rendered',
      ]),
    );
  }

}
