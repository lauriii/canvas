<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\Config;

use Drupal\canvas\Entity\AssetLibrary;
use Drupal\canvas\Entity\CanvasAssetInterface;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * @covers \Drupal\canvas\EntityHandlers\CanvasAssetStorage
 * @covers \Drupal\canvas\Entity\AssetLibrary
 * @group canvas
 * @internal
 */
#[RunTestsInSeparateProcesses]
class AssetLibraryStorageTest extends CanvasKernelTestBase {

  /**
   * @covers \Drupal\canvas\EntityHandlers\CanvasAssetStorage::generateFiles
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

    // After saving, it does, and the original also still exists.
    $entity->save();
    self::assertFileExists($entity->getJsPath());
    self::assertFileExists($original_js_path);
  }

}
