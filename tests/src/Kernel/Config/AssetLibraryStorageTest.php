<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Kernel\Config;

use Drupal\experience_builder\Entity\AssetLibrary;
use Drupal\experience_builder\Entity\XbAssetInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\experience_builder\Traits\ContribStrictConfigSchemaTestTrait;

/**
 * @covers \Drupal\experience_builder\EntityHandlers\XbAssetStorage
 * @covers \Drupal\experience_builder\Entity\AssetLibrary
 * @group experience_builder
 * @internal
 */
class AssetLibraryStorageTest extends KernelTestBase {

  use ContribStrictConfigSchemaTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['experience_builder'];

  /**
   * @covers \Drupal\experience_builder\EntityHandlers\XbAssetStorage::generateFiles()
   */
  public function testGeneratedFiles(): void {
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

  protected function assertGeneratedFiles(XbAssetInterface $entity): void {
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
