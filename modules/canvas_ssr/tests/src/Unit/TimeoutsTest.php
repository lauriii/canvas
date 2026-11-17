<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_ssr\Unit;

use Drupal\canvas_ssr\Timeouts;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests SSR's time limits.
 */
#[CoversClass(Timeouts::class)]
#[Group('canvas_ssr')]
final class TimeoutsTest extends UnitTestCase {

  /**
   * Tests limits left out get their defaults.
   */
  public function testLimitsLeftOutGetTheirDefaults(): void {
    $timeouts = Timeouts::fromArray(['medium' => 3000]);

    self::assertSame([100, 3000, 10000], [$timeouts->short, $timeouts->medium, $timeouts->long]);
  }

  /**
   * Tests the CPU time limit is half again the long limit, rounded up.
   */
  public function testCpuLimitIsHalfAgainTheLongLimit(): void {
    self::assertSame(15, Timeouts::fromArray([])->cpuLimitSeconds());
    self::assertSame(2, Timeouts::fromArray(['medium' => 500, 'long' => 1000])->cpuLimitSeconds());
  }

  /**
   * Returns limits that aren't accepted.
   *
   * @return array<string, array{array<mixed>}>
   *   The limits, keyed by what's wrong with them.
   */
  public static function invalidTimeouts(): array {
    return [
      'an unknown name' => [['quick' => 100]],
      'zero' => [['short' => 0]],
      'a float' => [['long' => 1.5]],
      'medium under twice short' => [['short' => 1001]],
      'long under twice medium' => [['medium' => 5001]],
    ];
  }

  /**
   * Tests limits that aren't accepted throw.
   *
   * That's an unknown name, a limit that isn't a positive integer, or a
   * limit under twice the one before it.
   *
   * @param array<mixed> $timeouts
   *   The limits.
   */
  #[DataProvider('invalidTimeouts')]
  public function testInvalidLimitsThrow(array $timeouts): void {
    $this->expectException(\InvalidArgumentException::class);
    Timeouts::fromArray($timeouts);
  }

}
