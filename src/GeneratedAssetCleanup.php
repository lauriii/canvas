<?php

declare(strict_types=1);

namespace Drupal\canvas;

use Drupal\canvas\Entity\AssetLibrary;
use Drupal\canvas\Entity\BrandKit;
use Drupal\canvas\Entity\CanvasAssetInterface;
use Drupal\canvas\Entity\JavaScriptComponent;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;

/**
 * Deletes generated CSS and JS files that no config entity refers to anymore.
 *
 * Canvas writes the CSS and JS of its asset config entities to the `assets://`
 * stream, at a path that is a content hash. Editing one of those entities
 * therefore writes a new file and orphans the previous one, and nothing ever
 * removes it: Drupal core's own asset garbage collection only deletes
 * `assets://css` and `assets://js`, the directories its aggregator writes to.
 *
 * @see \Drupal\canvas\Entity\CanvasAssetLibraryTrait::getCssPath()
 * @see \Drupal\canvas\EntityHandlers\CanvasAssetStorage::generateFiles()
 * @see \drupal_flush_all_caches()
 *
 * @internal
 */
final class GeneratedAssetCleanup {

  /**
   * The directories Canvas generates asset files into.
   */
  private const array DIRECTORIES = [
    // AssetLibrary and BrandKit.
    AssetLibrary::ASSETS_DIRECTORY,
    JavaScriptComponent::ASSETS_DIRECTORY,
  ];

  /**
   * The config entity types whose generated files live in those directories.
   */
  private const array ENTITY_TYPE_IDS = [
    AssetLibrary::ENTITY_TYPE_ID,
    BrandKit::ENTITY_TYPE_ID,
    JavaScriptComponent::ENTITY_TYPE_ID,
  ];

  /**
   * How long an unreferenced generated file is kept, in seconds.
   *
   * A response that has already been sent still points at the previous file
   * name, so a file cannot be deleted the moment it stops being referenced:
   * that would strip the styling from every page still holding the old markup.
   * Six hours is longer than any page cache or CDN lifetime a Drupal site sets
   * by default, and the files are small.
   */
  private const int MAX_AGE = 21600;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly FileSystemInterface $fileSystem,
    private readonly TimeInterface $time,
  ) {}

  /**
   * Deletes unreferenced generated files older than ::MAX_AGE.
   *
   * @return list<string>
   *   The URIs that were deleted.
   */
  public function deleteStaleFiles(): array {
    $in_use = $this->getReferencedUris();
    $cutoff = $this->time->getRequestTime() - self::MAX_AGE;
    $deleted = [];

    foreach (self::DIRECTORIES as $directory) {
      if (!\is_dir($directory)) {
        continue;
      }
      // Only ever consider the file extensions Canvas generates, so that
      // anything else a site put in these directories is left alone.
      $files = $this->fileSystem->scanDirectory($directory, '/\.(css|js)$/', ['recurse' => FALSE]);
      foreach (\array_keys($files) as $uri) {
        if (isset($in_use[$uri])) {
          continue;
        }
        $modified = \filemtime($uri);
        if ($modified === FALSE || $modified > $cutoff) {
          continue;
        }
        if ($this->fileSystem->delete($uri)) {
          $deleted[] = $uri;
        }
      }
    }

    return $deleted;
  }

  /**
   * The generated file URIs the current config entities point at.
   *
   * @return array<string, true>
   *   URIs as keys, for O(1) lookup.
   */
  private function getReferencedUris(): array {
    $uris = [];
    foreach (self::ENTITY_TYPE_IDS as $entity_type_id) {
      foreach ($this->entityTypeManager->getStorage($entity_type_id)->loadMultiple() as $entity) {
        \assert($entity instanceof CanvasAssetInterface);
        $uris[$entity->getCssPath()] = TRUE;
        $uris[$entity->getJsPath()] = TRUE;
      }
    }
    return $uris;
  }

}
