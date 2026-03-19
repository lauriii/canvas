<?php

declare(strict_types=1);

namespace Drupal\canvas\Entity;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\RefinableCacheableDependencyInterface;
use Drupal\Core\Config\Entity\ConfigEntityBase;
use Drupal\Core\Entity\Attribute\ConfigEntityType;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\canvas\ClientSideRepresentation;
use Drupal\canvas\EntityHandlers\AssetLibraryAccessControlHandler;
use Drupal\canvas\EntityHandlers\CanvasAssetStorage;
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
   * @var list<array{name: string, uri: string}>|null
   */
  protected ?array $assets = NULL;

  /**
   * Shared chunk file references.
   *
   * @var list<array{name: string, uri: string}>|null
   */
  protected ?array $shared = NULL;

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
        'assets' => $this->assets,
        'shared' => $this->shared,
      ],
      preview: NULL
    );
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
    $this->imports = $entries ?: NULL;
  }

  /**
   * @return list<array{name: string, uri: string}>
   */
  public function getAssets(): array {
    return $this->assets ?? [];
  }

  /**
   * @param list<array{name: string, uri: string}>|null $entries
   */
  public function setAssets(?array $entries): void {
    $this->assets = $entries ?: NULL;
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
    $this->shared = $entries ?: NULL;
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
    foreach (['imports', 'assets', 'shared'] as $key) {
      if ($properties[$key] === NULL) {
        unset($properties[$key]);
      }
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
    $file_usage = \Drupal::service('file.usage');
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
