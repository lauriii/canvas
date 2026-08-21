<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\Config;

use Drupal\canvas\Entity\AssetLibrary;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the computed `importMap` property on AssetLibrary.
 *
 * Build tools outside Drupal cannot know which bare specifiers a site resolves
 * in the browser, because modules and themes contribute them through
 * hook_canvas_importmap_alter(). This is how they find out.
 */
#[Group("canvas")]
class AssetLibraryImportMapTest extends CanvasKernelTestBase {

  public function testExposesTheEffectiveImportMap(): void {
    $normalized = self::getGlobalAssetLibrary()
      ->normalizeForClientSide()
      ->values;

    // An import map document, so a client can hand it straight to a
    // `<script type="importmap">` tag.
    self::assertSame(['imports', 'scopes'], \array_keys($normalized['importMap']));
    // Everything Canvas itself provides is listed, so a build tool knows not to
    // bundle it.
    self::assertArrayHasKey('react', $normalized['importMap']['imports']);
    self::assertArrayHasKey('drupal-canvas', $normalized['importMap']['imports']);
  }

  public function testIncludesModuleContributedImports(): void {
    $this->enableModules(['canvas_test_importmap_alter']);

    $normalized = self::getGlobalAssetLibrary()
      ->normalizeForClientSide()
      ->values;

    self::assertArrayHasKey('test-added-package', $normalized['importMap']['imports']);
  }

  public function testIsReadOnly(): void {
    $entity = self::getGlobalAssetLibrary();

    // A client that sends back the object it received must not be able to
    // write the computed property, nor make saving fail.
    $entity->updateFromClientSide(['importMap' => ['imports' => ['evil' => '/evil.js']]]);
    $entity->save();

    $reloaded = AssetLibrary::load(AssetLibrary::GLOBAL_ID);
    self::assertNotNull($reloaded);
    self::assertArrayNotHasKey('evil', $reloaded->normalizeForClientSide()->values['importMap']['imports']);
    self::assertArrayNotHasKey('importMap', $reloaded->toArray());
  }

  private static function getGlobalAssetLibrary(): AssetLibrary {
    $entity = AssetLibrary::load(AssetLibrary::GLOBAL_ID);
    self::assertNotNull($entity);
    return $entity;
  }

}
