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

  /**
   * The most files one sweep deletes.
   *
   * The first sweep on a site that has never had one is the largest, and it is
   * the only sweep that site has ever needed. Bounding it keeps a long backlog
   * from costing more memory than cron has: exceeding that would abort the run
   * before anything is deleted, and every later run the same way, so the
   * backlog could never be worked off. Whatever is left waits for the next run.
   */
  private const int MAX_DELETIONS_PER_RUN = 1000;

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
    $candidates = [];

    foreach (self::DIRECTORIES as $directory) {
      if (!\is_dir($directory)) {
        continue;
      }
      // Iterated rather than collected as a whole: a listing of every file in
      // the directory is the one part of this that grows with the backlog.
      foreach (new \FilesystemIterator($directory, \FilesystemIterator::SKIP_DOTS) as $file_info) {
        \assert($file_info instanceof \SplFileInfo);
        $filename = $file_info->getFilename();
        // Only ever consider the file extensions Canvas generates, so that
        // anything else a site put in these directories is left alone.
        if (!\str_ends_with($filename, '.css') && !\str_ends_with($filename, '.js')) {
          continue;
        }
        // These directories are flat and every generated path is the directory
        // plus a content hash and an extension, so this is the same URI the
        // config entities report.
        // @see \Drupal\canvas\Entity\CanvasAssetLibraryTrait::getCssPath()
        $uri = $directory . $filename;
        if (isset($in_use[$uri])) {
          continue;
        }
        $modified = \filemtime($uri);
        if ($modified === FALSE || $modified > $cutoff) {
          continue;
        }
        $candidates[] = $uri;
        if (\count($candidates) === self::MAX_DELETIONS_PER_RUN) {
          break 2;
        }
      }
    }

    if ($candidates === []) {
      return [];
    }

    // Deciding and deleting are separate passes because the scan above is not
    // instantaneous: an editor saving during it can make one of these files
    // current again, and CanvasAssetStorage rewrites the file under its old
    // name rather than creating a new one. Reading the reference set a second
    // time, after the scan, keeps anything that reappeared meanwhile.
    // @see \Drupal\canvas\EntityHandlers\CanvasAssetStorage::doSave()
    $in_use = $this->getReferencedUris();

    $deleted = [];
    foreach ($candidates as $uri) {
      if (isset($in_use[$uri])) {
        continue;
      }
      // That second read is a snapshot too: it loads the entity types one after
      // another, and this loop outlives it. Re-reading the modification time
      // here is what actually protects a file written meanwhile, whether it
      // came back under its old name or was orphaned again — either way saving
      // it makes it too young to collect. Only the gap between this check and
      // the unlink below is left, and losing that race costs a stylesheet until
      // the entity is saved again, not data.
      \clearstatcache();
      $modified = \filemtime($uri);
      if ($modified === FALSE || $modified > $cutoff) {
        continue;
      }
      if ($this->fileSystem->delete($uri)) {
        $deleted[] = $uri;
      }
    }

    return $deleted;
  }

  /**
   * How long an unreferenced generated file is kept, in seconds.
   *
   * An unreferenced file has to outlive every response that still names it. For
   * anonymous traffic that is bounded by the page cache lifetime the site
   * advertises to proxies and CDNs, so honor it whenever it is longer than the
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
      $storage = $this->entityTypeManager->getStorage($entity_type_id);
      // Read through the storage's static cache. Cron is a long-running
      // request in which another hook, or this method's own earlier call, may
      // already have loaded these entities; a stale path here is a file
      // deleted while it is still in use.
      $storage->resetCache();
      foreach ($storage->loadMultiple() as $entity) {
        \assert($entity instanceof CanvasAssetInterface);
        $uris[$entity->getCssPath()] = TRUE;
        $uris[$entity->getJsPath()] = TRUE;
      }
    }
    return $uris;
  }

}
