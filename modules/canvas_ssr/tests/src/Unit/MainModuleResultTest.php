<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_ssr\Unit;

use Drupal\canvas_ssr\ComponentInfo\MainModuleResult;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests reading what an invoke of a main module returned.
 *
 * RendererTest and RealmSetupTest cover real results end to end. This pins
 * down how each is read.
 */
#[CoversClass(MainModuleResult::class)]
#[Group('canvas_ssr')]
final class MainModuleResultTest extends UnitTestCase {

  /**
   * Tests each kind of result is read as what it is.
   *
   * @param string $json
   *   What the invoke returned.
   * @param array{string|null, string|null, string|null} $expected
   *   The markup, the error and the hydration data.
   */
  #[DataProvider('providerResults')]
  public function testResultIsRead(string $json, array $expected): void {
    $result = MainModuleResult::fromInvoke($json);

    self::assertSame($expected, [$result->html, $result->error, $result->hydrationData]);
  }

  /**
   * Provides results and how they're read.
   *
   * @return array<string, array{string, array{string|null, string|null, string|null}}>
   *   What the invoke returned, and the markup, error and hydration data.
   */
  public static function providerResults(): array {
    return [
      'markup' => ['{"html":"<p>hi</p>"}', ['<p>hi</p>', NULL, NULL]],
      'no markup' => ['{"html":""}', ['', NULL, NULL]],
      // Not a marker, as a prefix would have been.
      'markup that looks like an error' => ['{"html":"SSR_ERROR: x"}', ['SSR_ERROR: x', NULL, NULL]],
      'a throw' => ['{"error":"boom"}', [NULL, 'boom', NULL]],
      'markup with hydration data' => ['{"html":"<p>hi</p>","hydrationData":"{\\"swr\\":1}"}', ['<p>hi</p>', NULL, '{"swr":1}']],
      'hydration data that is not a string' => ['{"html":"<p>hi</p>","hydrationData":7}', ['<p>hi</p>', NULL, NULL]],
    ];
  }

  /**
   * Tests a result that isn't the main module's JSON is a failed render.
   *
   * @param string $json
   *   What the invoke returned.
   */
  #[DataProvider('providerMalformedResults')]
  public function testMalformedResultCountsAsFailedRender(string $json): void {
    $result = MainModuleResult::fromInvoke($json);

    self::assertNull($result->html);
    self::assertNotNull($result->error);
  }

  /**
   * Provides results that aren't the main module's JSON.
   *
   * @return array<string, array{string}>
   *   What the invoke returned, keyed by what's wrong with it.
   */
  public static function providerMalformedResults(): array {
    return [
      'not JSON' => ['<p>hi</p>'],
      'empty' => [''],
      'not an object' => ['"<p>hi</p>"'],
      'neither markup nor an error' => ['{}'],
      'markup that is not a string' => ['{"html":7}'],
      'nested too deep' => ['{"html":{"a":"b"}}'],
    ];
  }

}
