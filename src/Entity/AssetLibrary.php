<?php

declare(strict_types=1);

namespace Drupal\canvas\Entity;

use Drupal\canvas\ClientSideRepresentation;
use Drupal\canvas\EntityHandlers\AssetLibraryAccessControlHandler;
use Drupal\canvas\EntityHandlers\CanvasAssetStorage;
use Drupal\canvas\ExtensionNpmDependencies;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\RefinableCacheableDependencyInterface;
use Drupal\Core\Config\Entity\ConfigEntityBase;
use Drupal\Core\Entity\Attribute\ConfigEntityType;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\file\FileInterface;
use Drupal\file\FileUsage\FileUsageInterface;

#[ConfigEntityType(
  id: self::ENTITY_TYPE_ID,
  label: new TranslatableMarkup('In-browser code library'),
  label_singular: new TranslatableMarkup('in-browser code library'),
  label_plural: new TranslatableMarkup('in-browser code libraries'),
  label_collection: new TranslatableMarkup('In-browser code libraries'),
  admin_permission: self::ADMIN_PERMISSION,
  handlers: [
    'storage' => CanvasAssetStorage::class,
    'access' => AssetLibraryAccessControlHandler::class,
  ],
  entity_keys: [
    'id' => 'id',
    'label' => 'label',
  ],
  links: [],
  config_export: [
    'id',
    'label',
    'css',
    'js',
    'imports',
    'assets',
    'shared',
    'bundledSources',
    'packageJson',
  ],
)]
final class AssetLibrary extends ConfigEntityBase implements CanvasAssetInterface {
  use CanvasAssetLibraryTrait;

  public const string ENTITY_TYPE_ID = 'asset_library';
  public const string ADMIN_PERMISSION = JavaScriptComponent::ADMIN_PERMISSION;
  public const string ASSETS_DIRECTORY = 'assets://canvas/';
  public const string ARTIFACTS_DIRECTORY = 'public://canvas/assets/';

  public const string GLOBAL_ID = 'global';

  protected string $id;

  /**
   * The human-readable label of the asset library.
   */
  protected ?string $label;

  /**
   * Import file references.
   *
   * @var list<array{name: string, uri: string}>|null
   */
  protected ?array $imports = NULL;

  /**
   * Asset file references.
   *
   * Each entry may additionally carry `path` (the original relative disk path)
   * and, for text modules, `source` (the verbatim original source text), stored
   * so a CLI pull can reconstruct the file on disk.
   *
   * @var list<array{name: string, uri: string, path?: string, source?: string}>|null
   */
  protected ?array $assets = NULL;

  /**
   * Shared chunk file references.
   *
   * @var list<array{name: string, uri: string}>|null
   */
  protected ?array $shared = NULL;

  /**
   * Verbatim sources of local modules bundled into other artifacts.
   *
   * A local helper reached only transitively (or via a relative import inside
   * another local module) is inlined by the bundler and has no standalone
   * artifact. Its source is kept here, keyed by original relative disk `path`,
   * so a CLI pull can reconstruct the editable file. These entries have no
   * `uri` and are never part of the runtime import map.
   *
   * @var list<array{path: string, source: string}>|null
   */
  protected ?array $bundledSources = NULL;

  /**
   * The package.json of the source project.
   *
   * Stored so that a subsequent CLI `pull` can reconstruct the project's
   * `package.json` on disk.
   */
  protected ?string $packageJson = NULL;

  /**
   * {@inheritdoc}
   *
   * This corresponds to `AssetLibrary` in openapi.yml.
   *
   * @see docs/adr/0005-Keep-the-front-end-simple.md
   */
  public function normalizeForClientSide(): ClientSideRepresentation {
    return ClientSideRepresentation::create(
      values: [
        'id' => $this->id,
        'label' => $this->label,
        'css' => $this->css,
        'js' => $this->js,
        'imports' => $this->imports,
        'assets' => $this->normalizeAssetsForClientSide(),
        'shared' => $this->shared,
        'bundledSources' => $this->bundledSources,
        'packageJson' => $this->packageJson,
        'npmDependencies' => self::getNpmDependencies(),
      ],
      preview: NULL
    )
      // The computed `npmDependencies` changes when extensions are installed
      // or uninstalled, not when this entity is saved.
      ->addCacheTags(['config:core.extension']);
  }

  /**
   * Returns the npm packages installed modules and themes declare.
   *
   * Computed for clients and never stored: it describes the site's extensions,
   * not this entity. A CLI `pull` adds them to the project's package.json so
   * the project builds against the same package versions the site's extensions
   * were written for. Exposed as an object so an empty set is `{}` in JSON.
   *
   * @see \Drupal\canvas\ExtensionNpmDependencies
   */
  private static function getNpmDependencies(): object {
    return (object) \Drupal::service(ExtensionNpmDependencies::class)->getDependencies();
  }

  /**
   * Adds a fetchable `url` to each asset entry for the client side.
   *
   * The stored `uri` is a `public://` stream wrapper URI, which the CLI cannot
   * fetch directly. A CLI `pull` downloads binary assets over HTTP, so expose a
   * generated URL alongside each entry. This value is normalize-time only; it
   * is not part of `config_export` and is never stored.
   *
   * @return list<array{name: string, uri: string, path?: string, source?: string, url: string}>|null
   *   The asset entries with an added `url`, or NULL when there are none.
   */
  private function normalizeAssetsForClientSide(): ?array {
    if ($this->assets === NULL) {
      return NULL;
    }
    $file_url_generator = \Drupal::service(FileUrlGeneratorInterface::class);
    \assert($file_url_generator instanceof FileUrlGeneratorInterface);
    $normalized = [];
    foreach ($this->assets as $entry) {
      $normalized[] = [
        ...$entry,
        'url' => $file_url_generator->generateString((string) $entry['uri']),
      ];
    }
    return $normalized;
  }

  /**
   * {@inheritdoc}
   *
   * This corresponds to `AssetLibrary` in openapi.yml.
   *
   * @see docs/adr/0005-Keep-the-front-end-simple.md
   */
  public static function createFromClientSide(array $data): static {
    $entity = static::create(['id' => $data['id']]);
    $entity->updateFromClientSide($data);
    return $entity;
  }

  /**
   * {@inheritdoc}
   *
   * This corresponds to `AssetLibrary` in openapi.yml.
   *
   * @see docs/adr/0005-Keep-the-front-end-simple.md
   */
  public function updateFromClientSide(array $data): void {
    foreach ($data as $key => $value) {
      match ($key) {
        'imports' => $this->setImports(\is_array($value) ? array_values($value) : NULL),
        'assets' => $this->setAssets(\is_array($value) ? array_values($value) : NULL),
        'shared' => $this->setShared(\is_array($value) ? array_values($value) : NULL),
        'bundledSources' => $this->setBundledSources(\is_array($value) ? array_values($value) : NULL),
        'packageJson' => $this->setPackageJson(\is_string($value) ? $value : NULL),
        // Computed for clients, never stored: a client that sends back the
        // object it received must not be able to write it.
        // @see self::getNpmDependencies()
        'npmDependencies' => NULL,
        default => $this->set($key, $value),
      };
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function refineListQuery(QueryInterface &$query, RefinableCacheableDependencyInterface $cacheability): void {
    // Nothing to do.
  }

  /**
   * {@inheritdoc}
   */
  public function postSave(EntityStorageInterface $storage, $update = TRUE): void {
    parent::postSave($storage, $update);

    // Sync file usage for manifest files.
    $this->syncManifestFileUsage();

    // The files generated in CanvasAssetStorage::doSave() have a
    // content-dependent hash in their name. This has 2 consequences:
    // 1. Cached responses that referred to an older version, continue to work.
    // 2. New responses must use the newly generated files, which requires the
    //    asset library to point to those new files. Hence the library info must
    //    be recalculated.
    // @see \canvas_library_info_build()
    Cache::invalidateTags(['library_info']);
  }

  /**
   * {@inheritdoc}
   */
  public static function postDelete(EntityStorageInterface $storage, array $entities): void {
    parent::postDelete($storage, $entities);
    foreach ($entities as $entity) {
      if ($entity instanceof self) {
        $entity->clearManifestFileUsage();
      }
    }
  }

  /**
   * @return list<array{name: string, uri: string}>
   */
  public function getImports(): array {
    return $this->imports ?? [];
  }

  /**
   * @param list<array{name: string, uri: string}>|null $entries
   */
  public function setImports(?array $entries): void {
    $this->imports = $entries === [] ? NULL : $entries;
  }

  /**
   * @return list<array{name: string, uri: string, path?: string, source?: string}>
   */
  public function getAssets(): array {
    return $this->assets ?? [];
  }

  /**
   * @param list<array{name: string, uri: string, path?: string, source?: string}>|null $entries
   */
  public function setAssets(?array $entries): void {
    if ($entries === NULL || $entries === []) {
      $this->assets = NULL;
      return;
    }
    // Keep only the stored keys. Drops the normalize-time `url` so a client
    // PATCH of the normalized representation cannot persist a key that is not
    // in the config schema.
    $this->assets = array_values(\array_map(
      static fn (array $entry): array => array_intersect_key(
        $entry,
        array_flip(['name', 'uri', 'path', 'source']),
      ),
      $entries,
    ));
  }

  /**
   * @return list<array{name: string, uri: string}>
   */
  public function getShared(): array {
    return $this->shared ?? [];
  }

  /**
   * @param list<array{name: string, uri: string}>|null $entries
   */
  public function setShared(?array $entries): void {
    $this->shared = $entries === [] ? NULL : $entries;
  }

  /**
   * @return list<array{path: string, source: string}>
   */
  public function getBundledSources(): array {
    return $this->bundledSources ?? [];
  }

  /**
   * @param list<array{path: string, source: string}>|null $entries
   */
  public function setBundledSources(?array $entries): void {
    if ($entries === NULL || $entries === []) {
      $this->bundledSources = NULL;
      return;
    }
    // Keep only the stored keys, guarding against a client PATCH persisting
    // unexpected keys that are not in the config schema.
    $this->bundledSources = array_values(\array_map(
      static fn (array $entry): array => array_intersect_key(
        $entry,
        array_flip(['path', 'source']),
      ),
      $entries,
    ));
  }

  /**
   * Gets the stored project `package.json`, if any.
   */
  public function getPackageJson(): ?string {
    return $this->packageJson;
  }

  /**
   * Sets the verbatim project `package.json` to store for round-tripping.
   */
  public function setPackageJson(?string $packageJson): void {
    $this->packageJson = ($packageJson === NULL || trim($packageJson) === '') ? NULL : $packageJson;
  }

  /**
   * {@inheritdoc}
   */
  public function getAssetLibrary(bool $isPreview): string {
    // Inside the Canvas UI, always load the draft even if there isn't one. Let
    // the controller logic automatically serve the non-draft assets when a
    // draft disappears. This is necessary to allow for asset library
    // dependencies, and avoids race conditions.
    // @see \Drupal\canvas\Controller\ApiConfigAutoSaveControllers::getCss()
    // @see \Drupal\canvas\Controller\ApiConfigAutoSaveControllers::getJs()
    return 'canvas/asset_library.' . $this->id() . ($isPreview ? '.draft' : '');
  }

  /**
   * {@inheritdoc}
   */
  public function getAssetLibraryDependencies(): array {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function toArray(): array {
    $properties = parent::toArray();
    // Omit NULL manifest properties to satisfy NotBlank constraint.
    // If there are no entries, the key should be omitted entirely.
    foreach (['imports', 'assets', 'shared', 'bundledSources'] as $key) {
      if ($properties[$key] === NULL) {
        unset($properties[$key]);
      }
    }
    // Omit the package.json when absent; the schema key is optional.
    if (($properties['packageJson'] ?? NULL) === NULL) {
      unset($properties['packageJson']);
    }
    return $properties;
  }

  /**
   * Extracts all URIs from manifest arrays.
   *
   * @param self $entity
   *   The entity to extract URIs from.
   *
   * @return list<string>
   *   All unique URIs from imports, assets, and shared arrays.
   */
  private static function extractManifestUris(self $entity): array {
    $uris = [];
    foreach ([...$entity->getImports(), ...$entity->getAssets(), ...$entity->getShared()] as $entry) {
      $uris[] = (string) $entry['uri'];
    }
    return \array_values(\array_unique($uris));
  }

  /**
   * Updates file usage records for manifest URIs.
   */
  private function syncManifestFileUsage(): void {
    $old_uris = $this->getOriginal() ? self::extractManifestUris($this->getOriginal()) : [];
    $new_uris = self::extractManifestUris($this);
    $uris_to_add = \array_diff($new_uris, $old_uris);
    $uris_to_remove = \array_diff($old_uris, $new_uris);

    // Add usage for new files.
    $files_to_add = self::loadFilesByUris($uris_to_add);
    $file_usage = self::getFileUsage();
    $id = (string) $this->id();
    foreach ($files_to_add as $file) {
      $file_usage->add($file, 'canvas', self::ENTITY_TYPE_ID, $id);
    }

    // Remove usage for deleted files.
    $files_to_remove = self::loadFilesByUris($uris_to_remove);
    $this->removeFileUsageAndMarkTemporary($files_to_remove);
  }

  /**
   * Clears file usage records for manifest URIs.
   */
  private function clearManifestFileUsage(): void {
    $uris = self::extractManifestUris($this);
    $files = self::loadFilesByUris($uris);
    $this->removeFileUsageAndMarkTemporary($files);
  }

  /**
   * Gets the file usage service.
   *
   * @return \Drupal\file\FileUsage\FileUsageInterface
   *   The file usage service.
   */
  private static function getFileUsage(): FileUsageInterface {
    $file_usage = \Drupal::service(FileUsageInterface::class);
    \assert($file_usage instanceof FileUsageInterface);
    return $file_usage;
  }

  /**
   * Removes file usage and marks files as temporary if no longer in use.
   *
   * @param list<\Drupal\file\FileInterface> $files
   *   The files to remove usage from.
   */
  private function removeFileUsageAndMarkTemporary(array $files): void {
    $file_usage = self::getFileUsage();
    $id = (string) $this->id();

    foreach ($files as $file) {
      $file_usage->delete($file, 'canvas', self::ENTITY_TYPE_ID, $id, 0);

      // Mark as temporary for garbage collection if no longer in use.
      if (empty($file_usage->listUsage($file)) && $file->isPermanent()) {
        $file->setTemporary();
        $file->save();
      }
    }
  }

  /**
   * Loads multiple file entities by URI in a single query.
   *
   * @param string[] $uris
   *   The file URIs to load.
   *
   * @return list<\Drupal\file\FileInterface>
   *   File entities.
   */
  private static function loadFilesByUris(array $uris): array {
    if (empty($uris)) {
      return [];
    }

    $file_storage = \Drupal::entityTypeManager()->getStorage('file');
    $query = $file_storage->getQuery()
      ->condition('uri', $uris, 'IN')
      ->accessCheck(FALSE);
    $fids = $query->execute();

    if (empty($fids)) {
      return [];
    }

    $files = $file_storage->loadMultiple($fids);
    $file_entities = [];
    foreach ($files as $file) {
      if ($file instanceof FileInterface) {
        $file_entities[] = $file;
      }
    }

    return $file_entities;
  }

}
