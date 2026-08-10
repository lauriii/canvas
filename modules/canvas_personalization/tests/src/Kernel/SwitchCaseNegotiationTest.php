<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_personalization\Kernel;

use Drupal\canvas\ComponentSource\ComponentSourceWithSwitchCasesInterface;
use Drupal\canvas\ComponentSource\SwitchCaseNegotiation;
use Drupal\canvas\Entity\Component;
use Drupal\canvas_personalization\Entity\Segment;
use Drupal\canvas_personalization_test\Plugin\SegmentCondition\TestUnreachableProvider;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;

/**
 * Tests switch/case negotiation: priority order, fallback, cacheability.
 */
#[Group('canvas')]
#[Group('canvas_personalization')]
final class SwitchCaseNegotiationTest extends CanvasKernelTestBase {

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
    $coupon_rule = static fn (string $value): array => [
      'query_parameter' => [
        'id' => 'query_parameter',
        'negate' => FALSE,
        'parameter' => 'coupon',
        'value' => $value,
        'matching' => 'exact',
      ],
    ];
    Segment::create(['id' => 'seg_a', 'label' => 'A', 'status' => TRUE, 'rules' => $coupon_rule('A')])->save();
    Segment::create(['id' => 'seg_b', 'label' => 'B', 'status' => TRUE, 'rules' => $coupon_rule('B')])->save();
    // seg_any matches whenever the coupon parameter is present at all.
    Segment::create([
      'id' => 'seg_any',
      'label' => 'Any coupon',
      'status' => TRUE,
      'rules' => [
        'query_parameter' => [
          'id' => 'query_parameter',
          'negate' => FALSE,
          'parameter' => 'coupon',
          'value' => '',
          'matching' => 'present',
        ],
      ],
    ])->save();
    // Stands in for a segment backed by a third-party segmentation provider:
    // declares a cookie context and a bounded TTL, and costs something to
    // consult.
    Segment::create([
      'id' => 'seg_provider',
      'label' => 'Provider',
      'status' => TRUE,
      'rules' => [
        'test_unreachable_provider' => [
          'id' => TestUnreachableProvider::PLUGIN_ID,
          'negate' => FALSE,
        ],
      ],
    ])->save();
  }

  private function setRequest(array $query = []): void {
    $request = new Request($query);
    $request->setSession(new Session());
    $request_stack = $this->container->get(RequestStack::class);
    \assert($request_stack instanceof RequestStack);
    $request_stack->push($request);
  }

  /**
   * Builds a hydrated switch component instance array.
   *
   * @param list<string> $variants
   *   The priority-ordered variant IDs.
   * @param array<string, array{segments: list<string>, disabled?: bool}> $cases
   *   Cases keyed by variant ID.
   */
  private static function buildSwitchInstance(array $variants, array $cases): array {
    $children = [];
    foreach ($cases as $variant_id => $case) {
      $children['uuid-case-' . $variant_id] = [
        'component' => 'p13n.case',
        'variant_id' => $variant_id,
        'segments' => $case['segments'],
        'slots' => ['content' => ''],
      ] + (\array_key_exists('disabled', $case) ? ['disabled' => $case['disabled']] : []);
    }
    return [
      'component' => 'p13n.switch',
      'variants' => $variants,
      'slots' => ['content' => $children],
    ];
  }

  private static function negotiate(array $switch_instance): SwitchCaseNegotiation {
    $component = Component::load('p13n.switch');
    \assert($component instanceof Component);
    $source = $component->getComponentSource();
    \assert($source instanceof ComponentSourceWithSwitchCasesInterface);
    return $source->negotiateCases($switch_instance);
  }

  public function testFirstMatchInPriorityOrderWins(): void {
    $switch = self::buildSwitchInstance(
      ['a', 'any', 'default'],
      [
        'a' => ['segments' => ['seg_a']],
        'any' => ['segments' => ['seg_any']],
        'default' => ['segments' => [Segment::DEFAULT_ID]],
      ],
    );

    // Both seg_a and seg_any match ?coupon=A; the higher-priority variant
    // wins.
    $this->setRequest(['coupon' => 'A']);
    $this->assertSame('uuid-case-a', self::negotiate($switch)->negotiatedCaseUuid);

    // Only seg_any matches ?coupon=B... but a fresh request is needed since
    // evaluation memoizes per request.
    $this->setRequest(['coupon' => 'B']);
    $this->assertSame('uuid-case-any', self::negotiate($switch)->negotiatedCaseUuid);

    // Nothing matches without a coupon: the default variant is the fallback.
    $this->setRequest();
    $this->assertSame('uuid-case-default', self::negotiate($switch)->negotiatedCaseUuid);
  }

  public function testDisabledCaseIsSkipped(): void {
    $switch = self::buildSwitchInstance(
      ['a', 'default'],
      [
        'a' => ['segments' => ['seg_a'], 'disabled' => TRUE],
        'default' => ['segments' => [Segment::DEFAULT_ID]],
      ],
    );
    $this->setRequest(['coupon' => 'A']);
    $this->assertSame('uuid-case-default', self::negotiate($switch)->negotiatedCaseUuid);
  }

  public function testMultiSegmentCaseRequiresAllSegmentsToMatch(): void {
    $switch = self::buildSwitchInstance(
      ['both', 'default'],
      [
        'both' => ['segments' => ['seg_a', 'seg_any']],
        'default' => ['segments' => [Segment::DEFAULT_ID]],
      ],
    );
    // seg_any matches ?coupon=B, seg_a does not: the variant loses.
    $this->setRequest(['coupon' => 'B']);
    $this->assertSame('uuid-case-default', self::negotiate($switch)->negotiatedCaseUuid);
    // Both match ?coupon=A.
    $this->setRequest(['coupon' => 'A']);
    $this->assertSame('uuid-case-both', self::negotiate($switch)->negotiatedCaseUuid);
  }

  public function testNoMatchAndNoDefaultRendersNothing(): void {
    $switch = self::buildSwitchInstance(
      ['a'],
      [
        'a' => ['segments' => ['seg_a']],
      ],
    );
    $this->setRequest();
    $negotiation = self::negotiate($switch);
    $this->assertNull($negotiation->negotiatedCaseUuid);
    // Cacheability still covers the decision.
    $this->assertSame(['url.query_args:coupon'], $negotiation->cacheability->getCacheContexts());
  }

  public function testCacheabilityCoversEveryReferencedSegment(): void {
    $switch = self::buildSwitchInstance(
      ['a', 'missing', 'default'],
      [
        'a' => ['segments' => ['seg_a']],
        'missing' => ['segments' => ['not_created_yet']],
        'default' => ['segments' => [Segment::DEFAULT_ID]],
      ],
    );
    // The first variant matches — yet the metadata must cover the losing and
    // missing segments too, because a different request context changes the
    // outcome, and creating the missing segment must invalidate the page.
    $this->setRequest(['coupon' => 'A']);
    $negotiation = self::negotiate($switch);
    $this->assertSame('uuid-case-a', $negotiation->negotiatedCaseUuid);
    $this->assertSame(['url.query_args:coupon'], $negotiation->cacheability->getCacheContexts());
    $this->assertEqualsCanonicalizing([
      'config:canvas_personalization.segment.seg_a',
      'config:canvas_personalization.segment.not_created_yet',
      'config:canvas_personalization.segment.default',
    ], $negotiation->cacheability->getCacheTags());
  }

  /**
   * A losing variant's segments shape cacheability without being consulted.
   *
   * Metadata is declared from configuration, so the segments of variants after
   * the winner never have to be evaluated — which is what keeps a third-party
   * segmentation provider out of the render path of every page that merely
   * offers a lower-priority variant it backs.
   */
  public function testVariantsAfterTheWinnerAreNotEvaluated(): void {
    $switch = self::buildSwitchInstance(
      ['a', 'provider', 'default'],
      [
        'a' => ['segments' => ['seg_a']],
        'provider' => ['segments' => ['seg_provider']],
        'default' => ['segments' => [Segment::DEFAULT_ID]],
      ],
    );
    $this->setRequest(['coupon' => 'A']);
    TestUnreachableProvider::$evaluations = 0;
    $negotiation = self::negotiate($switch);

    $this->assertSame('uuid-case-a', $negotiation->negotiatedCaseUuid);
    $this->assertSame(0, TestUnreachableProvider::$evaluations);
    $this->assertEqualsCanonicalizing(['url.query_args:coupon', 'cookies:canvas_test_provider'], $negotiation->cacheability->getCacheContexts());
    $this->assertSame(300, $negotiation->cacheability->getCacheMaxAge());
  }

}
