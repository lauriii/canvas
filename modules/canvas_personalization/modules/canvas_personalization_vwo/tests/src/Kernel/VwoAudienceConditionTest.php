<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_personalization_vwo\Kernel;

use Drupal\canvas_personalization\Entity\Segment;
use Drupal\canvas_personalization\SegmentCondition\SegmentConditionInterface;
use Drupal\canvas_personalization\SegmentCondition\SegmentConditionManager;
use Drupal\canvas_personalization\SegmentEvaluator;
use Drupal\canvas_personalization_vwo\Plugin\SegmentCondition\VwoAudience;
use Drupal\canvas_personalization_vwo_test\StubVwoAudienceResolver;
use Drupal\Core\State\StateInterface;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;

/**
 * Tests the VWO audience condition: evaluation, cacheability, degradation.
 *
 * The resolver is stubbed, so everything asserted here is the integration's
 * own behavior rather than VWO's. What a live VWO account would add is one
 * thing only: whether the flag is actually enabled for a real visitor UUID.
 */
#[Group('canvas')]
#[Group('canvas_personalization')]
final class VwoAudienceConditionTest extends CanvasKernelTestBase {

  private const string FLAG = 'halloween_audience';

  /**
   * A distinct identity cookie in the shape VWO's SmartCode writes.
   *
   * Distinct visitors matter: the membership cache is keyed by visitor, so
   * reusing one would read a previous case's cached answer.
   */
  private static function visitorCookie(int $visitor = 1): string {
    return 'D' . \strtoupper(\str_pad(\dechex($visitor), 32, '0', \STR_PAD_LEFT)) . '|fa64c2dbf8455463770fd5d2edc77faf';
  }

  protected static $modules = [
    'canvas_personalization',
    'canvas_personalization_vwo',
    'canvas_personalization_vwo_test',
    // @see https://www.drupal.org/i/3520484
    'canvas_dev_mode',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['canvas_personalization', 'canvas_personalization_vwo']);
  }

  private function state(): StateInterface {
    return $this->container->get('state');
  }

  /**
   * Scripts the stubbed VWO and resets the call counter.
   *
   * @param array<string, list<string>>|string $behavior
   *   Flag keys per visitor UUID ('*' for every visitor), or 'throw'/'hang'.
   */
  private function setProvider(array|string $behavior): void {
    $this->state()->set(StubVwoAudienceResolver::BEHAVIOR_KEY, $behavior);
    $this->state()->set(StubVwoAudienceResolver::CALLS_KEY, 0);
  }

  private function calls(): int {
    return (int) $this->state()->get(StubVwoAudienceResolver::CALLS_KEY, 0);
  }

  /**
   * Puts a request carrying the given VWO cookie value on the stack.
   */
  private function setRequest(?string $cookie_value, string $cookie_name = '_vwo_uuid_v2'): void {
    $request = new Request(cookies: $cookie_value === NULL ? [] : [$cookie_name => $cookie_value]);
    $request->setSession(new Session());
    // Push onto the existing stack: services hold the injected RequestStack.
    $request_stack = $this->container->get(RequestStack::class);
    \assert($request_stack instanceof RequestStack);
    $request_stack->push($request);
  }

  private function condition(array $configuration = []): SegmentConditionInterface {
    $condition = $this->container->get(SegmentConditionManager::class)
      ->createInstance(VwoAudience::PLUGIN_ID, $configuration + ['flag_key' => self::FLAG]);
    \assert($condition instanceof SegmentConditionInterface);
    return $condition;
  }

  /**
   * The condition is discovered from a module outside canvas_personalization.
   */
  public function testDiscovery(): void {
    $definitions = $this->container->get(SegmentConditionManager::class)->getDefinitions();
    $this->assertArrayHasKey(VwoAudience::PLUGIN_ID, $definitions);
    $this->assertSame('VWO audience', (string) $definitions[VwoAudience::PLUGIN_ID]['label']);
  }

  /**
   * Evaluation and every degradation path.
   *
   * One method on purpose: a kernel boot per case costs about a minute and
   * buys no isolation, because the only per-case state is the request, the
   * scripted provider and the membership cache.
   */
  public function testEvaluationAndDegradation(): void {
    // A visitor VWO puts in the audience matches, and is looked up once.
    $this->setProvider(['*' => [self::FLAG]]);
    $this->setRequest(self::visitorCookie(1));
    $this->assertTrue($this->condition()->evaluate(), 'A visitor in the audience matches.');
    $this->assertSame(1, $this->calls());

    // A second render for the same visitor is served from the membership
    // cache: the provider is not consulted again.
    $this->setRequest(self::visitorCookie(1));
    $this->assertTrue($this->condition()->evaluate());
    $this->assertSame(1, $this->calls(), 'The membership cache prevents a second lookup.');

    // A visitor VWO does not put in the audience does not match.
    $this->setProvider([]);
    $this->setRequest(self::visitorCookie(2));
    $this->assertFalse($this->condition()->evaluate());

    // No VWO cookie: not a member, and VWO is never consulted. This is the
    // first-ever visit, an opted-out visitor, a bot, or VWO's localStorage
    // identity mode, in all of which the server sees no cookie at all.
    $this->setProvider(['*' => [self::FLAG]]);
    $this->setRequest(NULL);
    $this->assertFalse($this->condition()->evaluate(), 'A visitor without a VWO cookie is not a member.');
    $this->assertSame(0, $this->calls(), 'A missing cookie must not cost a provider call.');

    // A cookie VWO's own SDK would reject is treated the same way.
    $this->setRequest('not-a-vwo-uuid');
    $this->assertFalse($this->condition()->evaluate());
    $this->assertSame(0, $this->calls(), 'A malformed cookie must not cost a provider call.');

    // An unreachable provider fails closed rather than throwing: the visitor
    // gets the default variant, and the failure is negatively cached so an
    // outage costs one attempt per TTL rather than one per render.
    $this->setProvider('throw');
    $this->setRequest(self::visitorCookie(3));
    $this->assertFalse($this->condition()->evaluate(), 'An unreachable provider fails closed.');
    $this->setRequest(self::visitorCookie(3));
    $this->assertFalse($this->condition()->evaluate());
    $this->assertSame(1, $this->calls(), 'A failure is negatively cached.');

    // A provider slower than the hard timeout degrades identically.
    $this->setProvider('hang');
    $this->setRequest(self::visitorCookie(4));
    $this->assertFalse($this->condition()->evaluate(), 'A timing-out provider fails closed.');

    // An unconfigured rule matches nobody rather than everybody.
    $this->setProvider(['*' => [self::FLAG]]);
    $this->setRequest(self::visitorCookie(5));
    $this->assertFalse($this->condition(['flag_key' => ''])->evaluate());

    // Negation applies to the resolved answer: a member of the audience is
    // excluded by a negated rule.
    $this->setProvider(['*' => [self::FLAG]]);
    $this->setRequest(self::visitorCookie(6));
    $this->assertFalse($this->condition(['negate' => TRUE])->evaluate());
    // And a non-member is included by it — including a visitor VWO could not
    // be consulted about, which is why a negated provider rule is a sharp
    // tool: a VWO outage makes it match everyone.
    $this->setProvider('throw');
    $this->setRequest(self::visitorCookie(7));
    $this->assertTrue($this->condition(['negate' => TRUE])->evaluate());
  }

  /**
   * The declared cacheability, which is what keeps caches correct.
   */
  public function testCacheability(): void {
    $condition = $this->condition();
    $this->assertSame(['cookies:_vwo_uuid_v2'], $condition->getCacheContexts());
    $this->assertSame(300, $condition->getCacheMaxAge());
    // Segment conditions MUST NOT set cache tags.
    $this->assertSame([], $condition->getCacheTags());
    $this->assertNotSame('', (string) $condition->summary());

    // The declared context follows the configured cookie name, so an account
    // on VWO's post-rebrand `_wingify_uuid_v2` still declares honestly.
    $this->config('canvas_personalization_vwo.settings')
      ->set('cookie_name', '_wingify_uuid_v2')
      ->set('membership_ttl', 900)
      ->save();
    $condition = $this->condition();
    $this->assertSame(['cookies:_wingify_uuid_v2'], $condition->getCacheContexts());
    $this->assertSame(900, $condition->getCacheMaxAge());

    // Cacheability must be derivable from configuration alone: a request that
    // carries no cookie declares exactly what one carrying a cookie does.
    $this->setProvider(['*' => [self::FLAG]]);
    $this->setRequest(NULL);
    $this->assertSame(['cookies:_wingify_uuid_v2'], $this->condition()->getCacheContexts());
    $this->setRequest(self::visitorCookie(8), '_wingify_uuid_v2');
    $this->assertSame(['cookies:_wingify_uuid_v2'], $this->condition()->getCacheContexts());
  }

  /**
   * A segment built on the condition behaves through the evaluator.
   */
  public function testThroughSegmentEvaluator(): void {
    Segment::create([
      'id' => 'vwo_halloween',
      'label' => 'VWO Halloween audience',
      'status' => TRUE,
      'rules' => [
        VwoAudience::PLUGIN_ID => [
          'id' => VwoAudience::PLUGIN_ID,
          'negate' => FALSE,
          'flag_key' => self::FLAG,
        ],
      ],
    ])->save();

    $evaluator = $this->container->get(SegmentEvaluator::class);
    \assert($evaluator instanceof SegmentEvaluator);

    $this->setProvider(['*' => [self::FLAG]]);
    $this->setRequest(self::visitorCookie(8));
    $match = $evaluator->evaluate('vwo_halloween');
    $this->assertTrue($match->matched);
    $this->assertSame(['cookies:_vwo_uuid_v2'], $match->cacheability->getCacheContexts());
    $this->assertSame(300, $match->cacheability->getCacheMaxAge());
    $this->assertSame(['config:canvas_personalization.segment.vwo_halloween'], $match->cacheability->getCacheTags());

    // A dead provider still yields the full declared cacheability, so the
    // page reverts to the personalized variant on its own once VWO recovers.
    $this->setProvider('throw');
    $this->setRequest(self::visitorCookie(9));
    $match = $evaluator->evaluate('vwo_halloween');
    $this->assertFalse($match->matched);
    $this->assertSame(['cookies:_vwo_uuid_v2'], $match->cacheability->getCacheContexts());
    $this->assertSame(300, $match->cacheability->getCacheMaxAge());

    // The declared cacheability is derived from configuration, so it holds on
    // a request where nothing evaluated at all — which is what the page-cache
    // integration reads on a dynamic_page_cache hit.
    $declared = $evaluator->getDeclaredCacheability(['vwo_halloween']);
    $this->assertSame(['cookies:_vwo_uuid_v2'], $declared->getCacheContexts());
    $this->assertSame(300, $declared->getCacheMaxAge());
  }

}
