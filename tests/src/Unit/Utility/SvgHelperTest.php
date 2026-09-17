<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Unit\Utility;

// cspell:ignore viewbox

use Drupal\canvas\Utility\SvgHelper;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

#[CoversClass(SvgHelper::class)]
#[Group('canvas')]
class SvgHelperTest extends UnitTestCase {

  /**
   * @param string $svg
   *   The contents of an SVG file.
   * @param array{width: int, height: int}|null $expected
   *   The expected intrinsic dimensions.
   */
  #[DataProvider('providerGetIntrinsicDimensions')]
  public function testGetIntrinsicDimensions(string $svg, ?array $expected): void {
    self::assertSame($expected, SvgHelper::getIntrinsicDimensions($svg));
  }

  /**
   * @return \Generator<string, array{string, array{width: int, height: int}|null}>
   */
  public static function providerGetIntrinsicDimensions(): \Generator {
    $svg = static fn (string $attributes): string => \sprintf('<svg xmlns="http://www.w3.org/2000/svg" %s><circle cx="5" cy="5" r="4"/></svg>', $attributes);

    // 1. The `width` and `height` attributes, when both are in pixels.
    yield 'unitless width and height' => [$svg('width="100" height="50"'), ['width' => 100, 'height' => 50]];
    yield 'width and height in pixels' => [$svg('width="100px" height="50px"'), ['width' => 100, 'height' => 50]];
    yield 'fractional width and height are rounded' => [$svg('width="99.5" height="49.4"'), ['width' => 100, 'height' => 49]];
    yield 'width and height take precedence over viewBox' => [$svg('width="100" height="50" viewBox="0 0 24 24"'), ['width' => 100, 'height' => 50]];

    // … but not when they are expressed in any other unit, because that conveys
    // no intrinsic size.
    yield 'width and height in percentages, without viewBox' => [$svg('width="100%" height="100%"'), NULL];
    yield 'width and height in percentages, with viewBox' => [$svg('width="100%" height="100%" viewBox="0 0 24 24"'), ['width' => 24, 'height' => 24]];
    yield 'only a width' => [$svg('width="100" viewBox="0 0 24 24"'), ['width' => 24, 'height' => 24]];
    yield 'zero width and height' => [$svg('width="0" height="0" viewBox="0 0 24 24"'), ['width' => 24, 'height' => 24]];

    // 2. The width and height in the `viewBox` attribute: its third and fourth
    // value. The first two are its origin, which must not be subtracted.
    yield 'viewBox' => [$svg('viewBox="0 0 24 24"'), ['width' => 24, 'height' => 24]];
    yield 'viewBox with a non-zero origin' => [$svg('viewBox="10 20 24 24"'), ['width' => 24, 'height' => 24]];
    yield 'viewBox separated by commas and spaces' => [$svg('viewBox="0, 0, 755, 826"'), ['width' => 755, 'height' => 826]];
    yield 'viewBox with floats' => [$svg('viewBox="0 0 24.6 24.2"'), ['width' => 25, 'height' => 24]];
    yield 'viewBox in lowercase' => [$svg('viewbox="0 0 24 24"'), ['width' => 24, 'height' => 24]];
    yield 'viewBox with too few values' => [$svg('viewBox="0 0 24"'), NULL];
    yield 'viewBox with non-numeric values' => [$svg('viewBox="0 0 auto auto"'), NULL];

    // 3. Neither.
    yield 'no dimensions at all' => [$svg(''), NULL];
    yield 'malformed XML' => ['<svg width="100" height="50">', NULL];
    yield 'empty string' => ['', NULL];
  }

}
