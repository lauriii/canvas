<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\Config;

use Drupal\canvas\Entity\AssetLibrary;
use Drupal\canvas\Entity\BrandKit;
use Drupal\canvas\Entity\CanvasAssetInterface;
use Drupal\canvas\Entity\JavaScriptComponent;
use Drupal\canvas\GeneratedAssetCleanup;
use Drupal\Core\File\FileSystemInterface;
use Drupal\file\Entity\File;
use Drupal\file\FileInterface;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests Asset Library Storage.
 *
 * @internal
 * @legacy-covers \Drupal\canvas\EntityHandlers\CanvasAssetStorage
 * @legacy-covers \Drupal\canvas\Entity\AssetLibrary
 */
#[RunTestsInSeparateProcesses]
#[Group('canvas')]
class AssetLibraryStorageTest extends CanvasKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    // The brand kit generates its CSS from managed font files.
    // @see ::createManagedFontFile()
    $this->installEntitySchema('file');
    $this->installSchema('file', 'file_usage');
  }

  /**
   * Tests generated files.
   *
   * @legacy-covers \Drupal\canvas\EntityHandlers\CanvasAssetStorage::generateFiles
   */
  public function testGeneratedFiles(): void {
    $asset_library = AssetLibrary::load(AssetLibrary::GLOBAL_ID);
    self::assertNotNull($asset_library);
    $asset_library->delete();

    $asset_library = AssetLibrary::create([
      'id' => 'global',
      'label' => 'Test',
      'js' => [
        'original' => 'console.log("hey");',
        'compiled' => 'console.log("hey");',
      ],
      'css' => [
        'original' => '.test { display: none; }',
        'compiled' => '.test { display: none; }',
      ],
    ]);
    $this->assertGeneratedFiles($asset_library);
  }

  protected function assertGeneratedFiles(CanvasAssetInterface $entity): void {
    $this->assertTrue($entity->isNew());

    // Before saving, the corresponding files do not yet exist.
    self::assertFileDoesNotExist($entity->getCssPath());
    self::assertFileDoesNotExist($entity->getJsPath());

    // After saving, they do.
    $entity->save();
    self::assertFileExists($entity->getCssPath());
    self::assertFileExists($entity->getJsPath());

    // After changing without saving, they don't.
    $original_js_path = $entity->getJsPath();
    $entity->set('js', [
      'original' => 'console.log("hallo");',
      'compiled' => 'console.log("hallo");',
    ]);
    self::assertFileDoesNotExist($entity->getJsPath());

    // After saving, it does, and the original also still exists: the path is a
    // content hash, so the previous file is orphaned rather than overwritten.
    // Cron cleans it up once no response can still be pointing at it.
    // @see ::testStaleGeneratedFilesAreGarbageCollected()
    $entity->save();
    self::assertFileExists($entity->getJsPath());
    self::assertFileExists($original_js_path);
  }

  /**
   * Tests that orphaned generated files are eventually deleted.
   *
   * Drupal core's asset garbage collection only sweeps `assets://css` and
   * `assets://js`, so nothing removed the files Canvas orphans in
   * `assets://canvas` and `assets://astro-island` on every save.
   *
   * @legacy-covers \Drupal\canvas\GeneratedAssetCleanup::deleteStaleFiles
   */
  public function testStaleGeneratedFilesAreGarbageCollected(): void {
    $cleanup = $this->container->get(GeneratedAssetCleanup::class);
    \assert($cleanup instanceof GeneratedAssetCleanup);

    // All three entity types that generate files, so that both directories and
    // both extensions are covered, and so that removing any of them from the
    // sweep's list of entity types deletes a file that is still in use.
    $asset_library = AssetLibrary::load(AssetLibrary::GLOBAL_ID);
    self::assertNotNull($asset_library);
    $brand_kit = BrandKit::load(BrandKit::GLOBAL_ID);
    self::assertNotNull($brand_kit);
    $code_component = JavaScriptComponent::create([
      'machineName' => 'gc_test_component',
      'name' => 'GC test component',
      'status' => FALSE,
      'props' => [
        'title' => [
          'type' => 'string',
          'title' => 'Title',
          'examples' => ['Title'],
        ],
      ],
      'required' => ['title'],
      'slots' => [],
      'dataDependencies' => [],
    ]);

    $orphaned = [];
    $current = [];
    foreach ([1 => 'first', 2 => 'second'] as $round => $marker) {
      $asset_library->set('css', [
        'original' => ".$marker { display: none; }",
        'compiled' => ".$marker{display:none}",
      ])->set('js', [
        'original' => "console.log('$marker');",
        'compiled' => "console.log('$marker');",
      ])->save();
      // The brand kit generates its CSS from its fonts; a different font file
      // means different CSS, hence a different content hash.
      $brand_kit->setFonts([
        [
          'id' => '00000000-0000-4000-8000-00000000000' . $round,
          'family' => 'GC Test',
          'uri' => $this->createManagedFontFile("gc-test-$marker.woff2")->getFileUri(),
          'format' => 'woff2',
          'weight' => '400',
          'style' => 'normal',
        ],
      ]);
      $brand_kit->save();
      $code_component->set('css', [
        'original' => ".$marker { color: red; }",
        'compiled' => ".$marker{color:red}",
      ])->set('js', [
        'original' => "export default () => '$marker';",
        'compiled' => "export default () => '$marker';",
      ])->save();

      $paths = [
        $asset_library->getCssPath(),
        $asset_library->getJsPath(),
        $brand_kit->getCssPath(),
        $code_component->getCssPath(),
        $code_component->getJsPath(),
      ];
      foreach ($paths as $path) {
        self::assertFileExists($path);
      }
      // The second round orphans everything the first round wrote.
      if ($round === 1) {
        $orphaned = $paths;
      }
      else {
        $current = $paths;
      }
    }

    self::assertSame([], \array_intersect($orphaned, $current), 'Every path is content-addressed, so a second save orphans the first file');
    // Both directories, both extensions.
    self::assertNotEmpty(\array_filter($orphaned, static fn (string $p): bool => \str_starts_with($p, AssetLibrary::ASSETS_DIRECTORY)));
    self::assertNotEmpty(\array_filter($orphaned, static fn (string $p): bool => \str_starts_with($p, JavaScriptComponent::ASSETS_DIRECTORY)));
    self::assertNotEmpty(\array_filter($orphaned, static fn (string $p): bool => \str_ends_with($p, '.js')));

    // Everything was written during this request, so nothing is old enough to
    // be removed yet: a response that is still being served can point at an
    // orphan.
    self::assertSame([], $cleanup->deleteStaleFiles());

    // Age everything past the grace period, the files still in use included, to
    // prove age alone is not what makes a file collectable.
    $file_system = $this->container->get(FileSystemInterface::class);
    \assert($file_system instanceof FileSystemInterface);
    $long_ago = \time() - (7 * 24 * 60 * 60);
    foreach ([...$orphaned, ...$current] as $path) {
      $real_path = $file_system->realpath($path);
      self::assertIsString($real_path);
      self::assertTrue(\touch($real_path, $long_ago));
    }
    \clearstatcache();

    // A site that advertises a page cache lifetime longer than the six-hour
    // floor keeps its files for that lifetime instead: a response cached for
    // two weeks still names them. Seven days is inside that window, so the
    // orphans survive.
    // @see \Drupal\canvas\GeneratedAssetCleanup::getMaxAge()
    $this->config('system.performance')->set('cache.page.max_age', 14 * 24 * 60 * 60)->save();
    self::assertSame([], $cleanup->deleteStaleFiles());
    $this->config('system.performance')->set('cache.page.max_age', 0)->save();

    $deleted = $cleanup->deleteStaleFiles();
    \sort($deleted);
    $expected = $orphaned;
    \sort($expected);
    self::assertSame($expected, $deleted);
    foreach ($orphaned as $path) {
      self::assertFileDoesNotExist($path);
    }
    foreach ($current as $path) {
      self::assertFileExists($path, 'A file a config entity still points at is never deleted, whatever its age');
    }

    // And it is idempotent.
    self::assertSame([], $cleanup->deleteStaleFiles());
  }

  /**
   * Tests that reverting an entity makes its orphaned file protected again.
   *
   * The paths are content hashes, so reverting to earlier content resurrects an
   * orphaned file under its old name rather than writing a new one. What keeps
   * such a file is the reference set, not its age, and the reference set has to
   * be read from storage rather than from a stale static cache.
   *
   * Note this covers the *precondition* of the sweep's race, not the race
   * itself: reproducing an entity save interleaved between the sweep's scan and
   * its unlink is not practical in a single-threaded kernel test.
   *
   * @see \Drupal\canvas\GeneratedAssetCleanup::deleteStaleFiles()
   * @legacy-covers \Drupal\canvas\GeneratedAssetCleanup::deleteStaleFiles
   */
  public function testRevertedEntityKeepsItsResurrectedFile(): void {
    $cleanup = $this->container->get(GeneratedAssetCleanup::class);
    \assert($cleanup instanceof GeneratedAssetCleanup);

    $asset_library = AssetLibrary::load(AssetLibrary::GLOBAL_ID);
    self::assertNotNull($asset_library);
    $first = ['original' => '.first { display: none; }', 'compiled' => '.first{display:none}'];
    $second = ['original' => '.second { display: none; }', 'compiled' => '.second{display:none}'];

    $asset_library->set('css', $first)->save();
    $orphaned_css_path = $asset_library->getCssPath();
    $asset_library->set('css', $second)->save();
    self::assertNotSame($orphaned_css_path, $asset_library->getCssPath());

    // Age the orphan so that, on the reference set as it stands now, the sweep
    // would collect it.
    $file_system = $this->container->get(FileSystemInterface::class);
    \assert($file_system instanceof FileSystemInterface);
    $real_path = $file_system->realpath($orphaned_css_path);
    self::assertIsString($real_path);
    self::assertTrue(\touch($real_path, \time() - (7 * 24 * 60 * 60)));
    \clearstatcache();

    // Now revert, which makes that same path current again: the path is a
    // content hash, so identical content means the identical file name.
    $asset_library->set('css', $first)->save();
    self::assertSame($orphaned_css_path, $asset_library->getCssPath());
    // Saving rewrote the file, so age it again: this test is about the
    // reference set, not about the retention period.
    self::assertTrue(\touch($real_path, \time() - (7 * 24 * 60 * 60)));
    \clearstatcache();

    self::assertSame([], $cleanup->deleteStaleFiles());
    self::assertFileExists($orphaned_css_path, 'A file that is current again is never deleted');
  }

  /**
   * Creates a managed font file for the brand kit to generate CSS from.
   */
  private function createManagedFontFile(string $filename): FileInterface {
    $file_system = $this->container->get(FileSystemInterface::class);
    \assert($file_system instanceof FileSystemInterface);
    $directory = BrandKit::ARTIFACTS_DIRECTORY;
    self::assertTrue($file_system->prepareDirectory(
      $directory,
      FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS,
    ));
    $uri = $directory . $filename;
    $real_path = $file_system->realpath($uri);
    self::assertIsString($real_path);
    self::assertNotFalse(\file_put_contents($real_path, 'font-data'));

    $file = File::create(['uri' => $uri]);
    $file->save();

    return $file;
  }

}
