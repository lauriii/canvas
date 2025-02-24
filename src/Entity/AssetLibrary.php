<?php

declare(strict_types=1);

namespace Drupal\experience_builder\Entity;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\RefinableCacheableDependencyInterface;
use Drupal\Core\Config\Entity\ConfigEntityBase;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\experience_builder\ClientSideRepresentation;

/**
 * @ConfigEntityType(
 *    id = \Drupal\experience_builder\Entity\AssetLibrary::ENTITY_TYPE_ID,
 *    label = @Translation("In-browser code library"),
 *    label_singular = @Translation("in-browser code library"),
 *    label_plural = @Translation("in-browser code libraries"),
 *    label_collection = @Translation("In-browser code libraries"),
 *    admin_permission = "administer code components",
 *    handlers = {},
 *    entity_keys = {
 *      "id" = "id",
 *      "label" = "label",
 *    },
 *    links = {},
 *    config_export = {
 *      "id",
 *      "label",
 *      "css",
 *      "js",
 *    }
 *  )
 */
final class AssetLibrary extends ConfigEntityBase implements XbHttpApiEligibleConfigEntityInterface, XbAssetInterface {

  use XbAssetLibraryTrait;

  public const string ENTITY_TYPE_ID = 'xb_asset_library';
  private const string ASSETS_DIRECTORY = 'assets://xb/';

  public const string GLOBAL_ID = 'global';

  protected string $id;

  /**
   * The human-readable label of the asset library.
   */
  protected ?string $label;

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
  public static function denormalizeFromClientSide(array $data): array {
    return $data;
  }

  /**
   * {@inheritdoc}
   */
  public static function refineListQuery(QueryInterface &$query, RefinableCacheableDependencyInterface $cacheability): void {
    // Nothing to do.
  }

  public function postSave(EntityStorageInterface $storage, $update = TRUE): void {
    // Force recalculation of library info.
    // @see \experience_builder_library_info_build()
    Cache::invalidateTags(['library_info']);
  }

}
