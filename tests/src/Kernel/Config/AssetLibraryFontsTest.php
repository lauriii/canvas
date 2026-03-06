<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\Config;

use Drupal\Core\Asset\LibraryDiscoveryInterface;
use Drupal\Core\Cache\CacheCollectorInterface;
use Drupal\canvas\Entity\AssetLibrary;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the fonts property on AssetLibrary.
 *
 * @legacy-covers \Drupal\canvas\Entity\AssetLibrary::getFonts
 * @legacy-covers \Drupal\canvas\Entity\AssetLibrary::setFonts
 * @legacy-covers \Drupal\canvas\Hook\LibraryHooks::libraryInfoBuild
 */
#[RunTestsInSeparateProcesses]
#[Group('canvas')]
final class AssetLibraryFontsTest extends CanvasKernelTestBase {

  public function testFontsDefaultToEmpty(): void {
    $entity = AssetLibrary::load(AssetLibrary::GLOBAL_ID);
    self::assertNotNull($entity);
    self::assertSame([], $entity->getFonts());
  }

  public function testFontsSaveAndLoad(): void {
    $entries = [
      [
        'name' => '@fontsource/inter',
        'uri' => 'assets://canvas/vendor/assets/fontsource-inter.css',
      ],
      [
        'name' => '@fontsource/roboto',
        'uri' => 'assets://canvas/vendor/assets/fontsource-roboto.css',
      ],
    ];

    $entity = AssetLibrary::load(AssetLibrary::GLOBAL_ID);
    self::assertNotNull($entity);

    $entity->setFonts($entries);
    $entity->save();

    $reloaded = AssetLibrary::load(AssetLibrary::GLOBAL_ID);
    self::assertNotNull($reloaded);
    self::assertSame($entries, $reloaded->getFonts());
    self::assertCount(2, $reloaded->getFonts());
  }

  public function testFontsCanBeCleared(): void {
    $entity = AssetLibrary::load(AssetLibrary::GLOBAL_ID);
    self::assertNotNull($entity);

    $entity->setFonts([
      [
        'name' => '@fontsource/inter',
        'uri' => 'assets://canvas/vendor/assets/fontsource-inter.css',
      ],
    ]);
    $entity->save();
    $reloaded = AssetLibrary::load(AssetLibrary::GLOBAL_ID);
    self::assertNotNull($reloaded);
    self::assertNotEmpty($reloaded->getFonts());

    $entity->setFonts(NULL);
    $entity->save();

    $reloaded = AssetLibrary::load(AssetLibrary::GLOBAL_ID);
    self::assertNotNull($reloaded);
    self::assertSame([], $reloaded->getFonts());
  }

  public function testFontsInClientSideNormalization(): void {
    $fonts = [
      [
        'name' => '@fontsource/inter',
        'uri' => 'assets://canvas/vendor/assets/fontsource-inter.css',
      ],
    ];

    $entity = AssetLibrary::load(AssetLibrary::GLOBAL_ID);
    self::assertNotNull($entity);

    $entity->setFonts($fonts);
    $clientSide = $entity->normalizeForClientSide();

    self::assertArrayHasKey('fonts', $clientSide->values);
    self::assertSame($fonts, $clientSide->values['fonts']);
  }

  public function testFontsInClientSideNormalizationWhenNull(): void {
    $entity = AssetLibrary::load(AssetLibrary::GLOBAL_ID);
    self::assertNotNull($entity);

    $clientSide = $entity->normalizeForClientSide();
    self::assertArrayHasKey('fonts', $clientSide->values);
    self::assertNull($clientSide->values['fonts']);
  }

  public function testFontsAppearInLibraryInfoBuild(): void {
    $entity = AssetLibrary::load(AssetLibrary::GLOBAL_ID);
    self::assertNotNull($entity);

    $entity->setFonts([
      [
        'name' => '@fontsource/inter',
        'uri' => 'assets://canvas/vendor/assets/fontsource-inter.css',
      ],
    ]);
    $entity->save();

    $library_discovery = \Drupal::service(LibraryDiscoveryInterface::class);
    \assert($library_discovery instanceof CacheCollectorInterface);
    $discovered = $library_discovery->getLibrariesByExtension('canvas');

    $library_name = 'asset_library.' . AssetLibrary::GLOBAL_ID;
    self::assertArrayHasKey($library_name, $discovered);
    self::assertArrayHasKey('css', $discovered[$library_name]);
    self::assertNotEmpty($discovered[$library_name]['css']);

    // After library discovery processing, CSS entries are flat arrays with a
    // 'data' property containing the file path.
    $css_paths = array_column($discovered[$library_name]['css'], 'data');
    $font_css_found = array_filter($css_paths, fn(string $path) => str_contains($path, 'fontsource-inter.css'));
    self::assertNotEmpty($font_css_found, 'Font CSS URL should appear in library CSS.');
  }

}
