<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Unit\Twig;

use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Image\ImageFactory;
use Drupal\Core\StreamWrapper\StreamWrapperManagerInterface;
use Drupal\experience_builder\Extension\XbTwigExtension;
use Drupal\experience_builder\Routing\ParametrizedImageStyleConverter;
use Drupal\Tests\UnitTestCase;

// cspell:ignore fitok itok Bwidth

/**
 * Tests Twig filter functionality.
 *
 * @group experience_builder
 * @covers \Drupal\experience_builder\Extension\XbTwigExtension::toSrcSet
 */
class XbTwigExtensionFiltersTest extends UnitTestCase {

  /**
   * @var \Drupal\experience_builder\Extension\XbTwigExtension
   */
  private XbTwigExtension $xbTwigExtension;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Mock the required dependencies
    $streamWrapperManager = $this->createMock(StreamWrapperManagerInterface::class);
    $imageFactory = $this->createMock(ImageFactory::class);
    $fileUrlInterfaceManager = $this->createMock(FileUrlGeneratorInterface::class);

    // Create the extension instance
    $this->xbTwigExtension = new XbTwigExtension($streamWrapperManager, $imageFactory, $fileUrlInterfaceManager);
  }

  /**
   * @covers XbTwigExtension::toSrcSet
   * @dataProvider providerToSrcSet
   */
  public function testToSrcSet(string $src, int $intrinsicImageWidth, ?string $expected): void {
    $actual = $this->xbTwigExtension->toSrcSet($src, $intrinsicImageWidth);
    $this->assertSame($expected, $actual);
  }

  /**
   * Data provider for testToSrcSet.
   */
  public static function providerToSrcSet(): \Generator {
    yield 'simple image without query' => [
      '/simple-image.png',
      max(ParametrizedImageStyleConverter::ALLOWED_WIDTHS),
      NULL,
    ];

    yield 'simple image with fragments' => [
      '/simple-image.png#fragment',
      max(ParametrizedImageStyleConverter::ALLOWED_WIDTHS),
      NULL,
    ];

    yield 'image with non-alternateWidths query' => [
      '/simple-image.png?foo=bar&itok=asdf&baz=lol',
      max(ParametrizedImageStyleConverter::ALLOWED_WIDTHS),
      NULL,
    ];

    yield 'complex image with alternateWidths with the image *exactly* the maximum allowed width — generate even the equally wide src because it may get converted to a more optimized format such as AVIF' => [
      '/sites/default/files/2025-07/Screenshot%202025-07-08%20at%208.56.02.png?alternateWidths=/sites/default/files/styles/xb_parametrized_width--%7Bwidth%7D/public/2025-07/Screenshot%25202025-07-08%2520at%25208.56.02.png.webp%3Fitok%3DWp4lG4Wk#fragment',
      max(ParametrizedImageStyleConverter::ALLOWED_WIDTHS),
      self::generateExpectedSrcSetForWidths(ParametrizedImageStyleConverter::ALLOWED_WIDTHS),
    ];

    yield 'complex image with alternateWidths with the image *slightly smaller* than the maximum allowed width' => [
      '/sites/default/files/2025-07/Screenshot%202025-07-08%20at%208.56.02.png?alternateWidths=/sites/default/files/styles/xb_parametrized_width--%7Bwidth%7D/public/2025-07/Screenshot%25202025-07-08%2520at%25208.56.02.png.webp%3Fitok%3DWp4lG4Wk#fragment',
      max(ParametrizedImageStyleConverter::ALLOWED_WIDTHS) - 1,
      self::generateExpectedSrcSetForWidths(array_slice(ParametrizedImageStyleConverter::ALLOWED_WIDTHS, 0, -1)),
    ];

    yield 'complex image with alternateWidths with the image *slightly bigger* than the maximum allowed width' => [
      '/sites/default/files/2025-07/Screenshot%202025-07-08%20at%208.56.02.png?alternateWidths=/sites/default/files/styles/xb_parametrized_width--%7Bwidth%7D/public/2025-07/Screenshot%25202025-07-08%2520at%25208.56.02.png.webp%3Fitok%3DWp4lG4Wk#fragment',
      max(ParametrizedImageStyleConverter::ALLOWED_WIDTHS) + 1,
      self::generateExpectedSrcSetForWidths(ParametrizedImageStyleConverter::ALLOWED_WIDTHS),
    ];

    yield 'complex image with alternateWidths and defined intrinsic image width' => [
      '/sites/default/files/2025-07/Screenshot%202025-07-08%20at%208.56.02.png?alternateWidths=/sites/default/files/styles/xb_parametrized_width--%7Bwidth%7D/public/2025-07/Screenshot%25202025-07-08%2520at%25208.56.02.png.webp%3Fitok%3DWp4lG4Wk#fragment',
      // Simulate a real image width.
      1245,
      // Do not generate higher resolutions than 1200.
      // Same ParametrizedImageStyleConverter::ALLOWED_WIDTHS array but lower
      // values than 1245, the image's real width.
      self::generateExpectedSrcSetForWidths([16, 32, 48, 64, 96, 128, 256, 384, 640, 750, 828, 1080, 1200]),
    ];

    // @todo Add test cases in https://drupal.org/i/3533563 that test a customized set of allowed widths
    // @phpcs:disable
    /*
    yield 'custom widths test' => [
      '/sites/default/files/2025-07/Screenshot%202025-07-08%20at%208.56.02.png?alternateWidths=/sites/default/files/styles/xb_parametrized_width--%7Bwidth%7D/public/2025-07/Screenshot%25202025-07-08%2520at%25208.56.02.png.webp%3Fitok%3DWp4lG4Wk#and-thats-a-fragment',
      max(ParametrizedImageStyleConverter::ALLOWED_WIDTHS),
      self::generateExpectedSrcSetForWidths([400, 800]),
    ];

    yield 'custom widths with complex url' => [
      '/sites/default/files/2025-07/Screenshot%202025-07-08%20at%208.56.02.png?alternateWidths=/sites/default/files/styles/xb_parametrized_width--%7Bwidth%7D/public/2025-07/Screenshot%25202025-07-08%2520at%25208.56.02.png.webp%3Fitok%3DWp4lG4Wk&sort=recent&filter=status:active,owner:me&page=2#view=grid&selected=project-456&panel=details',
      max(ParametrizedImageStyleConverter::ALLOWED_WIDTHS),
      self::generateExpectedSrcSetForWidths([400, 800]),
    ];
    */
    // @phpcs:enable
  }

  private static function generateExpectedSrcSetForWidths(array $widths): string {
    return implode(', ', array_map(function ($width) {
      return "/sites/default/files/styles/xb_parametrized_width--$width/public/2025-07/Screenshot 2025-07-08 at 8.56.02.png.webp?itok=Wp4lG4Wk {$width}w";
    }, $widths));
  }

  /**
   * Test invalid URLs.
   *
   * @param string $src
   *   An image URL.
   * @param int $intrinsicImageWidth
   *   The intrinsic width of the image in $src.
   * @param class-string<\Throwable> $expectedException
   *   The expected exception.
   *
   * @covers XbTwigExtension::toSrcSet
   * @dataProvider invalidProviderToSrcSet
   */
  public function testToSrcSetWithInvalidWidth(string $src, int $intrinsicImageWidth, string $expectedException): void {
    $this->expectException($expectedException);
    $this->xbTwigExtension->toSrcSet($src, $intrinsicImageWidth);
  }

  public static function invalidProviderToSrcSet(): \Generator {
    yield 'simple image with wrongly formatted alternateWidths query' => [
      '/simple-image.png?alternateWidths=something',
      max(ParametrizedImageStyleConverter::ALLOWED_WIDTHS),
      \AssertionError::class,
    ];

    yield 'simple image with two alternateWidths query params' => [
      '/simple-image.png?alternateWidths[]=/sites/default/files/styles/xb_parametrized_width--%7Bwidth%7D/public/2025-07/image.png.webp%3Fitok%3DWp4lG4Wk#and-thats-a-fragment&alternateWidths[]=/sites/default/files/styles/xb_parametrized_width--%7Bwidth%7D/public/2025-07/image.png.webp%3Fitok%3DWp4lG4Wk#and-thats-a-fragment',
      max(ParametrizedImageStyleConverter::ALLOWED_WIDTHS),
      \AssertionError::class,
    ];
  }

}
