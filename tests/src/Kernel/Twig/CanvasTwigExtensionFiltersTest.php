<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\Twig;

// cspell:ignore itok MIOT

use Drupal\canvas\Routing\ParametrizedImageStyleConverter;
use Drupal\canvas\Twig\CanvasTwigExtension;
use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Extension\ExtensionPathResolver;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\StreamWrapper\PublicStream;
use Drupal\Core\Template\TwigEnvironment;
use Drupal\image\Entity\ImageStyle;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\canvas\Kernel\Traits\PredictableImageStyleItokTestTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * Tests Twig filter functionality.
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
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->setupPredictableItok();
    $this->installBalloonsFixture();
    $test_base_url = 'http://localhost/sites/default/files';
    $this->setSetting('file_public_base_url', $test_base_url);
  }

  /**
   * Copies a real 640×427 image to public://balloons.png for Image API use.
   */
  private function installBalloonsFixture(): void {
    $file_system = $this->container->get('file_system');
    \assert($file_system instanceof FileSystemInterface);
    $extension_path_resolver = $this->container->get('extension.path.resolver');
    \assert($extension_path_resolver instanceof ExtensionPathResolver);
    $module_path = $extension_path_resolver->getPath('module', 'canvas_test_sdc');
    $source = $module_path . '/components/card/balloons.png';
    $public_directory = 'public://';
    $file_system->prepareDirectory($public_directory, FileSystemInterface::CREATE_DIRECTORY);
    $file_system->copy($source, 'public://balloons.png', FileExists::Replace);
  }

  /**
   * Pushes a request whose base path is a subdirectory (e.g. `/subdirectory`).
   */
  private function pushSubdirectorySiteRequest(string $subdirectoryBaseUrl = '/subdirectory'): void {
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
    $request->setSession(new Session(new MockArraySessionStorage()));
    $this->container->get('request_stack')->push($request);
  }

  /**
   * Renders inline Twig via Drupal (registered extensions, production filter wiring).
   */
  private function renderDrupalInlineTemplate(string $template, array $context = []): string {
    $twig = $this->container->get('twig');
    \assert($twig instanceof TwigEnvironment);
    return (string) $twig->renderInline($template, $context);
  }

  /**
   * Renders the built-in canvas:image SDC (components/image/image.twig).
   *
   * @param array<string, mixed> $props
   *   Props for the component (at minimum `src`; optional `width`, `height`,
   *   `alt`, `sizes`, `loading`).
   */
  private function renderCanvasImageComponent(array $props): string {
    $build = [
      '#type' => 'inline_template',
      '#template' => "{{ include('canvas:image', props, with_context = false) }}",
      '#context' => ['props' => $props],
    ];
    return trim((string) $this->container->get('renderer')->renderRoot($build));
  }

  /**
   * Asserts the first img tag in HTML has the expected srcset attribute value.
   *
   * @param string $caseLabel
   *   When non-empty, prefixed onto failure messages (e.g. loop iteration id).
   */
  private function assertImgSrcsetEquals(string $html, string $expectedSrcset, string $caseLabel = ''): void {
    $prefix = $caseLabel !== '' ? $caseLabel . ': ' : '';
    $this->assertSame(1, preg_match('/<img[^>]*\ssrcset="([^"]*)"/', $html, $matches), $prefix . 'Expected an img tag with a srcset attribute.');
    $this->assertSame($expectedSrcset, html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'), $prefix . 'srcset attribute value.');
  }

  /**
   * Covers stream-wrapper srcset behavior for the canvas:image component.
   *
   * One test method avoids data-provider overhead (multiple invocations per case).
   * Several cases share the same expected srcset (intrinsic width 640) while
   * exercising different prop paths: explicit width, omitted width, and explicit
   * width above the file dimensions (capped to 640).
   */
  public function testToSrcSet(): void {
    $actual_width = 640;
    $expect_all_srcset_widths = self::expectedBalloonsSrcsetUpTo640();
    $expect_width_200 = self::generateExpectedSrcSet(
      array_filter(ParametrizedImageStyleConverter::ALLOWED_WIDTHS, fn($w) => $w <= 200)
    );

    $cases = [
      'public stream wrapper image' => [
        'props' => [
          'src' => 'public://balloons.png',
          'width' => $actual_width,
          'alt' => '',
        ],
        'expected' => $expect_all_srcset_widths,
      ],
      'public stream wrapper image, no given width — should inspect image to fetch actual width' => [
        'props' => [
          'src' => 'public://balloons.png',
          'alt' => '',
        ],
        'expected' => $expect_all_srcset_widths,
      ],
      'public stream wrapper image, provided width is bigger than actual width' => [
        'props' => [
          'src' => 'public://balloons.png',
          'width' => 1024,
          'alt' => '',
        ],
        'expected' => $expect_all_srcset_widths,
      ],
      'public stream wrapper image, provided width is smaller than actual width' => [
        'props' => [
          'src' => 'public://balloons.png',
          'width' => 200,
          'alt' => '',
        ],
        'expected' => $expect_width_200,
      ],
    ];

    foreach ($cases as $label => $case) {
      $html = $this->renderCanvasImageComponent($case['props']);
      $this->assertImgSrcsetEquals($html, $case['expected'], $label);
    }
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
   * Expected parametrized srcset for `public://balloons.png` (640px wide fixture).
   */
  private static function expectedBalloonsSrcsetUpTo640(): string {
    return self::generateExpectedSrcSet(
      array_filter(
        ParametrizedImageStyleConverter::ALLOWED_WIDTHS,
        static fn ($w) => $w <= 640
      )
    );
  }

  /**
   * Tests toSrcSet with a root-relative public files URL (urlToStreamWrapperUri).
   */
  public function testToSrcSetWithRootRelativePublicFilesUrl(): void {
    $publicBasePath = PublicStream::basePath();
    $src = '/' . $publicBasePath . '/balloons.png';
    $expected = self::expectedBalloonsSrcsetUpTo640();
    $html = $this->renderCanvasImageComponent([
      'src' => $src,
      'width' => 640,
      'alt' => '',
    ]);
    $this->assertImgSrcsetEquals($html, $expected);
  }

  /**
   * Tests toSrcSet with a full URL including a non-default port.
   */
  public function testToSrcSetWithFullUrlIncludingNonDefaultPort(): void {
    $publicBasePath = PublicStream::basePath();
    $src = 'http://127.0.0.1:8080/' . $publicBasePath . '/balloons.png';
    $expected = self::expectedBalloonsSrcsetUpTo640();
    $html = $this->renderCanvasImageComponent([
      'src' => $src,
      'width' => 640,
      'alt' => '',
    ]);
    $this->assertImgSrcsetEquals($html, $expected);
  }

  /**
   * Tests toSrcSet with a subdirectory site base path (Request on stack).
   */
  public function testToSrcSetWithSubdirectoryPublicFilesUrl(): void {
    $publicBasePath = PublicStream::basePath();
    $subdirectoryBaseUrl = '/subdirectory';
    $this->pushSubdirectorySiteRequest($subdirectoryBaseUrl);

    $src = $subdirectoryBaseUrl . '/' . $publicBasePath . '/balloons.png';
    $expected = self::expectedBalloonsSrcsetUpTo640();
    $html = $this->renderCanvasImageComponent([
      'src' => $src,
      'width' => 640,
      'alt' => '',
    ]);
    $this->assertImgSrcsetEquals($html, $expected);
  }

  /**
   * Tests both filters on the same public-file URL (port + path integration).
   *
   * Apply_image_style and toSrcSet share urlToStreamWrapperUri(); this ensures
   * both accept the same full URL shape. toSrcSet targets the original file
   * for parametrized srcset (not a styled derivative URL from another style).
   * Exact srcset for this URL shape is asserted in
   * testToSrcSetWithFullUrlIncludingNonDefaultPort().
   */
  public function testApplyImageStyleThenToSrcSetWithFullUrlIncludingPort(): void {
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

    $publicBasePath = PublicStream::basePath();
    $originalSrc = 'http://127.0.0.1:8080/' . $publicBasePath . '/balloons.png';
    $image = [
      'src' => $originalSrc,
      'width' => 640,
      'height' => 427,
    ];
    $styledSrc = $this->renderDrupalInlineTemplate(
      "{% set r = image|apply_image_style('test_style') %}{{ r.src }}",
      ['image' => $image]
    );
    $this->assertStyledDerivativeRelativeUrl(
      '/sites/default/files/styles/test_style/public/balloons.png',
      $styledSrc,
    );

    $html = $this->renderCanvasImageComponent([
      'src' => $originalSrc,
      'width' => 640,
      'alt' => '',
    ]);
    $this->assertSame(1, preg_match('/<img[^>]*\ssrcset="([^"]*)"/', $html, $matches), 'Expected an img tag with a srcset attribute.');
    $this->assertNotSame('', html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    $this->assertStringContainsString('canvas_parametrized_width', $html);
  }

  /**
   * Asserts a root-relative styled derivative URL path and a non-empty itok.
   *
   * The exact itok value is not asserted; only its presence is required.
   */
  private function assertStyledDerivativeRelativeUrl(string $expectedPath, string $actualSrc): void {
    $this->assertStringStartsWith('/', $actualSrc, 'Expected a root-relative URL from transformRelative().');
    $parts = parse_url($actualSrc);
    $this->assertIsArray($parts);
    $this->assertSame($expectedPath, $parts['path'] ?? '');
    $this->assertArrayHasKey('query', $parts);
    parse_str($parts['query'], $query);
    $this->assertArrayHasKey('itok', $query);
    $this->assertNotSame('', $query['itok']);
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

    $image = [
      'src' => 'public://test-image.jpg',
      'width' => 800,
      'height' => 600,
      'alt' => 'Test image',
    ];
    $rendered = $this->renderDrupalInlineTemplate(
      "{% set r = image|apply_image_style('test_style') %}{{ r.src }}###{{ r.width }}###{{ r.height }}###{{ r.alt }}",
      ['image' => $image]
    );
    [$src, $width, $height, $alt] = explode('###', $rendered, 4);

    $this->assertTrue(UrlHelper::isValid($src));
    $this->assertStyledDerivativeRelativeUrl(
      '/sites/default/files/styles/test_style/public/test-image.jpg',
      $src,
    );
    // Dimensions should be transformed (scaled to width 200).
    $this->assertEquals('200', $width);
    $this->assertEquals('150', $height);
    // Alt should be preserved.
    $this->assertEquals('Test image', $alt);
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

    $publicBasePath = PublicStream::basePath();
    // URL with encoded space (%20) in filename, e.g. "MIOT U-6_4.png".
    $image = [
      'src' => '/' . $publicBasePath . '/litters/MIOT%20U-6_4.png',
      'width' => 400,
      'height' => 300,
    ];
    $src = $this->renderDrupalInlineTemplate(
      "{% set r = image|apply_image_style('test_style') %}{{ r.src }}",
      ['image' => $image]
    );

    $this->assertStyledDerivativeRelativeUrl(
      '/sites/default/files/styles/test_style/public/litters/MIOT%20U-6_4.png',
      $src,
    );
    // Styled URL must use single encoding (%20) so the browser requests the correct path.
    $this->assertStringContainsString('MIOT%20U-6_4.png', $src);
    // Must not be double-encoded (%2520), which would break image loading.
    $this->assertStringNotContainsString('%2520', $src);
  }

  /**
   * Tests the apply_image_style filter with local file URL.
   */
  public function testApplyImageStyleWithLocalFileUrl(): void {
    ImageStyle::create([
      'name' => 'test_style',
      'label' => 'Test Style',
    ])->save();

    // Test with local file URL matching the actual public files path.
    $publicBasePath = PublicStream::basePath();
    $image = [
      'src' => '/' . $publicBasePath . '/test-image.jpg',
      'width' => 400,
      'height' => 300,
    ];
    $src = $this->renderDrupalInlineTemplate(
      "{% set r = image|apply_image_style('test_style') %}{{ r.src }}",
      ['image' => $image]
    );

    $this->assertStyledDerivativeRelativeUrl(
      '/sites/default/files/styles/test_style/public/test-image.jpg',
      $src,
    );
  }

  /**
   * Tests full public file URL with a non-default port (e.g. DDEV, local HTTPS).
   */
  public function testApplyImageStyleWithPublicFilesUrlIncludingNonDefaultPort(): void {
    ImageStyle::create([
      'name' => 'test_style',
      'label' => 'Test Style',
    ])->save();

    $publicBasePath = PublicStream::basePath();
    $image = [
      'src' => 'http://127.0.0.1:8080/' . $publicBasePath . '/test-image.jpg',
      'width' => 400,
      'height' => 300,
    ];
    $src = $this->renderDrupalInlineTemplate(
      "{% set r = image|apply_image_style('test_style') %}{{ r.src }}",
      ['image' => $image]
    );

    $this->assertStyledDerivativeRelativeUrl(
      '/sites/default/files/styles/test_style/public/test-image.jpg',
      $src,
    );
  }

  /**
   * Tests the apply_image_style filter with local file URL in subdirectory.
   */
  public function testApplyImageStyleWithLocalFileUrlInSubdirectory(): void {
    ImageStyle::create([
      'name' => 'test_style',
      'label' => 'Test Style',
    ])->save();

    $publicBasePath = PublicStream::basePath();
    $subdirectoryBaseUrl = '/subdirectory';
    $this->pushSubdirectorySiteRequest($subdirectoryBaseUrl);

    $image = [
      'src' => $subdirectoryBaseUrl . '/' . $publicBasePath . '/test-image.jpg',
      'width' => 400,
      'height' => 300,
    ];
    $src = $this->renderDrupalInlineTemplate(
      "{% set r = image|apply_image_style('test_style') %}{{ r.src }}",
      ['image' => $image]
    );

    $this->assertStyledDerivativeRelativeUrl(
      '/sites/default/files/styles/test_style/public/test-image.jpg',
      $src,
    );
  }

  /**
   * Tests prefixed paths outside base path are not treated as public files.
   */
  public function testApplyImageStyleWithUnexpectedPrefixedPublicPath(): void {
    ImageStyle::create([
      'name' => 'test_style',
      'label' => 'Test Style',
    ])->save();

    $publicBasePath = PublicStream::basePath();
    $subdirectoryBaseUrl = '/subdirectory';
    $this->pushSubdirectorySiteRequest($subdirectoryBaseUrl);

    $image = [
      'src' => '/other/' . $publicBasePath . '/test-image.jpg',
      'width' => 400,
      'height' => 300,
    ];
    $rendered = $this->renderDrupalInlineTemplate(
      "{% set r = image|apply_image_style('test_style') %}{{ r.src }}###{{ r.width }}###{{ r.height }}",
      ['image' => $image]
    );
    [$src, $width, $height] = explode('###', $rendered, 3);

    // Paths outside the detected base path should remain unchanged.
    $this->assertSame('/other/' . $publicBasePath . '/test-image.jpg', $src);
    $this->assertSame('400', $width);
    $this->assertSame('300', $height);
  }

  /**
   * Tests the apply_image_style filter with external URL returns unchanged.
   */
  public function testApplyImageStyleWithExternalUrl(): void {
    ImageStyle::create([
      'name' => 'test_style',
      'label' => 'Test Style',
    ])->save();

    $externalUrl = 'https://example.com/image.jpg';
    $image = [
      'src' => $externalUrl,
      'width' => 400,
      'height' => 300,
    ];
    $rendered = $this->renderDrupalInlineTemplate(
      "{% set r = image|apply_image_style('test_style') %}{{ r.src }}###{{ r.width }}###{{ r.height }}",
      ['image' => $image]
    );
    [$src, $width, $height] = explode('###', $rendered, 3);

    // External URLs should be returned unchanged.
    $this->assertSame($externalUrl, $src);
    $this->assertSame('400', $width);
    $this->assertSame('300', $height);
  }

  /**
   * Tests the apply_image_style filter with local file outside public path.
   */
  public function testApplyImageStyleWithLocalFileOutsidePublicPath(): void {
    ImageStyle::create([
      'name' => 'test_style',
      'label' => 'Test Style',
    ])->save();

    $tempPath = tempnam(sys_get_temp_dir(), 'canvas');
    $this->assertNotFalse($tempPath);
    try {
      // Absolute filesystem path outside sites/default/files; file exists on disk.
      $image = [
        'src' => $tempPath,
        'width' => 400,
        'height' => 300,
      ];
      $rendered = $this->renderDrupalInlineTemplate(
        "{% set r = image|apply_image_style('test_style') %}{{ r.src }}###{{ r.width }}###{{ r.height }}",
        ['image' => $image]
      );
      [$src, $width, $height] = explode('###', $rendered, 3);

      $this->assertSame($tempPath, $src);
      $this->assertSame('400', $width);
      $this->assertSame('300', $height);
      $this->assertStringNotContainsString('/styles/test_style/', $src);
    }
    finally {
      @unlink($tempPath);
    }
  }

  /**
   * Tests the apply_image_style filter with invalid style name.
   */
  public function testApplyImageStyleWithInvalidStyleName(): void {
    $image = [
      'src' => 'public://test-image.jpg',
      'width' => 400,
      'height' => 300,
    ];
    $rendered = $this->renderDrupalInlineTemplate(
      "{% set r = image|apply_image_style('nonexistent_style') %}{{ r.src }}###{{ r.width }}###{{ r.height }}",
      ['image' => $image]
    );
    [$src, $width, $height] = explode('###', $rendered, 3);

    // With invalid style, the original image should be returned unchanged.
    $this->assertSame('public://test-image.jpg', $src);
    $this->assertSame('400', $width);
    $this->assertSame('300', $height);
  }

}
