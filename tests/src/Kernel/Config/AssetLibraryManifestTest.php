<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\Config;

use Drupal\canvas\Entity\AssetLibrary;
use Drupal\Core\File\FileSystemInterface;
use Drupal\file\Entity\File;
use Drupal\file\FileInterface;
use Drupal\file\FileUsage\FileUsageInterface;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the imports and assets properties on AssetLibrary.
 */
#[Group("canvas")]
class AssetLibraryManifestTest extends CanvasKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('file');
    $this->installSchema('file', ['file_usage']);

    $dir = AssetLibrary::ARTIFACTS_DIRECTORY;
    $this->container->get('file_system')->prepareDirectory($dir, FileSystemInterface::CREATE_DIRECTORY);
  }

  /**
   * Tests manifest property CRUD operations.
   *
   * Verifies defaults, save/load, and independent clearing of properties.
   */
  public function testManifestPropertiesCrud(): void {
    $entity = $this->getAssetLibrary();

    // Verify defaults are empty arrays.
    self::assertSame([], $entity->getImports());
    self::assertSame([], $entity->getAssets());
    self::assertSame([], $entity->getShared());

    // Set and save all properties with unsorted multi-item arrays.
    // This validates the config schema orderby setting sorts them.
    $entity->setImports([
      [
        'name' => 'z-last',
        'uri' => AssetLibrary::ARTIFACTS_DIRECTORY . 'vendor/z-last.js',
      ],
      [
        'name' => 'a-first',
        'uri' => AssetLibrary::ARTIFACTS_DIRECTORY . 'vendor/a-first.js',
      ],
      [
        'name' => 'm-middle',
        'uri' => AssetLibrary::ARTIFACTS_DIRECTORY . 'vendor/m-middle.js',
      ],
    ]);
    $entity->setAssets([
      [
        'name' => '@/z-component',
        'uri' => AssetLibrary::ARTIFACTS_DIRECTORY . 'z-component.js',
      ],
      [
        'name' => '@/a-component',
        'uri' => AssetLibrary::ARTIFACTS_DIRECTORY . 'a-component.js',
      ],
    ]);
    $entity->setShared([
      [
        'name' => 'shared-z',
        'uri' => AssetLibrary::ARTIFACTS_DIRECTORY . 'shared-z.js',
      ],
      [
        'name' => 'shared-a',
        'uri' => AssetLibrary::ARTIFACTS_DIRECTORY . 'shared-a.js',
      ],
    ]);
    $entity->save();

    // Verify are sorted by name.
    $imports = $entity->getImports();
    self::assertCount(3, $imports);
    self::assertSame('a-first', $imports[0]['name']);
    self::assertSame('m-middle', $imports[1]['name']);
    self::assertSame('z-last', $imports[2]['name']);

    $assets = $entity->getAssets();
    self::assertCount(2, $assets);
    self::assertSame('@/a-component', $assets[0]['name']);
    self::assertSame('@/z-component', $assets[1]['name']);

    $shared = $entity->getShared();
    self::assertCount(2, $shared);
    self::assertSame('shared-a', $shared[0]['name']);
    self::assertSame('shared-z', $shared[1]['name']);

    // Clear only imports, verify others remain.
    $entity->setImports(NULL);
    $entity->save();

    self::assertSame([], $entity->getImports());
    self::assertCount(2, $entity->getAssets());
    self::assertCount(2, $entity->getShared());
  }

  /**
   * Tests client-side normalization of manifest properties.
   */
  public function testClientSideNormalization(): void {
    $entity = $this->getAssetLibrary();

    // Verify baseline: all properties are null when empty.
    $clientSide = $entity->normalizeForClientSide();
    self::assertArrayHasKey('imports', $clientSide->values);
    self::assertArrayHasKey('assets', $clientSide->values);
    self::assertArrayHasKey('shared', $clientSide->values);
    self::assertNull($clientSide->values['imports']);
    self::assertNull($clientSide->values['assets']);
    self::assertNull($clientSide->values['shared']);

    // Set properties and verify normalization includes them.
    $assets = [
      [
        'name' => '@/components/hero/index.js',
        'uri' => AssetLibrary::ARTIFACTS_DIRECTORY . 'components/hero/index.js',
      ],
    ];
    $entity->setAssets($assets);
    $clientSide = $entity->normalizeForClientSide();

    self::assertNull($clientSide->values['imports']);
    self::assertSame($assets, $clientSide->values['assets']);
    self::assertNull($clientSide->values['shared']);
  }

  /**
   * Tests file usage tracking for all manifest property types.
   */
  public function testManifestFileUsageTracking(): void {
    $old_file = $this->createTestFile('old-file.js', 'old');
    $new_file = $this->createTestFile('new-file.js', 'new');
    $asset_file = $this->createTestFile('asset.js', 'asset');
    $shared_file = $this->createTestFile('shared.js', 'shared');

    $entity = $this->getAssetLibrary();
    $file_usage = $this->container->get(FileUsageInterface::class);

    // Add files to imports and verify usage tracking.
    $entity->setImports([
      [
        'name' => 'old-component',
        'uri' => (string) $old_file->getFileUri(),
      ],
    ]);
    $entity->save();

    $usage = $file_usage->listUsage($old_file);
    self::assertArrayHasKey('canvas', $usage);
    self::assertArrayHasKey(AssetLibrary::ENTITY_TYPE_ID, $usage['canvas']);
    self::assertArrayHasKey(AssetLibrary::GLOBAL_ID, $usage['canvas'][AssetLibrary::ENTITY_TYPE_ID]);

    // Replace old file with new file and verify old file marked temporary.
    $entity->setImports([
      [
        'name' => 'new-component',
        'uri' => (string) $new_file->getFileUri(),
      ],
    ]);
    $entity->save();

    $old_usage = $file_usage->listUsage($old_file);
    self::assertEmpty($old_usage);
    $old_file = File::load($old_file->id());
    self::assertNotNull($old_file);
    self::assertFalse($old_file->isPermanent());

    $new_usage = $file_usage->listUsage($new_file);
    self::assertArrayHasKey('canvas', $new_usage);

    // Add files to assets and shared, verify usage tracked for all types.
    $entity->setAssets([
      [
        'name' => '@/asset',
        'uri' => (string) $asset_file->getFileUri(),
      ],
    ]);
    $entity->setShared([
      [
        'name' => './vendor/shared.js',
        'uri' => (string) $shared_file->getFileUri(),
      ],
    ]);
    $entity->save();

    $asset_usage = $file_usage->listUsage($asset_file);
    self::assertArrayHasKey('canvas', $asset_usage);
    $shared_usage = $file_usage->listUsage($shared_file);
    self::assertArrayHasKey('canvas', $shared_usage);
  }

  /**
   * Tests that duplicate URIs are handled correctly in file usage tracking.
   */
  public function testManifestFileUsageWithDuplicateUris(): void {
    $file = $this->createTestFile('shared.js', 'shared');

    $entity = $this->getAssetLibrary();

    // Two manifest entries pointing to the same file.
    $entity->setImports([
      [
        'name' => 'motion',
        'uri' => (string) $file->getFileUri(),
      ],
      [
        'name' => '@/motion',
        'uri' => (string) $file->getFileUri(),
      ],
    ]);
    $entity->save();

    // File usage should exist (added once for the unique URI).
    $file_usage = $this->container->get(FileUsageInterface::class);
    $usage = $file_usage->listUsage($file);
    self::assertArrayHasKey('canvas', $usage);

    // Update manifest to still reference the same file.
    $entity->setImports([
      [
        'name' => 'motion',
        'uri' => (string) $file->getFileUri(),
      ],
    ]);
    $entity->save();

    // File usage should still exist.
    $usage_after = $file_usage->listUsage($file);
    self::assertArrayHasKey('canvas', $usage_after);
  }

  /**
   * Tests that file usage is cleared when entity is deleted.
   */
  public function testManifestFileUsageClearedOnDelete(): void {
    $file = $this->createTestFile('test-delete.js', 'test');

    $entity = $this->getAssetLibrary();

    $entity->setImports([
      [
        'name' => 'test-component',
        'uri' => (string) $file->getFileUri(),
      ],
    ]);
    $entity->save();

    // Verify file usage exists.
    $file_usage = $this->container->get(FileUsageInterface::class);
    $usage = $file_usage->listUsage($file);
    self::assertArrayHasKey('canvas', $usage);
    self::assertArrayHasKey(AssetLibrary::ENTITY_TYPE_ID, $usage['canvas']);
    self::assertArrayHasKey(AssetLibrary::GLOBAL_ID, $usage['canvas'][AssetLibrary::ENTITY_TYPE_ID]);

    // Delete the entity to test postDelete() cleanup.
    $entity->delete();

    // File usage should be cleared.
    $usage_after = $file_usage->listUsage($file);
    self::assertEmpty($usage_after);

    // File should be marked temporary for garbage collection.
    $file = File::load($file->id());
    self::assertNotNull($file);
    self::assertFalse($file->isPermanent());
  }

  /**
   * Tests that garbage collection removes temporary unused manifest files.
   */
  public function testManifestFileGarbageCollection(): void {
    $file = $this->createTestFile('gc-test.js', 'test');
    $file_uri = (string) $file->getFileUri();
    $entity = $this->getAssetLibrary();

    // Add file to manifest (establishes usage).
    $entity->setImports([
      [
        'name' => 'gc-test',
        'uri' => $file_uri,
      ],
    ]);
    $entity->save();

    // Backdate the changed time to exceed the garbage collection threshold.
    $age = $this->config('system.file')->get('temporary_maximum_age');
    $mocked_changed_time = $this->container->get('datetime.time')->getRequestTime() - $age - 1;
    $file->setChangedTime($mocked_changed_time);
    $file->save();

    // Run cron - file should NOT be deleted because it's still in use.
    $this->container->get('cron')->run();

    $file = File::load($file->id());
    self::assertNotNull($file, 'File should not be deleted while in use, even if old');
    self::assertFileExists($file_uri);
    self::assertEquals($mocked_changed_time, $file->getChangedTime());

    // Remove file from manifest (removes usage, marks temporary).
    $entity->setImports(NULL);
    $entity->save();

    // Verify file is marked temporary.
    $file = File::load($file->id());
    self::assertNotNull($file);
    self::assertFalse($file->isPermanent());

    // Backdate the file again since the changed timestamp was updated.
    self::assertNotEquals($mocked_changed_time, $file->getChangedTime());
    $file->setChangedTime($mocked_changed_time);
    $file->save();

    // Run cron again to trigger garbage collection.
    $this->container->get('cron')->run();

    // Verify file entity was deleted.
    $file = File::load($file->id());
    self::assertNull($file);

    // Verify physical file was deleted.
    self::assertFileDoesNotExist($file_uri);
  }

  private function getAssetLibrary(): AssetLibrary {
    $entity = AssetLibrary::load(AssetLibrary::GLOBAL_ID);
    self::assertNotNull($entity);
    return $entity;
  }

  private function createTestFile(string $filename, string $data): FileInterface {
    \file_put_contents(AssetLibrary::ARTIFACTS_DIRECTORY . $filename, $data);
    $file = File::create([
      'uri' => AssetLibrary::ARTIFACTS_DIRECTORY . $filename,
      'filename' => $filename,
      'filemime' => 'application/javascript',
      'status' => 1,
    ]);
    $file->save();
    return $file;
  }

}
