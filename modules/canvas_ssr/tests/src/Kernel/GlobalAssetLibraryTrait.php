<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_ssr\Kernel;

use Drupal\canvas\Entity\AssetLibrary;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\StreamWrapper\StreamWrapperManagerInterface;

/**
 * Writes modules to the global asset library, the way a Canvas push does.
 *
 * This writes real files under public://, so a test using it must call
 * cleanupGlobalAssetLibraryModules() in tearDown().
 */
trait GlobalAssetLibraryTrait {

  /**
   * The files written so far.
   *
   * @var array<int, string>
   */
  private array $temporaryGlobalAssetUris = [];

  /**
   * Writes a module under public://canvas/assets and returns its URI.
   */
  private function writeGlobalAssetModule(string $relative_path, string $source): string {
    $uri = 'public://canvas/assets/' . ltrim($relative_path, '/');
    $directory = dirname($uri);
    $file_system = $this->container->get(FileSystemInterface::class);
    \assert($file_system instanceof FileSystemInterface);
    $file_system->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY);
    $file_system->saveData($source, $uri, FileExists::Replace);
    $this->temporaryGlobalAssetUris[] = $uri;
    return $uri;
  }

  /**
   * Sets the global asset library's manifest.
   *
   * @param list<array{name: string, uri: string}>|null $imports
   *   The imports entries.
   * @param list<array{name: string, uri: string}>|null $assets
   *   The assets entries.
   * @param list<array{name: string, uri: string}>|null $shared
   *   The shared entries.
   */
  private function setGlobalAssetLibraryEntries(?array $imports = NULL, ?array $assets = NULL, ?array $shared = NULL): void {
    $asset_library = AssetLibrary::load(AssetLibrary::GLOBAL_ID);
    \assert($asset_library instanceof AssetLibrary);
    $asset_library->setImports($imports);
    $asset_library->setAssets($assets);
    $asset_library->setShared($shared);
    $asset_library->save();
  }

  /**
   * Deletes the written files and empties the manifest.
   */
  protected function cleanupGlobalAssetLibraryModules(): void {
    // Kernel tests skip DrupalKernel::preHandle(), so public:// may no longer
    // be registered by tear-down.
    $stream_wrapper_manager = $this->container->get(StreamWrapperManagerInterface::class);
    \assert($stream_wrapper_manager instanceof StreamWrapperManagerInterface);
    $stream_wrapper_manager->register();
    $file_system = $this->container->get(FileSystemInterface::class);
    \assert($file_system instanceof FileSystemInterface);
    foreach ($this->temporaryGlobalAssetUris as $uri) {
      // A failed write leaves nothing to delete.
      if ($file_system->realpath($uri) !== FALSE) {
        $file_system->delete($uri);
      }
    }
    $this->temporaryGlobalAssetUris = [];
    $this->setGlobalAssetLibraryEntries(NULL, NULL, NULL);
  }

}
