<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\Config;

use Drupal\canvas\Entity\AssetLibrary;
use Drupal\canvas\Entity\CanvasAssetInterface;
use Drupal\canvas\GeneratedAssetCleanup;
use Drupal\Core\File\FileSystemInterface;
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

    $asset_library = AssetLibrary::load(AssetLibrary::GLOBAL_ID);
    self::assertNotNull($asset_library);
    $asset_library->set('css', [
      'original' => '.first { display: none; }',
      'compiled' => '.first{display:none}',
    ])->save();
    $orphaned_css_path = $asset_library->getCssPath();
    self::assertFileExists($orphaned_css_path);

    // Editing the entity writes a new file and orphans the previous one.
    $asset_library->set('css', [
      'original' => '.second { display: none; }',
      'compiled' => '.second{display:none}',
    ])->save();
    $current_css_path = $asset_library->getCssPath();
    self::assertNotSame($orphaned_css_path, $current_css_path);
    self::assertFileExists($orphaned_css_path);
    self::assertFileExists($current_css_path);

    // Both files were written during this request, so neither is old enough to
    // be removed yet: a response that is still being served can point at the
    // orphan.
    self::assertSame([], $cleanup->deleteStaleFiles());
    self::assertFileExists($orphaned_css_path);

    // Age the orphan past the grace period. The file still in use is aged too,
    // to prove age alone is not what makes a file collectable.
    $file_system = $this->container->get(FileSystemInterface::class);
    \assert($file_system instanceof FileSystemInterface);
    $long_ago = \time() - (7 * 24 * 60 * 60);
    foreach ([$orphaned_css_path, $current_css_path] as $path) {
      $real_path = $file_system->realpath($path);
      self::assertIsString($real_path);
      self::assertTrue(\touch($real_path, $long_ago));
    }
    \clearstatcache();

    self::assertSame([$orphaned_css_path], $cleanup->deleteStaleFiles());
    self::assertFileDoesNotExist($orphaned_css_path);
    self::assertFileExists($current_css_path);

    // And it is idempotent.
    self::assertSame([], $cleanup->deleteStaleFiles());
  }

}
