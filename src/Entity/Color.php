<?php

declare(strict_types=1);

namespace Drupal\canvas\Entity;

use Drupal\canvas\ClientSideRepresentation;
use Drupal\canvas\EntityHandlers\CanvasAssetStorage;
use Drupal\canvas\EntityHandlers\ColorAccessControlHandler;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\RefinableCacheableDependencyInterface;
use Drupal\Core\Config\Entity\ConfigEntityBase;
use Drupal\Core\Entity\Attribute\ConfigEntityType;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * @phpstan-type ColorEntry array{
 *   id: string,
 *   name: string,
 *   cssVariable: string,
 *   value: array{
 *     colorSpace: string,
 *     components: list<float>,
 *     alpha: float|null,
 *     hex: string|null
 *   },
 *   displayFormat: string|null,
 *   weight: int
 * }
 * @phpstan-type ColorEntryInput array{
 *   id: string,
 *   name: string,
 *   cssVariable: string,
 *   value: array{
 *     colorSpace: string,
 *     components: list<float|int|string>,
 *     alpha: float|int|string|null,
 *     hex: string|null
 *   },
 *   displayFormat: string|null,
 *   weight: int|string
 * }
 */
#[ConfigEntityType(
  id: self::ENTITY_TYPE_ID,
  label: new TranslatableMarkup('Color'),
  label_singular: new TranslatableMarkup('color'),
  label_plural: new TranslatableMarkup('colors'),
  label_collection: new TranslatableMarkup('Colors'),
  admin_permission: self::ADMIN_PERMISSION,
  handlers: [
    'access' => ColorAccessControlHandler::class,
  ],
  entity_keys: [
    'id' => 'uuid',
    'label' => 'name',
    'weight' => 'weight',
  ],
  config_export: [
    'name',
    'cssVariable',
    'value',
    'displayFormat',
    'weight',
  ],
  constraints: [
    'ImmutableProperties' => [
      'properties' => [
        'uuid',
      ],
    ],
  ],
  additional: [
    // The client-side representation uses `id` as the identifier, not `uuid`.
    // @see ::normalizeForClientSide()
    'canvas_client_id_key' => 'id',
  ],
)]
final class Color extends ConfigEntityBase implements CanvasHttpApiEligibleConfigEntityInterface, FolderItemInterface {

  public const string ENTITY_TYPE_ID = 'color';
  public const string ADMIN_PERMISSION = 'administer brand kit';

  /**
   * Prefix marking a stored color prop value as a reference to a Color.
   *
   * A color prop stores either a literal CSS value (`#rrggbbaa`, `hsl(…)`) or
   * this prefix followed by a Color config entity ID (which is its UUID).
   *
   * @see \Drupal\canvas\Utility\ColorResolver::parseColorEntityId()
   */
  public const string REFERENCE_PREFIX = 'canvas-color:';

  protected string $name;
  protected string $cssVariable;

  /**
   * The color value in W3C Design Token format.
   *
   * @var array{
   *   colorSpace: string,
   *   components: list<float>,
   *   alpha: float|null,
   *   hex: string|null
   * }
   */
  protected array $value;
  protected ?string $displayFormat = NULL;
  protected int $weight = 0;

  public function id(): ?string {
    return $this->uuid();
  }

  /**
   * {@inheritdoc}
   *
   * This corresponds to `Color` in openapi.yml.
   *
   * @see docs/adr/0005-Keep-the-front-end-simple.md
   */
  public function normalizeForClientSide(): ClientSideRepresentation {
    return ClientSideRepresentation::create(
      values: [
        'id' => $this->uuid(),
        'name' => $this->name,
        'cssVariable' => $this->cssVariable,
        'value' => $this->value,
        'displayFormat' => $this->displayFormat ?? NULL,
        'weight' => $this->weight,
      ],
      preview: NULL,
    )->addCacheableDependency($this);
  }

  /**
   * {@inheritdoc}
   *
   * This corresponds to `Color` in openapi.yml.
   *
   * @see docs/adr/0005-Keep-the-front-end-simple.md
   */
  public static function createFromClientSide(array $data): static {
    unset($data['id']);
    return static::create($data);
  }

  /**
   * {@inheritdoc}
   *
   * This corresponds to `Color` in openapi.yml.
   *
   * @see docs/adr/0005-Keep-the-front-end-simple.md
   */
  public function updateFromClientSide(array $data): void {
    unset($data['id']);
    foreach ($data as $key => $value) {
      // Merge nested 'value' array instead of replacing it for partial updates.
      if ($key === 'value' && \is_array($value)) {
        $existing_value = $this->getValue();
        $merged_value = array_merge($existing_value, $value);
        // If color data changed but hex was not explicitly provided, clear hex.
        $color_data_changed = isset($value['components']) || isset($value['colorSpace']);
        $hex_explicitly_provided = \array_key_exists('hex', $value);
        if ($color_data_changed && !$hex_explicitly_provided) {
          $merged_value['hex'] = NULL;
        }
        $this->set($key, $merged_value);
      }
      else {
        $this->set($key, $value);
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function refineListQuery(QueryInterface &$query, RefinableCacheableDependencyInterface $cacheability): void {
    // Nothing to do.
  }

  public function getName(): string {
    return $this->name;
  }

  public function getCssVariable(): string {
    return $this->cssVariable;
  }

  /**
   * Returns the color value in W3C Design Token format.
   *
   * @return array{colorSpace: string, components: list<float>, alpha: float|null, hex: string|null}
   */
  public function getValue(): array {
    return $this->value;
  }

  public function getWeight(): int {
    return $this->weight;
  }

  public function getDisplayFormat(): ?string {
    return $this->displayFormat;
  }

  /**
   * {@inheritdoc}
   */
  public function postSave(EntityStorageInterface $storage, $update = TRUE): void {
    parent::postSave($storage, $update);
    self::regenerateBrandKitAssets();
  }

  /**
   * {@inheritdoc}
   */
  public static function postDelete(EntityStorageInterface $storage, array $entities): void {
    parent::postDelete($storage, $entities);
    self::regenerateBrandKitAssets();
  }

  /**
   * Regenerates BrandKit assets and invalidates caches when colors change.
   *
   * This ensures that CSS files containing color variables are rebuilt
   * whenever a color is created, updated, or deleted.
   */
  private static function regenerateBrandKitAssets(): void {
    $storage = \Drupal::entityTypeManager()->getStorage(BrandKit::ENTITY_TYPE_ID);
    \assert($storage instanceof CanvasAssetStorage);
    foreach ($storage->loadMultiple() as $brand_kit) {
      \assert($brand_kit instanceof CanvasAssetInterface);
      $storage->generateFiles($brand_kit);
    }
    Cache::invalidateTags(['library_info']);
  }

  /**
   * Returns the CSS value for this color.
   *
   * Supports sRGB and HSL color spaces.
   *
   * @return string
   *   The CSS value as hex, rgb, rgba, hsl, or hsla.
   */
  public function getCssValue(): string {
    $colorSpace = $this->value['colorSpace'];
    $components = $this->value['components'];
    $alpha = $this->value['alpha'] ?? NULL;

    switch ($colorSpace) {
      case 'srgb':
        // Components are [R, G, B] each [0-1].
        $r = (int) round($components[0] * 255);
        $g = (int) round($components[1] * 255);
        $b = (int) round($components[2] * 255);

        if ($alpha === NULL || $alpha === 1.0) {
          // Prefer stored hex value when available to maintain consistency with
          // client-side rendering. Fall back to computing from components.
          return $this->value['hex'] ?? \sprintf('#%02x%02x%02x', $r, $g, $b);
        }
        return \sprintf('rgba(%d, %d, %d, %.2f)', $r, $g, $b, $alpha);

      case 'hsl':
        // Components are [H, S, L] where H is [0-360], S and L are [0-100].
        $h = round($components[0]);
        $s = round($components[1]);
        $l = round($components[2]);

        if ($alpha === NULL || $alpha === 1.0) {
          return \sprintf('hsl(%d, %d%%, %d%%)', $h, $s, $l);
        }
        return \sprintf('hsla(%d, %d%%, %d%%, %.2f)', $h, $s, $l, $alpha);

      default:
        // Fallback to hex if available, otherwise first component as gray.
        if (isset($this->value['hex'])) {
          return $this->value['hex'];
        }
        return '#000000';
    }
  }

}
