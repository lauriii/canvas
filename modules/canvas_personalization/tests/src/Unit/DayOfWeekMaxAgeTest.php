<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_personalization\Unit;

use Drupal\canvas_personalization\Plugin\SegmentCondition\DayOfWeek;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the day-of-week condition's max-age computation.
 */
#[Group('canvas')]
#[Group('canvas_personalization')]
final class DayOfWeekMaxAgeTest extends UnitTestCase {

  #[DataProvider('providerMaxAge')]
  public function testSecondsUntilNextMidnight(string $now, string $timezone, int $expected): void {
    $this->assertSame(
      $expected,
      DayOfWeek::secondsUntilNextMidnight(new \DateTimeImmutable($now, new \DateTimeZone($timezone))),
    );
  }

  public static function providerMaxAge(): \Generator {
    yield 'mid-day UTC' => ['2026-08-05 12:00:00', 'UTC', 12 * 3600];
    yield 'one second before midnight' => ['2026-08-05 23:59:59', 'UTC', 1];
    yield 'exactly midnight yields a full day' => ['2026-08-05 00:00:00', 'UTC', 24 * 3600];
    yield 'non-UTC timezone' => ['2026-08-05 22:00:00', 'Europe/Helsinki', 2 * 3600];
    // Spring-forward DST day in Helsinki (2026-03-29, clocks jump 03:00 →
    // 04:00): from 01:00 to the next midnight is 23h on the wall clock but
    // only 22h in real seconds.
    yield 'DST spring-forward day' => ['2026-03-29 01:00:00', 'Europe/Helsinki', 22 * 3600];
  }

}
