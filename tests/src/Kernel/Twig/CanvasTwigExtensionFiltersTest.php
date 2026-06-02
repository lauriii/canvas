<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\Twig;

// cspell:ignore itok

use Drupal\canvas\Routing\ParametrizedImageStyleConverter;
use Drupal\canvas\Twig\CanvasTwigExtension;
use Drupal\Core\Image\ImageFactory;
use Drupal\Core\Image\ImageInterface;
use Drupal\Core\StreamWrapper\StreamWrapperManagerInterface;
use Drupal\file\FileInterface;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\canvas\Kernel\Traits\PredictableImageStyleItokTestTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests Twig filter functionality.
 *
 * @legacy-covers \Drupal\canvas\Twig\CanvasTwigExtension::toSrcSet
 */
#[RunTestsInSeparateProcesses]
#[Group('canvas')]
class CanvasTwigExtensionFiltersTest extends CanvasKernelTestBase {

  use PredictableImageStyleItokTestTrait;

  /**
   * @var \Drupal\canvas\Twig\CanvasTwigExtension
   */
  private CanvasTwigExtension $canvasTwigExtension;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->setupPredictableItok();

    // Mock File entity.
    $file = $this->createMock(FileInterface::class);
    $file->method('getFileUri')->willReturn('public://balloons.png');
    $file->method('id')->willReturn('123');

    // Mock Image.
    $image = $this->createMock(ImageInterface::class);
    $image->method('getWidth')->willReturn(640);
    $image->method('getHeight')->willReturn(427);
    $image->method('isValid')->willReturn(TRUE);

    // Configure mocks.
    $imageFactory = $this->createMock(ImageFactory::class);
    $imageFactory->method('get')->with('public://balloons.png')->willReturn($image);
    $streamWrapperManager = $this->createMock(StreamWrapperManagerInterface::class);
    $streamWrapperManager->method('isValidUri')->willReturn(TRUE);
    $fileUrlGenerator = $this->container->get('file_url_generator');
    $renderer = $this->container->get('renderer');

    // Create the extension instance
    $this->canvasTwigExtension = new CanvasTwigExtension($streamWrapperManager, $imageFactory, $fileUrlGenerator, $renderer);
    $test_base_url = 'http://localhost/sites/default/files';
    $this->setSetting('file_public_base_url', $test_base_url);
  }

  /**
 * Tests to src set.
 */
  #[DataProvider('providerToSrcSet')]
  public function testToSrcSet(string $src, ?int $intrinsicImageWidth, ?string $expected): void {
    $actual = $this->canvasTwigExtension->toSrcSet($src, $intrinsicImageWidth);
    $this->assertSame($expected, $actual);
  }

  /**
   * Data provider for testToSrcSet.
   */
  public static function providerToSrcSet(): \Generator {
    $actual_width = 640;
    $expect_all_srcset_widths = self::generateExpectedSrcSet(
      self::getWidthsIncludingNextLarger($actual_width)
    );

    yield 'public stream wrapper image' => [
      'public://balloons.png',
      $actual_width,
      $expect_all_srcset_widths,
    ];

    yield 'public stream wrapper image, no given width — should inspect image to fetch actual width' => [
      'public://balloons.png',
      NULL,
      $expect_all_srcset_widths,
    ];

    yield 'public stream wrapper image, provided width is bigger than actual width' => [
      'public://balloons.png',
      1024,
      $expect_all_srcset_widths,
    ];

    yield 'public stream wrapper image, provided width is smaller than actual width' => [
      'public://balloons.png',
      200,
      self::generateExpectedSrcSet(
        self::getWidthsIncludingNextLarger(200)
      ),
    ];
  }

  /**
   * Gets allowed widths up to the target width, plus the next larger width.
   */
  private static function getWidthsIncludingNextLarger(int $target_width): array {
    $widths = [];
    foreach (ParametrizedImageStyleConverter::ALLOWED_WIDTHS as $allowed_width) {
      $widths[] = $allowed_width;
      if ($allowed_width > $target_width) {
        break;
      }
    }
    return $widths;
  }

  /**
   * Generate expected srcset for balloons.png.
   */
  private static function generateExpectedSrcSet(array $widths): string {
    return implode(', ', \array_map(
      fn ($width) => "/sites/default/files/styles/canvas_parametrized_width--$width/public/balloons.png.avif?itok=TeB392qG {$width}w",
      $widths
    ));
  }

}
