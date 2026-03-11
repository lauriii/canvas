<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\Config;

// cspell:ignore Brien obrien
use Drupal\Core\Asset\LibraryDiscoveryInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\canvas\Entity\BrandKit;
use Drupal\file\Entity\File;
use Drupal\file\FileInterface;
use Drupal\file\FileUsage\FileUsageInterface;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the fonts property on Brand Kit.
 *
 * @legacy-covers \Drupal\canvas\Entity\BrandKit::getFonts
 * @legacy-covers \Drupal\canvas\Entity\BrandKit::setFonts
 * @legacy-covers \Drupal\canvas\Hook\LibraryHooks::libraryInfoBuild
 */
#[RunTestsInSeparateProcesses]
#[Group('canvas')]
final class AssetLibraryFontsTest extends CanvasKernelTestBase {

  private function createFontFile(string $filename = 'test-font.woff2'): string {
    return BrandKit::ARTIFACTS_DIRECTORY . $filename;
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('file');
    $this->installSchema('file', 'file_usage');
    $this->config('file.settings')
      ->set('make_unused_managed_files_temporary', TRUE)
      ->save();
  }

  private function createManagedFontFile(string $filename = 'test-font.woff2'): FileInterface {
    $uri = $this->createFontFile($filename);
    $file_system = \Drupal::service('file_system');
    \assert($file_system instanceof FileSystemInterface);
    $directory = BrandKit::ARTIFACTS_DIRECTORY;
    self::assertTrue($file_system->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS));
    $realpath = $file_system->realpath($uri);
    self::assertIsString($realpath);
    self::assertNotFalse(file_put_contents($realpath, 'font-data'));

    $file = File::create(['uri' => $uri]);
    $file->save();

    return $file;
  }

  public function testFontsDefaultToEmpty(): void {
    $entity = BrandKit::load(BrandKit::GLOBAL_ID);
    self::assertNotNull($entity);
    self::assertSame([], $entity->getFonts());
  }

  public function testFontsSaveAndLoad(): void {
    $file = $this->createManagedFontFile('inter.woff2');
    $font_uri = $file->getFileUri();
    \assert(\is_string($font_uri));
    $entries = [
      [
        'id' => '00000000-0000-4000-8000-000000000001',
        'family' => 'Inter',
        'uri' => $font_uri,
        'format' => 'woff2',
        'weight' => '400',
        'style' => 'normal',
        'axes' => NULL,
      ],
    ];

    $entity = BrandKit::load(BrandKit::GLOBAL_ID);
    self::assertNotNull($entity);

    $entity->setFonts($entries);
    $entity->save();

    $reloaded = BrandKit::load(BrandKit::GLOBAL_ID);
    self::assertNotNull($reloaded);
    self::assertSame($entries, $reloaded->getFonts());
    self::assertCount(1, $reloaded->getFonts());
  }

  public function testFontsCanBeCleared(): void {
    $entity = BrandKit::load(BrandKit::GLOBAL_ID);
    self::assertNotNull($entity);

    $file = $this->createManagedFontFile('clear-test.woff2');
    $font_uri = $file->getFileUri();
    \assert(\is_string($font_uri));
    $entity->setFonts([
      [
        'id' => '00000000-0000-4000-8000-000000000001',
        'family' => 'Inter',
        'uri' => $font_uri,
        'format' => 'woff2',
        'weight' => '400',
        'style' => 'normal',
      ],
    ]);
    $entity->save();
    $reloaded = BrandKit::load(BrandKit::GLOBAL_ID);
    self::assertNotNull($reloaded);
    self::assertNotEmpty($reloaded->getFonts());

    $entity->setFonts(NULL);
    $entity->save();

    $reloaded = BrandKit::load(BrandKit::GLOBAL_ID);
    self::assertNotNull($reloaded);
    self::assertSame([], $reloaded->getFonts());
  }

  public function testSavingFontsTracksFileUsage(): void {
    $file = $this->createManagedFontFile('tracked.woff2');
    $file_uri = $file->getFileUri();
    \assert(\is_string($file_uri));

    $entity = BrandKit::load(BrandKit::GLOBAL_ID);
    self::assertNotNull($entity);
    $entity->setFonts([
      [
        'id' => '00000000-0000-4000-8000-000000000005',
        'family' => 'Tracked',
        'uri' => $file_uri,
        'format' => 'woff2',
        'weight' => '400',
        'style' => 'normal',
      ],
    ]);
    $entity->save();

    $tracked_file = File::load($file->id());
    self::assertInstanceOf(FileInterface::class, $tracked_file);
    self::assertFalse($tracked_file->isTemporary());
    $file_usage = \Drupal::service('file.usage');
    \assert($file_usage instanceof FileUsageInterface);
    self::assertSame([
      'canvas' => [
        BrandKit::FILE_USAGE_TYPE => [
          BrandKit::GLOBAL_ID => '1',
        ],
      ],
    ], $file_usage->listUsage($tracked_file));

    $entity->setFonts(NULL);
    $entity->save();

    $tracked_file = File::load($file->id());
    self::assertInstanceOf(FileInterface::class, $tracked_file);
    self::assertTrue($tracked_file->isTemporary());
    self::assertSame([], $file_usage->listUsage($tracked_file));
  }

  public function testDeletingBrandKitClearsFileUsage(): void {
    $file = $this->createManagedFontFile('delete-tracked.woff2');
    $file_uri = $file->getFileUri();
    \assert(\is_string($file_uri));

    $entity = BrandKit::load(BrandKit::GLOBAL_ID);
    self::assertNotNull($entity);
    $entity->setFonts([
      [
        'id' => '00000000-0000-4000-8000-000000000006',
        'family' => 'Tracked Delete',
        'uri' => $file_uri,
        'format' => 'woff2',
        'weight' => '400',
        'style' => 'normal',
      ],
    ]);
    $entity->save();

    $file_usage = \Drupal::service('file.usage');
    \assert($file_usage instanceof FileUsageInterface);
    $tracked_file = File::load($file->id());
    self::assertInstanceOf(FileInterface::class, $tracked_file);
    self::assertSame([
      'canvas' => [
        BrandKit::FILE_USAGE_TYPE => [
          BrandKit::GLOBAL_ID => '1',
        ],
      ],
    ], $file_usage->listUsage($tracked_file));

    $entity->delete();

    $tracked_file = File::load($file->id());
    self::assertInstanceOf(FileInterface::class, $tracked_file);
    self::assertTrue($tracked_file->isTemporary());
    self::assertSame([], $file_usage->listUsage($tracked_file));
  }

  public function testFontsInClientSideNormalization(): void {
    $font_uri = $this->createFontFile('normalize.woff2');
    $fonts = [
      [
        'id' => '00000000-0000-4000-8000-000000000001',
        'family' => 'Inter',
        'uri' => $font_uri,
        'format' => 'woff2',
        'weight' => '400',
        'style' => 'normal',
      ],
    ];

    $entity = BrandKit::load(BrandKit::GLOBAL_ID);
    self::assertNotNull($entity);

    $entity->setFonts($fonts);
    $clientSide = $entity->normalizeForClientSide();

    self::assertArrayHasKey('fonts', $clientSide->values);
    self::assertSame($fonts[0]['family'], $clientSide->values['fonts'][0]['family']);
    self::assertSame($fonts[0]['uri'], $clientSide->values['fonts'][0]['uri']);
    self::assertArrayHasKey('url', $clientSide->values['fonts'][0]);
  }

  public function testFontsInClientSideNormalizationWhenNull(): void {
    $entity = BrandKit::load(BrandKit::GLOBAL_ID);
    self::assertNotNull($entity);

    $clientSide = $entity->normalizeForClientSide();
    self::assertArrayHasKey('fonts', $clientSide->values);
    self::assertNull($clientSide->values['fonts']);
  }

  public function testGetCssIncludesGeneratedFontFaceRules(): void {
    $entity = BrandKit::load(BrandKit::GLOBAL_ID);
    self::assertNotNull($entity);

    $entity->set('css', [
      'original' => '',
      'compiled' => '.example { font-family: \'Inter\', sans-serif; }',
    ]);
    $entity->setFonts([
      [
        'id' => '00000000-0000-4000-8000-000000000001',
        'family' => 'Inter',
        'uri' => $this->createFontFile('generated-face.woff2'),
        'format' => 'woff2',
        'weight' => '400',
        'style' => 'normal',
      ],
    ]);

    self::assertStringContainsString("@font-face {\n  font-family: 'Inter';", $entity->getCss());
    self::assertStringContainsString("format('woff2');", $entity->getCss());
    self::assertStringContainsString(".example { font-family: 'Inter', sans-serif; }", $entity->getCss());
  }

  public function testFontFamilyWithSingleQuoteIsEscapedInGeneratedCss(): void {
    $entity = BrandKit::load(BrandKit::GLOBAL_ID);
    self::assertNotNull($entity);

    $entity->set('css', ['original' => '', 'compiled' => '']);
    $entity->setFonts([
      [
        'id' => '00000000-0000-4000-8000-000000000007',
        'family' => "O'Brien",
        'uri' => $this->createFontFile('obrien.woff2'),
        'format' => 'woff2',
        'weight' => '400',
        'style' => 'normal',
      ],
    ]);

    $css = $entity->getCss();
    self::assertStringContainsString("font-family: 'O\\'Brien';", $css, 'Single quote in font family must be escaped in generated @font-face CSS.');
  }

  public function testFontsNoLongerAppearInLibraryInfoBuild(): void {
    $entity = BrandKit::load(BrandKit::GLOBAL_ID);
    self::assertNotNull($entity);

    $file = $this->createManagedFontFile('library-build.woff2');
    $font_uri = $file->getFileUri();
    \assert(\is_string($font_uri));
    $entity->setFonts([
      [
        'id' => '00000000-0000-4000-8000-000000000001',
        'family' => 'Inter',
        'uri' => $font_uri,
        'format' => 'woff2',
        'weight' => '400',
        'style' => 'normal',
      ],
    ]);
    $entity->save();

    $library_discovery = \Drupal::service(LibraryDiscoveryInterface::class);
    \assert($library_discovery instanceof LibraryDiscoveryInterface);
    $discovered = $library_discovery->getLibrariesByExtension('canvas');

    $library_name = 'brand_kit.' . BrandKit::GLOBAL_ID;
    self::assertArrayHasKey($library_name, $discovered);
    $css_paths = array_column($discovered[$library_name]['css'] ?? [], 'data');
    $font_file_found = array_filter($css_paths, fn(string $path) => str_contains($path, 'library-build.woff2'));
    self::assertEmpty($font_file_found, 'Uploaded font files should not be attached directly as CSS.');
  }

  public function testSetFontsStripsClientOnlyFields(): void {
    $font_uri = $this->createFontFile('strip-url.woff2');
    $entity = BrandKit::load(BrandKit::GLOBAL_ID);
    self::assertNotNull($entity);
    $file_url_generator = \Drupal::service('file_url_generator');
    $font_url = $file_url_generator->generateString($font_uri);
    self::assertIsString($font_url);

    $entity->setFonts([
      [
        'id' => '00000000-0000-4000-8000-000000000001',
        'family' => 'Inter',
        'uri' => $font_uri,
        'url' => $font_url,
        'format' => 'woff2',
        'variantType' => 'variable',
        'weight' => '400',
        'style' => 'normal',
        'axes' => [
          [
            'tag' => 'wght',
            'name' => 'Weight',
            'min' => 100.0,
            'max' => 900.0,
            'default' => 400.0,
          ],
        ],
        'axisSettings' => [
          [
            'tag' => 'wght',
            'value' => 450.0,
          ],
        ],
      ],
    ]);

    self::assertSame([
      [
        'id' => '00000000-0000-4000-8000-000000000001',
        'family' => 'Inter',
        'uri' => $font_uri,
        'format' => 'woff2',
        'weight' => '400',
        'style' => 'normal',
        'axes' => [
          [
            'tag' => 'wght',
            'name' => 'Weight',
            'min' => 100.0,
            'max' => 900.0,
            'default' => 400.0,
          ],
        ],
      ],
    ], $entity->getFonts());
  }

  public function testVariableFontMetadataPersists(): void {
    $file = $this->createManagedFontFile('inter-variable.woff2');
    $font_uri = $file->getFileUri();
    \assert(\is_string($font_uri));
    $entries = [
      [
        'id' => '00000000-0000-4000-8000-000000000002',
        'family' => 'Inter',
        'uri' => $font_uri,
        'format' => 'woff2',
        'weight' => '400',
        'style' => 'normal',
        'axes' => [
          [
            'tag' => 'wght',
            'name' => 'Weight',
            'min' => 100.0,
            'max' => 900.0,
            'default' => 400.0,
          ],
        ],
      ],
    ];

    $entity = BrandKit::load(BrandKit::GLOBAL_ID);
    self::assertNotNull($entity);

    $entity->setFonts($entries);
    $entity->save();

    $reloaded = BrandKit::load(BrandKit::GLOBAL_ID);
    self::assertNotNull($reloaded);
    self::assertSame($entries, $reloaded->getFonts());
    self::assertSame($entries[0]['axes'], $reloaded->normalizeForClientSide()->values['fonts'][0]['axes']);
    self::assertSame('variable', $reloaded->normalizeForClientSide()->values['fonts'][0]['variantType']);
  }

  public function testExistingStaticFontsDefaultVariantTypeWhenMissing(): void {
    $entity = BrandKit::load(BrandKit::GLOBAL_ID);
    self::assertNotNull($entity);

    $entity->set('fonts', [[
      'id' => '00000000-0000-4000-8000-000000000008',
      'family' => 'Legacy Sans',
      'uri' => $this->createFontFile('legacy.woff2'),
      'format' => 'woff2',
      'weight' => '400',
      'style' => 'normal',
    ],
    ]);

    self::assertSame('static', $entity->normalizeForClientSide()->values['fonts'][0]['variantType']);
  }

}
