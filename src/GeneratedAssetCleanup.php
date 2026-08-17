<?php

declare(strict_types=1);

namespace Drupal\canvas;

use Drupal\canvas\Entity\AssetLibrary;
use Drupal\canvas\Entity\BrandKit;
use Drupal\canvas\Entity\CanvasAssetInterface;
use Drupal\canvas\Entity\JavaScriptComponent;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
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
   * The shortest time an unreferenced generated file is kept, in seconds.
   *
   * A response that has already been sent still points at the previous file
   * name, so a file cannot be deleted the moment it stops being referenced:
   * that would strip the styling from every page still holding the old markup.
   * Six hours is the floor; ::getMaxAge() raises it to the page cache lifetime
   * the site advertises when that is longer. The files are small, so keeping
   * them longer than strictly necessary is cheap.
   */
  private const int MINIMUM_MAX_AGE = 21600;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly FileSystemInterface $fileSystem,
    private readonly TimeInterface $time,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Deletes unreferenced generated files older than ::getMaxAge().
   *
   * @return list<string>
   *   The URIs that were deleted.
   */
  public function deleteStaleFiles(): array {
    $in_use = $this->getReferencedUris();
    $cutoff = $this->time->getRequestTime() - $this->getMaxAge();
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
   * How long an unreferenced generated file is kept, in seconds.
   *
   * An unreferenced file has to outlive every response that still names it. For
   * anonymous traffic that is bounded by the page cache lifetime the site
   * advertises to proxies and CDNs, so honour it whenever it is longer than the
   * floor rather than assuming six hours is enough everywhere.
   *
   * @see \Drupal\Core\EventSubscriber\FinishResponseSubscriber::setResponseCacheable()
   */
  private function getMaxAge(): int {
    $page_max_age = (int) ($this->configFactory->get('system.performance')->get('cache.page.max_age') ?? 0);
    return \max(self::MINIMUM_MAX_AGE, $page_max_age);
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
