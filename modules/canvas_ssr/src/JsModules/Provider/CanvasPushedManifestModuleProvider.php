<?php

declare(strict_types=1);

namespace Drupal\canvas_ssr\JsModules\Provider;

use Drupal\canvas\Entity\AssetLibrary;
use Drupal\canvas_ssr\JsModules\ProvidedModule;
use Drupal\canvas_ssr\JsModules\ProvidedModules;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;

/**
 * Supplies the code a site pushed with the Canvas CLI.
 *
 * A push uploads its build into one flat directory and lists the files in
 * the global AssetLibrary's manifest:
 *
 * - imports: the npm packages it bundled.
 * - assets: the site's own "@/…" modules.
 * - shared: chunks split out of those.
 *
 * Each file is named by the URL the browser loads it from, as in
 * CanvasDistModuleProvider. The manifest's own names are ignored. Only the
 * site import map says which specifier reaches which file, and no import
 * map entry points at a shared chunk, so SSR can't resolve anything the
 * browser can't.
 *
 * Each file gets evaluated during SSR, so what the manifest lists is
 * checked rather than trusted: a real .js file directly in the artifacts
 * directory, not a symlink. A file that fails is left out, so the islands
 * that import it fall back to CSR, not the whole page.
 *
 * @see \Drupal\canvas\GlobalImports::getImportMap()
 * @see \Drupal\canvas\Controller\ApiArtifactController::upload()
 * @see \Drupal\canvas_ssr\JsModules\SiteImportMap
 * @see \Drupal\canvas_ssr\JsModules\Provider\CanvasDistModuleProvider
 */
final readonly class CanvasPushedManifestModuleProvider implements ModuleProviderInterface {

  public function __construct(
    private EntityTypeManagerInterface $entityTypeManager,
    private FileSystemInterface $fileSystem,
    private FileUrlGeneratorInterface $fileUrlGenerator,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getModules(): ProvidedModules {
    // The list tag covers a site's first push, when there's no library yet.
    // The library's own tag covers later pushes.
    $cacheability = new CacheableMetadata();
    $cacheability->addCacheTags(
      $this->entityTypeManager->getDefinition(AssetLibrary::ENTITY_TYPE_ID)->getListCacheTags()
    );
    // Modules are named by URL.
    $cacheability->addCacheContexts(['url.site']);

    $library = $this->entityTypeManager
      ->getStorage(AssetLibrary::ENTITY_TYPE_ID)
      ->load(AssetLibrary::GLOBAL_ID);
    if (!$library instanceof AssetLibrary) {
      return new ProvidedModules([], $cacheability);
    }
    $cacheability->addCacheableDependency($library);

    // FALSE when no push has created the directory yet.
    $directory = $this->fileSystem->realpath(AssetLibrary::ARTIFACTS_DIRECTORY);
    if ($directory === FALSE) {
      return new ProvidedModules([], $cacheability);
    }

    $entries = [
      ...$library->getImports(),
      ...$library->getAssets(),
      ...$library->getShared(),
    ];
    $modules = [];
    foreach ($entries as $entry) {
      $uri = $entry['uri'] ?? NULL;
      if (!\is_string($uri)) {
        continue;
      }
      $path = self::moduleFile($uri, $directory);
      if ($path === NULL) {
        continue;
      }
      $modules[] = ProvidedModule::fromFile(
        url: $this->fileUrlGenerator->generateString($uri),
        path: $path,
      );
    }

    return new ProvidedModules($modules, $cacheability);
  }

  /**
   * Checks a manifest URI and returns the file to read.
   *
   * @param string $uri
   *   The URI from the manifest.
   * @param string $directory
   *   The artifacts directory's real path, without a trailing slash.
   *
   * @return string|null
   *   The file's path, or NULL unless the URI names a .js file directly in
   *   the artifacts directory.
   */
  private static function moduleFile(string $uri, string $directory): ?string {
    if (!\str_starts_with($uri, AssetLibrary::ARTIFACTS_DIRECTORY)) {
      return NULL;
    }
    // Uploads are flat, so require a bare file name. That also rules out
    // "../".
    $name = \substr($uri, \strlen(AssetLibrary::ARTIFACTS_DIRECTORY));
    if ($name === '' || \str_contains($name, '/') || !\str_ends_with($name, '.js')) {
      return NULL;
    }

    // Check the path as written, not its realpath, which would resolve a
    // symlink away. Uploads never make symlinks.
    $path = $directory . '/' . $name;
    if (!\is_file($path) || \is_link($path)) {
      return NULL;
    }

    return $path;
  }

}
