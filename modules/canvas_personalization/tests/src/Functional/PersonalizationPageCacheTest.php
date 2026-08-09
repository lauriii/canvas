<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_personalization\Functional;

use Drupal\canvas_personalization\Entity\Segment;
use Drupal\FunctionalTests\Core\Recipe\RecipeTestTrait;
use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests personalized pages against the anonymous page caches.
 *
 * This is the load-bearing coverage for the feature's cacheability contract:
 * a regression here is silent in normal browsing and expensive in production
 * (wrong variants served from cache, or caching silently defeated).
 *
 * Covers all three mechanisms from docs/personalization.md §5.2:
 * 1. URL-derived conditions get per-URL internal page cache HITs.
 * 2. Time-based conditions surface their max-age as an Expires header.
 * 3. Header-based conditions are excluded from the URL-keyed internal page
 *    cache (never leaking a variant across visitors of the same URL) while
 *    remaining cacheable in dynamic_page_cache.
 */
#[Group('canvas')]
#[Group('canvas_personalization')]
final class PersonalizationPageCacheTest extends BrowserTestBase {

  use RecipeTestTrait;

  private const string PAGE_PATH = '/personalization-test';
  private const string DEFAULT_HEADING = 'Best bikes in the market';
  private const string PERSONALIZED_HEADING = 'Halloween season is here';

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
    $this->applyRecipe(\dirname(__DIR__, 3) . '/../../tests/fixtures/recipes/test_site_personalization');
    $this->config('system.performance')->set('cache.page.max_age', 3600)->save();
  }

  /**
   * Asserts the rendered variant plus both cache-layer response headers.
   */
  private function assertVariant(string $expected_heading, ?string $expected_page_cache, ?string $expected_dynamic_cache, array $headers = [], string $query = ''): void {
    $this->drupalGet(self::PAGE_PATH . $query, [], $headers);
    $this->assertSession()->elementTextContains('css', 'h1.my-hero__heading', $expected_heading);
    if ($expected_page_cache !== NULL) {
      $this->assertSame($expected_page_cache, $this->getSession()->getResponseHeader('X-Drupal-Cache'));
    }
    if ($expected_dynamic_cache !== NULL) {
      $this->assertSame($expected_dynamic_cache, $this->getSession()->getResponseHeader('X-Drupal-Dynamic-Cache'));
    }
  }

  public function testPageCacheBehavior(): void {
    // Mechanism 1: URL-derived conditions (UTM query parameter).
    // Anonymous cold requests MISS, warm requests HIT, and each URL is its
    // own page_cache entry with its own correct variant.
    $this->assertVariant(self::DEFAULT_HEADING, 'MISS', 'MISS');
    $this->assertVariant(self::DEFAULT_HEADING, 'HIT', NULL);
    $this->assertVariant(self::PERSONALIZED_HEADING, 'MISS', 'MISS', query: '?utm_campaign=HALLOWEEN');
    $this->assertVariant(self::PERSONALIZED_HEADING, 'HIT', NULL, query: '?utm_campaign=HALLOWEEN');
    // The personalized URL never polluted the default URL's entry.
    $this->assertVariant(self::DEFAULT_HEADING, 'HIT', NULL);

    // Editing a referenced segment invalidates every cached variant of the
    // page via its config cache tag.
    $halloween = Segment::load('halloween');
    \assert($halloween instanceof Segment);
    $rules = $halloween->get('rules');
    $rules['utm_parameters']['parameters'][0]['value'] = 'SPOOKY';
    $halloween->set('rules', $rules)->save();
    $this->assertVariant(self::DEFAULT_HEADING, 'MISS', NULL, query: '?utm_campaign=HALLOWEEN');
    $this->assertVariant(self::PERSONALIZED_HEADING, 'MISS', NULL, query: '?utm_campaign=SPOOKY');

    // Mechanism 2: a time-based condition bounds the page_cache entry's
    // lifetime via the Expires header (the internal page cache ignores
    // max-age; expiry comes only from Expires).
    // Compute "today" in the timezone the site under test evaluates in.
    $site_timezone = $this->config('system.date')->get('timezone.default') ?: 'UTC';
    $today = \strtolower((new \DateTimeImmutable('now', new \DateTimeZone($site_timezone)))->format('l'));
    $halloween->set('rules', [
      'day_of_week' => [
        'id' => 'day_of_week',
        'negate' => FALSE,
        'days' => [$today],
      ],
    ])->save();
    $this->assertVariant(self::PERSONALIZED_HEADING, 'MISS', 'MISS');
    $expires = $this->getSession()->getResponseHeader('Expires');
    $this->assertNotNull($expires);
    $expires_in = \strtotime($expires) - \time();
    $this->assertGreaterThan(0, $expires_in);
    $this->assertLessThanOrEqual(86400, $expires_in);
    $this->assertVariant(self::PERSONALIZED_HEADING, 'HIT', NULL);

    // Mechanism 3: a header-based condition (geolocation) must never leak a
    // variant through the URL-keyed page cache — those responses are denied
    // internal page caching and served, still cached, by dynamic_page_cache
    // keyed on the country header.
    $halloween->set('rules', [
      'geolocation' => [
        'id' => 'geolocation',
        'negate' => FALSE,
        'countries' => ['BE'],
        'regions' => [],
      ],
    ])->save();
    $this->assertVariant(self::PERSONALIZED_HEADING, 'UNCACHEABLE (response policy)', 'MISS', headers: ['X-Country-Code' => 'BE']);
    // A dynamic_page_cache HIT must STILL be excluded from the URL-keyed
    // page cache: no evaluation runs on this request, so the exclusion has
    // to derive from the response itself. (An implementation keyed on
    // "evaluation ran" lets this request poison the page cache.)
    $this->assertVariant(self::PERSONALIZED_HEADING, 'UNCACHEABLE (response policy)', 'HIT', headers: ['X-Country-Code' => 'BE']);
    // The crucial leak assertion: a visitor from another country requesting
    // the SAME URL gets the default variant, not the cached BE variant.
    $this->assertVariant(self::DEFAULT_HEADING, 'UNCACHEABLE (response policy)', 'MISS', headers: ['X-Country-Code' => 'US']);
    $this->assertVariant(self::DEFAULT_HEADING, 'UNCACHEABLE (response policy)', 'HIT', headers: ['X-Country-Code' => 'US']);
    // No geolocation header at all: fail closed to the default variant.
    $this->assertVariant(self::DEFAULT_HEADING, 'UNCACHEABLE (response policy)', NULL);
  }

}
