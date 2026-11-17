<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_ssr\Kernel\JsModules;

use Drupal\canvas\Entity\AssetLibrary;
use Drupal\canvas_ssr\JsModules\SiteImportMap;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Serialization\ObjectAwareSerializationInterface;
use Drupal\Core\Cache\CacheTagsChecksumInterface;
use Drupal\Core\Cache\DatabaseBackend;
use Drupal\Core\Cache\DatabaseCacheTagsChecksum;
use Drupal\Core\Database\Connection;
use Drupal\Tests\canvas_ssr\Kernel\CanvasSsrKernelTestBase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the site import map and the cache tag it pins.
 */
#[CoversClass(SiteImportMap::class)]
#[RunTestsInSeparateProcesses]
#[Group('canvas')]
#[Group('JavaScriptComponents')]
final class SiteImportMapTest extends CanvasSsrKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // Saving the global asset library looks up its files in file_managed.
    $this->installEntitySchema('file');
  }

  /**
   * Tests the tag is the one saving the asset library invalidates.
   */
  public function testTagIsThatOfTheAssetLibrary(): void {
    $asset_library = AssetLibrary::load(AssetLibrary::GLOBAL_ID);
    self::assertNotNull($asset_library);
    self::assertSame(
      [SiteImportMap::ASSET_LIBRARY_TAG],
      $asset_library->getCacheTagsToInvalidate(),
    );
  }

  /**
   * Tests a push after the map is read leaves later cache writes stale.
   *
   * A render reads the map, another request pushes, then the render caches
   * markup built from the old map. That entry must not outlive the push.
   */
  public function testPushAfterReadInvalidatesLaterWrites(): void {
    $checksum = $this->container->get(CacheTagsChecksumInterface::class);
    // The pin relies on this one keeping what it read for the request.
    self::assertInstanceOf(DatabaseCacheTagsChecksum::class, $checksum);
    $site_import_map = $this->container->get(SiteImportMap::class);
    \assert($site_import_map instanceof SiteImportMap);

    $site_import_map->get();

    // Another request pushes, with its own checksum service.
    $connection = $this->container->get('database');
    \assert($connection instanceof Connection);
    (new DatabaseCacheTagsChecksum($connection))->invalidateTags([SiteImportMap::ASSET_LIBRARY_TAG]);

    // This request caches what it built from the map it read.
    $this->backend($checksum)->set('markup', 'built from the old map', tags: [SiteImportMap::ASSET_LIBRARY_TAG]);

    // The next request doesn't use it.
    $next_request = $this->backend(new DatabaseCacheTagsChecksum($connection));
    self::assertFalse($next_request->get('markup'));

    // Whereas a request that read the tag after the push caches for good.
    $next_request->set('markup', 'built from the new map', tags: [SiteImportMap::ASSET_LIBRARY_TAG]);
    $cached = $this->backend(new DatabaseCacheTagsChecksum($connection))->get('markup');
    self::assertNotFalse($cached);
    self::assertSame('built from the new map', $cached->data);
  }

  /**
   * Tests the map is read again once the asset library changes.
   */
  public function testSavingTheAssetLibraryDropsTheMap(): void {
    $site_import_map = $this->container->get(SiteImportMap::class);
    \assert($site_import_map instanceof SiteImportMap);
    self::assertArrayNotHasKey('pushed', $site_import_map->get()['imports']);

    $asset_library = AssetLibrary::load(AssetLibrary::GLOBAL_ID);
    \assert($asset_library instanceof AssetLibrary);
    $asset_library->setImports([['name' => 'pushed', 'uri' => 'public://canvas/assets/pushed.js']]);
    $asset_library->save();

    self::assertArrayHasKey('pushed', $site_import_map->get()['imports']);
  }

  /**
   * Builds a database cache bin on a checksum service.
   */
  private function backend(CacheTagsChecksumInterface $checksum): DatabaseBackend {
    $connection = $this->container->get('database');
    \assert($connection instanceof Connection);
    $serializer = $this->container->get(ObjectAwareSerializationInterface::class);
    \assert($serializer instanceof ObjectAwareSerializationInterface);
    $time = $this->container->get(TimeInterface::class);
    \assert($time instanceof TimeInterface);

    return new DatabaseBackend($connection, $checksum, 'canvas_ssr_test', $serializer, $time);
  }

}
