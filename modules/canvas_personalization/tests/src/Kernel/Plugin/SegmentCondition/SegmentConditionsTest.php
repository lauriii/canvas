<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_personalization\Kernel\Plugin\SegmentCondition;

use Drupal\canvas_personalization\Plugin\SegmentCondition\DayOfWeek;
use Drupal\canvas_personalization\Plugin\SegmentCondition\Geolocation;
use Drupal\canvas_personalization\Plugin\SegmentCondition\QueryParameter;
use Drupal\canvas_personalization\Plugin\SegmentCondition\UtmParameters;
use Drupal\canvas_personalization\SegmentCondition\SegmentConditionInterface;
use Drupal\canvas_personalization\SegmentCondition\SegmentConditionManager;
use Drupal\Core\Cache\Cache;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;

/**
 * Tests the evaluation and cacheability of all shipped segment conditions.
 */
#[Group('canvas')]
#[Group('canvas_personalization')]
final class SegmentConditionsTest extends CanvasKernelTestBase {

  protected static $modules = [
    'canvas_personalization',
    // Loosens the ComponentSource allowlist so the module's p13n components
    // can be installed.
    // @see https://www.drupal.org/i/3520484
    'canvas_dev_mode',
  ];

  /**
   * Tests every condition's evaluation result and cacheability metadata.
   *
   * All cases run in one test method on purpose: a kernel boot per data set
   * would make this matrix take tens of minutes for no isolation benefit —
   * the only per-case state is the fabricated request.
   */
  public function testEvaluationAndCacheability(): void {
    $this->installConfig(['canvas_personalization']);
    foreach (self::providerConditions() as $label => $case) {
      [$plugin_id, $configuration, $request_context, $expected_match, $expected_contexts] = $case;
      $expected_max_age = \array_key_exists(5, $case) ? $case[5] : Cache::PERMANENT;

      $request = new Request($request_context['query'] ?? []);
      foreach ($request_context['headers'] ?? [] as $name => $value) {
        $request->headers->set($name, $value);
      }
      $request->setSession(new Session());
      $this->container->set('request_stack', new RequestStack([$request]));

      $condition = $this->container->get(SegmentConditionManager::class)
        ->createInstance($plugin_id, $configuration);
      \assert($condition instanceof SegmentConditionInterface);

      $this->assertSame($expected_match, $condition->evaluate(), $label);
      $this->assertSame($expected_contexts, $condition->getCacheContexts(), $label);
      // Segment conditions MUST NOT set cache tags.
      $this->assertSame([], $condition->getCacheTags(), $label);
      if ($expected_max_age !== NULL) {
        $this->assertSame($expected_max_age, $condition->getCacheMaxAge(), $label);
      }
      else {
        // Time-dependent max-age: bounded by a day, and always positive.
        $this->assertGreaterThan(0, $condition->getCacheMaxAge(), $label);
        $this->assertLessThanOrEqual(86400, $condition->getCacheMaxAge(), $label);
      }
      $this->assertNotSame('', (string) $condition->summary(), $label);
    }
  }

  public static function providerConditions(): \Generator {
    // query_parameter.
    yield 'query parameter: exact match' => [
      QueryParameter::PLUGIN_ID,
      ['parameter' => 'coupon', 'value' => 'BLACKFRIDAY', 'matching' => 'exact'],
      ['query' => ['coupon' => 'BLACKFRIDAY']],
      TRUE,
      ['url.query_args:coupon'],
    ];
    yield 'query parameter: exact mismatch' => [
      QueryParameter::PLUGIN_ID,
      ['parameter' => 'coupon', 'value' => 'BLACKFRIDAY', 'matching' => 'exact'],
      ['query' => ['coupon' => 'CHRISTMAS']],
      FALSE,
      ['url.query_args:coupon'],
    ];
    yield 'query parameter: absent parameter never matches, even empty expected value' => [
      QueryParameter::PLUGIN_ID,
      ['parameter' => 'coupon', 'value' => '', 'matching' => 'exact'],
      [],
      FALSE,
      ['url.query_args:coupon'],
    ];
    yield 'query parameter: starts_with' => [
      QueryParameter::PLUGIN_ID,
      ['parameter' => 'coupon', 'value' => 'BLACK', 'matching' => 'starts_with'],
      ['query' => ['coupon' => 'BLACKFRIDAY']],
      TRUE,
      ['url.query_args:coupon'],
    ];
    yield 'query parameter: present' => [
      QueryParameter::PLUGIN_ID,
      ['parameter' => 'coupon', 'value' => '', 'matching' => 'present'],
      ['query' => ['coupon' => 'anything']],
      TRUE,
      ['url.query_args:coupon'],
    ];
    yield 'query parameter: negated match' => [
      QueryParameter::PLUGIN_ID,
      ['parameter' => 'coupon', 'value' => 'BLACKFRIDAY', 'matching' => 'exact', 'negate' => TRUE],
      ['query' => ['coupon' => 'BLACKFRIDAY']],
      FALSE,
      ['url.query_args:coupon'],
    ];

    // utm_parameters.
    $utm_config = [
      'parameters' => [
        ['key' => UtmParameters::UTM_CAMPAIGN, 'value' => 'HALLOWEEN', 'matching' => 'exact'],
        ['key' => UtmParameters::UTM_SOURCE, 'value' => 'news', 'matching' => 'starts_with'],
      ],
    ];
    yield 'utm: all match' => [
      UtmParameters::PLUGIN_ID,
      $utm_config + ['all' => TRUE],
      ['query' => ['utm_campaign' => 'HALLOWEEN', 'utm_source' => 'newsletter']],
      TRUE,
      ['url.query_args:utm_campaign', 'url.query_args:utm_source'],
    ];
    yield 'utm: all required, one missing' => [
      UtmParameters::PLUGIN_ID,
      $utm_config + ['all' => TRUE],
      ['query' => ['utm_campaign' => 'HALLOWEEN']],
      FALSE,
      ['url.query_args:utm_campaign', 'url.query_args:utm_source'],
    ];
    yield 'utm: any, one matches' => [
      UtmParameters::PLUGIN_ID,
      $utm_config + ['all' => FALSE],
      ['query' => ['utm_campaign' => 'HALLOWEEN']],
      TRUE,
      ['url.query_args:utm_campaign', 'url.query_args:utm_source'],
    ];
    yield 'utm: no parameters configured matches anything' => [
      UtmParameters::PLUGIN_ID,
      ['parameters' => [], 'all' => TRUE],
      [],
      TRUE,
      [],
    ];

    // geolocation. The header names come from canvas_personalization.settings.
    yield 'geolocation: country match' => [
      Geolocation::PLUGIN_ID,
      ['countries' => ['BE', 'NL']],
      ['headers' => ['X-Country-Code' => 'be']],
      TRUE,
      ['headers:X-Country-Code'],
    ];
    yield 'geolocation: country mismatch' => [
      Geolocation::PLUGIN_ID,
      ['countries' => ['BE', 'NL']],
      ['headers' => ['X-Country-Code' => 'US']],
      FALSE,
      ['headers:X-Country-Code'],
    ];
    yield 'geolocation: absent header fails closed' => [
      Geolocation::PLUGIN_ID,
      ['countries' => ['BE', 'NL']],
      [],
      FALSE,
      ['headers:X-Country-Code'],
    ];
    yield 'geolocation: country and region must both match' => [
      Geolocation::PLUGIN_ID,
      ['countries' => ['US'], 'regions' => ['CO', 'MA']],
      ['headers' => ['X-Country-Code' => 'US', 'X-Region-Code' => 'CO']],
      TRUE,
      ['headers:X-Country-Code', 'headers:X-Region-Code'],
    ];
    yield 'geolocation: region mismatch' => [
      Geolocation::PLUGIN_ID,
      ['countries' => ['US'], 'regions' => ['CO', 'MA']],
      ['headers' => ['X-Country-Code' => 'US', 'X-Region-Code' => 'NY']],
      FALSE,
      ['headers:X-Country-Code', 'headers:X-Region-Code'],
    ];
    yield 'geolocation: negated mismatch matches' => [
      Geolocation::PLUGIN_ID,
      ['countries' => ['BE'], 'negate' => TRUE],
      ['headers' => ['X-Country-Code' => 'US']],
      TRUE,
      ['headers:X-Country-Code'],
    ];

    // day_of_week: today in UTC (the test site default timezone is UTC via
    // CanvasKernelTestBase); expected max-age is time-dependent (NULL).
    $today = \strtolower((new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('l'));
    $tomorrow = \strtolower((new \DateTimeImmutable('tomorrow', new \DateTimeZone('UTC')))->format('l'));
    yield 'day of week: today matches' => [
      DayOfWeek::PLUGIN_ID,
      ['days' => [$today]],
      [],
      TRUE,
      [],
      NULL,
    ];
    yield 'day of week: tomorrow does not match' => [
      DayOfWeek::PLUGIN_ID,
      ['days' => [$tomorrow]],
      [],
      FALSE,
      [],
      NULL,
    ];
  }

  /**
   * Tests that all four conditions are discovered.
   */
  public function testDiscovery(): void {
    $definitions = $this->container->get(SegmentConditionManager::class)->getDefinitions();
    $this->assertEqualsCanonicalizing(
      [DayOfWeek::PLUGIN_ID, Geolocation::PLUGIN_ID, QueryParameter::PLUGIN_ID, UtmParameters::PLUGIN_ID],
      \array_keys($definitions),
    );
  }

}
