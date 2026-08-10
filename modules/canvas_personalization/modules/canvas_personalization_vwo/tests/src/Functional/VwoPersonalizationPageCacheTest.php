<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_personalization_vwo\Functional;

use Drupal\canvas_personalization\Entity\Segment;
use Drupal\canvas_personalization_vwo\Plugin\SegmentCondition\VwoAudience;
use Drupal\canvas_personalization_vwo_test\StubVwoAudienceResolver;
use Drupal\Core\Extension\ModuleInstallerInterface;
use Drupal\FunctionalTests\Core\Recipe\RecipeTestTrait;
use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests a page personalized on a VWO audience, end to end.
 *
 * This is the coverage that matters for an operator: which variant a real
 * anonymous request gets, and what the two anonymous cache layers do with it.
 * VWO itself is stubbed; the page, the negotiation, the response policy and
 * the cache layers are all real.
 */
#[Group('canvas')]
#[Group('canvas_personalization')]
final class VwoPersonalizationPageCacheTest extends BrowserTestBase {

  use RecipeTestTrait;

  private const string PAGE_PATH = '/personalization-test';
  private const string DEFAULT_HEADING = 'Best bikes in the market';
  private const string PERSONALIZED_HEADING = 'Halloween season is here';
  private const string COOKIE = '_vwo_uuid_v2';
  private const string FLAG = 'halloween_audience';

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'page_cache',
    'dynamic_page_cache',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->applyRecipe(\dirname(__DIR__, 5) . '/../../tests/fixtures/recipes/test_site_personalization');
    // Installed after the recipe, so the recipe's config import runs against
    // the same module set the shipped personalization tests use.
    \Drupal::service(ModuleInstallerInterface::class)->install(['canvas_personalization_vwo', 'canvas_personalization_vwo_test']);
    $this->rebuildContainer();
    $this->config('system.performance')->set('cache.page.max_age', 3600)->save();

    // Re-target the demo page's "halloween" segment at a VWO audience.
    $segment = Segment::load('halloween');
    \assert($segment instanceof Segment);
    $segment->set('rules', [
      VwoAudience::PLUGIN_ID => [
        'id' => VwoAudience::PLUGIN_ID,
        'negate' => FALSE,
        'flag_key' => self::FLAG,
      ],
    ])->save();
  }

  private static function visitorUuid(int $visitor): string {
    return 'D' . \strtoupper(\str_pad(\dechex($visitor), 32, '0', \STR_PAD_LEFT));
  }

  private static function visitorCookie(int $visitor): string {
    return self::visitorUuid($visitor) . '|fa64c2dbf8455463770fd5d2edc77faf';
  }

  /**
   * @param array<string, list<string>>|string $behavior
   *   Flag keys per visitor UUID ('*' for every visitor), or 'throw'.
   */
  private function setProvider(array|string $behavior): void {
    $this->container->get('state')->set(StubVwoAudienceResolver::BEHAVIOR_KEY, $behavior);
  }

  private function assertVariant(string $expected_heading, ?string $expected_page_cache, ?string $expected_dynamic_cache): void {
    $this->drupalGet(self::PAGE_PATH);
    $this->assertSession()->elementTextContains('css', 'h1.my-hero__heading', $expected_heading);
    if ($expected_page_cache !== NULL) {
      $this->assertSame($expected_page_cache, $this->getSession()->getResponseHeader('X-Drupal-Cache'));
    }
    if ($expected_dynamic_cache !== NULL) {
      $this->assertSame($expected_dynamic_cache, $this->getSession()->getResponseHeader('X-Drupal-Dynamic-Cache'));
    }
  }

  private function becomeVisitor(int $visitor): void {
    $this->getSession()->setCookie(self::COOKIE, self::visitorCookie($visitor));
  }

  public function testVwoPersonalizedPage(): void {
    // VWO places visitor 1 in the audience, and nobody else.
    $this->setProvider([self::visitorUuid(1) => [self::FLAG]]);
    $this->becomeVisitor(1);
    // The response is DENYed from the URL-keyed internal page cache, because
    // the condition declares a cookie context the page cache cannot see —
    // and stays cacheable in dynamic_page_cache, which keys on that context.
    $this->assertVariant(self::PERSONALIZED_HEADING, 'UNCACHEABLE (response policy)', 'MISS');
    $this->assertVariant(self::PERSONALIZED_HEADING, 'UNCACHEABLE (response policy)', 'HIT');

    // The leak assertion: a different visitor, whom VWO does not place in the
    // audience, requests the SAME URL and gets the default variant. Nothing
    // in either cache can hand them the first visitor's variant.
    $this->becomeVisitor(2);
    $this->assertVariant(self::DEFAULT_HEADING, 'UNCACHEABLE (response policy)', 'MISS');
    $this->assertVariant(self::DEFAULT_HEADING, 'UNCACHEABLE (response policy)', 'HIT');

    // A visitor with no VWO cookie at all gets the default variant.
    $this->getSession()->setCookie(self::COOKIE, NULL);
    $this->assertVariant(self::DEFAULT_HEADING, 'UNCACHEABLE (response policy)', NULL);

    // VWO down: the page renders, with the default variant, and no error.
    $this->setProvider('throw');
    $this->becomeVisitor(3);
    $this->assertVariant(self::DEFAULT_HEADING, 'UNCACHEABLE (response policy)', NULL);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextNotContains('The website encountered an unexpected error');

    // A page personalized on VWO ships no client-side personalization: the
    // right variant is in the first HTML response, and the losing variant's
    // markup is not in the page at all.
    $this->assertSession()->pageTextNotContains(self::PERSONALIZED_HEADING);

    // Publishing a change to the segment invalidates the cached variants
    // through the segment's config cache tag.
    $this->setProvider([self::visitorUuid(1) => [self::FLAG]]);
    $this->becomeVisitor(1);
    $this->assertVariant(self::PERSONALIZED_HEADING, NULL, 'HIT');
    $segment = Segment::load('halloween');
    \assert($segment instanceof Segment);
    $segment->set('status', FALSE)->save();
    $this->assertVariant(self::DEFAULT_HEADING, NULL, 'MISS');
  }

}
