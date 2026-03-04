<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\Twig;

// cspell:ignore itok MIOT

use Drupal\canvas\Routing\ParametrizedImageStyleConverter;
use Drupal\canvas\Twig\CanvasTwigExtension;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Image\ImageFactory;
use Drupal\Core\Image\ImageInterface;
use Drupal\Core\StreamWrapper\PublicStream;
use Drupal\Core\StreamWrapper\StreamWrapperManagerInterface;
use Drupal\file\FileInterface;
use Drupal\image\Entity\ImageStyle;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\canvas\Kernel\Traits\PredictableImageStyleItokTestTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests Twig filter functionality.
 *
 * @group canvas
 */
#[RunTestsInSeparateProcesses]
#[Group('canvas')]
#[CoversClass(CanvasTwigExtension::class)]
#[CoversMethod(CanvasTwigExtension::class, 'toSrcSet')]
#[CoversMethod(CanvasTwigExtension::class, 'applyImageStyle')]
#[CoversMethod(CanvasTwigExtension::class, 'urlToStreamWrapperUri')]
class CanvasTwigExtensionFiltersTest extends CanvasKernelTestBase {

  use PredictableImageStyleItokTestTrait;

  /**
   * The Canvas Twig extension under test.
   *
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
    $fileUrlGenerator = $this->container->get(FileUrlGeneratorInterface::class);
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
      array_filter(ParametrizedImageStyleConverter::ALLOWED_WIDTHS, fn($w) => $w <= $actual_width)
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
        array_filter(ParametrizedImageStyleConverter::ALLOWED_WIDTHS, fn($w) => $w <= 200)
      ),
    ];
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

  /**
   * Tests the apply_image_style filter with stream wrapper URI.
   */
  public function testApplyImageStyleWithStreamWrapperUri(): void {
    // Create a test image style with a resize effect.
    $style = ImageStyle::create([
      'name' => 'test_style',
      'label' => 'Test Style',
    ]);
    $style->addImageEffect([
      'id' => 'image_scale',
      'data' => ['width' => 200, 'height' => NULL, 'upscale' => FALSE],
      'weight' => 0,
    ]);
    $style->save();

    $extension = $this->container->get(CanvasTwigExtension::class);
    \assert($extension instanceof CanvasTwigExtension);

    $image = [
      'src' => 'public://test-image.jpg',
      'width' => 800,
      'height' => 600,
      'alt' => 'Test image',
    ];
    $result = $extension->applyImageStyle($image, 'test_style');

    $this->assertIsArray($result);
    // Should return a URL for the styled derivative.
    $this->assertStringContainsString('/styles/test_style/', $result['src']);
    $this->assertStringContainsString('test-image.jpg', $result['src']);
    // Dimensions should be transformed (scaled to width 200).
    $this->assertEquals(200, $result['width']);
    $this->assertEquals(150, $result['height']);
    // Alt should be preserved.
    $this->assertEquals('Test image', $result['alt']);
  }

  /**
   * Tests that public file URLs with encoded path segments are not double-encoded.
   *
   * When a URL contains encoded characters (e.g. %20 for space), the stream
   * wrapper URI must use the decoded path so that styled URLs are generated
   * with a single encoding (e.g. %20), not double-encoded (e.g. %2520).
   */
  public function testApplyImageStyleWithEncodedCharactersInPath(): void {
    ImageStyle::create([
      'name' => 'test_style',
      'label' => 'Test Style',
    ])->save();

    $extension = $this->container->get(CanvasTwigExtension::class);
    \assert($extension instanceof CanvasTwigExtension);

    $publicBasePath = PublicStream::basePath();
    // URL with encoded space (%20) in filename, e.g. "MIOT U-6_4.png".
    $image = [
      'src' => '/' . $publicBasePath . '/litters/MIOT%20U-6_4.png',
      'width' => 400,
      'height' => 300,
    ];
    $result = $extension->applyImageStyle($image, 'test_style');

    $this->assertIsArray($result);
    $this->assertStringContainsString('/styles/test_style/', $result['src']);
    // Styled URL must use single encoding (%20) so the browser requests the correct path.
    $this->assertStringContainsString('MIOT%20U-6_4.png', $result['src']);
    // Must not be double-encoded (%2520), which would break image loading.
    $this->assertStringNotContainsString('%2520', $result['src']);
  }

  /**
   * Tests the apply_image_style filter with local file URL.
   */
  public function testApplyImageStyleWithLocalFileUrl(): void {
    ImageStyle::create([
      'name' => 'test_style',
      'label' => 'Test Style',
    ])->save();

    $extension = $this->container->get(CanvasTwigExtension::class);
    \assert($extension instanceof CanvasTwigExtension);

    // Test with local file URL matching the actual public files path.
    $publicBasePath = PublicStream::basePath();
    $image = [
      'src' => '/' . $publicBasePath . '/test-image.jpg',
      'width' => 400,
      'height' => 300,
    ];
    $result = $extension->applyImageStyle($image, 'test_style');

    $this->assertIsArray($result);
    // Should return a URL for the styled derivative.
    $this->assertStringContainsString('/styles/test_style/', $result['src']);
    $this->assertStringContainsString('test-image.jpg', $result['src']);
  }

  /**
   * Tests the apply_image_style filter with local file URL in subdirectory.
   */
  public function testApplyImageStyleWithLocalFileUrlInSubdirectory(): void {
    ImageStyle::create([
      'name' => 'test_style',
      'label' => 'Test Style',
    ])->save();

    $extension = $this->container->get(CanvasTwigExtension::class);
    \assert($extension instanceof CanvasTwigExtension);

    $publicBasePath = PublicStream::basePath();
    $subdirectoryBaseUrl = '/subdirectory';
    $request = Request::create(
      $subdirectoryBaseUrl . '/node/1',
      'GET',
      [],
      [],
      [],
      [
        'SCRIPT_FILENAME' => '/var/www/html' . $subdirectoryBaseUrl . '/index.php',
        'SCRIPT_NAME' => $subdirectoryBaseUrl . '/index.php',
        'PHP_SELF' => $subdirectoryBaseUrl . '/index.php',
      ],
    );
    $requestStack = $this->container->get('request_stack');
    $requestStack->push($request);

    try {
      $image = [
        'src' => $subdirectoryBaseUrl . '/' . $publicBasePath . '/test-image.jpg',
        'width' => 400,
        'height' => 300,
      ];
      $result = $extension->applyImageStyle($image, 'test_style');
    }
    finally {
      $requestStack->pop();
    }

    $this->assertIsArray($result);
    // Should return a URL for the styled derivative.
    $this->assertStringContainsString('/styles/test_style/', $result['src']);
    $this->assertStringContainsString('test-image.jpg', $result['src']);
  }

  /**
   * Tests prefixed paths outside base path are not treated as public files.
   */
  public function testApplyImageStyleWithUnexpectedPrefixedPublicPath(): void {
    ImageStyle::create([
      'name' => 'test_style',
      'label' => 'Test Style',
    ])->save();

    $extension = $this->container->get(CanvasTwigExtension::class);
    \assert($extension instanceof CanvasTwigExtension);

    $publicBasePath = PublicStream::basePath();
    $subdirectoryBaseUrl = '/subdirectory';
    $request = Request::create(
      $subdirectoryBaseUrl . '/node/1',
      'GET',
      [],
      [],
      [],
      [
        'SCRIPT_FILENAME' => '/var/www/html' . $subdirectoryBaseUrl . '/index.php',
        'SCRIPT_NAME' => $subdirectoryBaseUrl . '/index.php',
        'PHP_SELF' => $subdirectoryBaseUrl . '/index.php',
      ],
    );
    $requestStack = $this->container->get('request_stack');
    $requestStack->push($request);

    try {
      $image = [
        'src' => '/other/' . $publicBasePath . '/test-image.jpg',
        'width' => 400,
        'height' => 300,
      ];
      $result = $extension->applyImageStyle($image, 'test_style');
    }
    finally {
      $requestStack->pop();
    }

    // Paths outside the detected base path should remain unchanged.
    $this->assertIsArray($result);
    $this->assertSame('/other/' . $publicBasePath . '/test-image.jpg', $result['src']);
    $this->assertSame(400, $result['width']);
    $this->assertSame(300, $result['height']);
  }

  /**
   * Tests the apply_image_style filter with external URL returns unchanged.
   */
  public function testApplyImageStyleWithExternalUrl(): void {
    ImageStyle::create([
      'name' => 'test_style',
      'label' => 'Test Style',
    ])->save();

    $extension = $this->container->get(CanvasTwigExtension::class);
    \assert($extension instanceof CanvasTwigExtension);

    $externalUrl = 'https://example.com/image.jpg';
    $image = [
      'src' => $externalUrl,
      'width' => 400,
      'height' => 300,
    ];
    $result = $extension->applyImageStyle($image, 'test_style');

    // External URLs should be returned unchanged.
    $this->assertIsArray($result);
    $this->assertSame($externalUrl, $result['src']);
    $this->assertSame(400, $result['width']);
    $this->assertSame(300, $result['height']);
  }

  /**
   * Tests the apply_image_style filter with local file outside public path.
   */
  public function testApplyImageStyleWithLocalFileOutsidePublicPath(): void {
    ImageStyle::create([
      'name' => 'test_style',
      'label' => 'Test Style',
    ])->save();

    $extension = $this->container->get(CanvasTwigExtension::class);
    \assert($extension instanceof CanvasTwigExtension);

    // Test with a local file URL that is NOT inside PublicStream::basePath().
    $image = [
      'src' => '/var/www/other/image.jpg',
      'width' => 400,
      'height' => 300,
    ];
    $result = $extension->applyImageStyle($image, 'test_style');

    // Should return unchanged since the file is outside the public files path.
    $this->assertIsArray($result);
    $this->assertSame('/var/www/other/image.jpg', $result['src']);
    $this->assertSame(400, $result['width']);
    $this->assertSame(300, $result['height']);
  }

  /**
   * Tests the apply_image_style filter with invalid style name.
   */
  public function testApplyImageStyleWithInvalidStyleName(): void {
    $extension = $this->container->get(CanvasTwigExtension::class);
    \assert($extension instanceof CanvasTwigExtension);

    $image = [
      'src' => 'public://test-image.jpg',
      'width' => 400,
      'height' => 300,
    ];
    $result = $extension->applyImageStyle($image, 'nonexistent_style');

    // With invalid style, the original image should be returned unchanged.
    $this->assertIsArray($result);
    $this->assertSame('public://test-image.jpg', $result['src']);
    $this->assertSame(400, $result['width']);
    $this->assertSame(300, $result['height']);
  }

}
