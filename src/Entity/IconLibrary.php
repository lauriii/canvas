<?php

declare(strict_types=1);

namespace Drupal\canvas\Entity;

use Drupal\canvas\ClientSideRepresentation;
use Drupal\canvas\EntityHandlers\ContentCreatorVisibleCanvasConfigEntityAccessControlHandler;
use Drupal\Component\Plugin\Discovery\CachedDiscoveryInterface;
use Drupal\Core\Cache\RefinableCacheableDependencyInterface;
use Drupal\Core\Config\Entity\ConfigEntityBase;
use Drupal\Core\Entity\Attribute\ConfigEntityType;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Theme\Icon\IconCollector;
use Drupal\Core\Theme\Icon\Plugin\IconPackManagerInterface;
use Drupal\Core\TypedData\ComplexDataInterface;
use Drupal\Core\TypedData\TypedDataInterface;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * An icon library: a Canvas-managed icon pack built from uploaded SVG files.
 *
 * Each icon library is exposed to the core Icon API as an icon pack whose id
 * is the entity id, via hook_icon_pack_alter().
 *
 * @phpstan-type IconAssetEntry array{name: string, uri: string}
 *
 * @see \Drupal\canvas\Hook\IconPackHooks::iconPackAlter()
 */
#[ConfigEntityType(
  id: self::ENTITY_TYPE_ID,
  label: new TranslatableMarkup('Icon library'),
  label_singular: new TranslatableMarkup('icon library'),
  label_plural: new TranslatableMarkup('icon libraries'),
  label_collection: new TranslatableMarkup('Icon libraries'),
  admin_permission: self::ADMIN_PERMISSION,
  handlers: [
    'access' => ContentCreatorVisibleCanvasConfigEntityAccessControlHandler::class,
  ],
  entity_keys: [
    'id' => 'id',
    'label' => 'label',
  ],
  links: [],
  config_export: [
    'id',
    'label',
    'description',
    'template',
    'assets',
  ],
)]
final class IconLibrary extends ConfigEntityBase implements CanvasHttpApiEligibleConfigEntityInterface {

  public const string ENTITY_TYPE_ID = 'icon_library';
  public const string ADMIN_PERMISSION = 'administer icon libraries';
  public const string FILE_USAGE_TYPE = 'icon_library';
  public const string ASSETS_DIRECTORY = 'public://canvas/icons/';

  /**
   * The Twig template used when an icon library does not define its own.
   *
   * The `attributes` variable carries the original `<svg>` element attributes
   * (viewBox, xmlns, fill, …) as provided by core's `svg` extractor.
   */
  public const string DEFAULT_TEMPLATE = '<svg {{ attributes }} width="{{ size|default(24) }}" height="{{ size|default(24) }}">{{ content }}</svg>';

  protected string $id;

  /**
   * The human-readable label of the icon library.
   */
  protected ?string $label;

  /**
   * The human-readable description of the icon library.
   */
  protected ?string $description = NULL;

  /**
   * The Twig template used to render icons, NULL means DEFAULT_TEMPLATE.
   */
  protected ?string $template = NULL;

  /**
   * The uploaded SVG files that make up this icon library.
   *
   * @var list<IconAssetEntry>|null
   */
  protected ?array $assets = NULL;

  /**
   * Returns the Twig template used to render icons in this library.
   */
  public function getTemplate(): string {
    return $this->template ?? self::DEFAULT_TEMPLATE;
  }

  /**
   * Returns the directory that must contain all of this library's SVG files.
   */
  public function getAssetsDirectory(): string {
    return self::ASSETS_DIRECTORY . $this->id() . '/';
  }

  /**
   * @return list<IconAssetEntry>
   */
  public function getAssets(): array {
    return $this->assets ?? [];
  }

  /**
   * @param list<array{name: string, uri: string}>|null $entries
   *   The asset entries; unknown keys (such as the client-side computed `url`)
   *   are dropped.
   */
  public function setAssets(?array $entries): void {
    if ($entries === NULL || $entries === []) {
      $this->assets = NULL;
      return;
    }
    $this->assets = \array_map(
      static fn (array $entry): array => [
        'name' => (string) $entry['name'],
        'uri' => (string) $entry['uri'],
      ],
      \array_values($entries),
    );
  }

  /**
   * {@inheritdoc}
   *
   * This corresponds to `IconLibrary` in openapi.yml.
   *
   * @see docs/adr/0005-Keep-the-front-end-simple.md
   */
  public function normalizeForClientSide(): ClientSideRepresentation {
    $file_url_generator = \Drupal::service(FileUrlGeneratorInterface::class);
    \assert($file_url_generator instanceof FileUrlGeneratorInterface);

    return ClientSideRepresentation::create(
      values: [
        'id' => $this->id,
        'label' => $this->label,
        'description' => $this->description,
        'template' => $this->template,
        'assets' => $this->assets === NULL
          ? NULL
          : \array_map(
            static fn (array $entry): array => [
              ...$entry,
              'url' => $file_url_generator->generateString((string) $entry['uri']),
            ],
            $this->assets,
        ),
      ],
      preview: NULL,
    );
  }

  /**
   * {@inheritdoc}
   *
   * This corresponds to `IconLibrary` in openapi.yml.
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
   * This corresponds to `IconLibrary` in openapi.yml.
   *
   * @see docs/adr/0005-Keep-the-front-end-simple.md
   */
  public function updateFromClientSide(array $data): void {
    foreach ($data as $key => $value) {
      match ($key) {
        'assets' => $this->setAssets(\is_array($value) ? \array_values($value) : NULL),
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
    $original = $update ? $this->getOriginal() : NULL;
    BrandKit::syncFontFileUsage(
      $original instanceof self ? self::getAssetUris($original) : [],
      self::getAssetUris($this),
      self::FILE_USAGE_TYPE,
      (string) $this->id(),
    );
    self::invalidateIconCaches();
  }

  /**
   * {@inheritdoc}
   */
  public static function postDelete(EntityStorageInterface $storage, array $entities): void {
    parent::postDelete($storage, $entities);
    foreach ($entities as $entity) {
      if ($entity instanceof self) {
        BrandKit::clearFontFileUsage(
          self::getAssetUris($entity),
          self::FILE_USAGE_TYPE,
          (string) $entity->id(),
        );
      }
    }
    self::invalidateIconCaches();
  }

  /**
   * Ensures the corresponding icon pack is (un)registered and re-discovered.
   *
   * Clearing the icon pack plugin manager's cached definitions also
   * invalidates the `icon_pack_plugin` and `icon_pack_collector` cache tags,
   * which covers both the icon collector's persistent cache and the icons
   * listing HTTP API response. The `config:icon_library_list` cache tag is
   * invalidated by Drupal core on entity save and delete.
   *
   * @see \Drupal\Core\Theme\Icon\Plugin\IconPackManager::__construct()
   * @see \Drupal\Core\Entity\EntityBase::invalidateTagsOnSave()
   * @see \Drupal\canvas\Controller\ApiIconsController::list()
   */
  private static function invalidateIconCaches(): void {
    $icon_pack_manager = \Drupal::service('plugin.manager.icon_pack');
    \assert($icon_pack_manager instanceof CachedDiscoveryInterface);
    $icon_pack_manager->clearCachedDefinitions();
    // The icon collector's per-request static cache is not covered by the tag
    // invalidation above; reset it so icons resolved earlier in this request
    // do not go stale.
    $icon_collector = \Drupal::service(IconCollector::class);
    \assert($icon_collector instanceof IconCollector);
    $icon_collector->reset();
  }

  /**
   * @return list<string>
   */
  private static function getAssetUris(self $entity): array {
    return \array_values(\array_unique(\array_map(
      static fn (array $entry): string => (string) $entry['uri'],
      $entity->getAssets(),
    )));
  }

  /**
   * Validates that the entity id does not collide with an extension's pack.
   *
   * The entity id doubles as the icon pack id, so it must not collide with an
   * icon pack provided by an installed extension. Packs registered from icon
   * library config entities use the `canvas` provider and are skipped: they
   * are this entity (on update) or covered by entity id uniqueness.
   *
   * @param string|null $id
   *   The entity id being validated.
   * @param \Symfony\Component\Validator\Context\ExecutionContextInterface $context
   *   The validation execution context.
   *
   * @see \Drupal\canvas\Hook\IconPackHooks::iconPackAlter()
   * @see config/schema/canvas.schema.yml
   */
  public static function validateIconPackIdCollision(?string $id, ExecutionContextInterface $context): void {
    if ($id === NULL || $id === '') {
      return;
    }
    $icon_pack_manager = \Drupal::service('plugin.manager.icon_pack');
    \assert($icon_pack_manager instanceof IconPackManagerInterface);
    $definition = $icon_pack_manager->getDefinitions()[$id] ?? NULL;
    $provider = \is_array($definition) ? ($definition['provider'] ?? NULL) : NULL;
    if ($provider !== NULL && $provider !== 'canvas') {
      $context->addViolation('This ID is already used by an icon pack provided by an installed extension.');
    }
  }

  /**
   * Validates persisted asset entries.
   *
   * @param array|null $assets
   *   The asset entries being validated.
   * @param \Symfony\Component\Validator\Context\ExecutionContextInterface $context
   *   The validation execution context.
   *
   * @see config/schema/canvas.schema.yml
   */
  public static function validateAssets(?array $assets, ExecutionContextInterface $context): void {
    if ($assets === NULL) {
      return;
    }

    // Determine the entity id from the parent mapping, to enforce that all
    // asset URIs live in this icon library's own directory.
    $expected_uri_prefix = NULL;
    $assets_typed_data = $context->getObject();
    $mapping = $assets_typed_data instanceof TypedDataInterface ? $assets_typed_data->getParent() : NULL;
    if ($mapping instanceof ComplexDataInterface) {
      $id = $mapping->get('id')->getValue();
      if (\is_string($id) && $id !== '') {
        $expected_uri_prefix = self::ASSETS_DIRECTORY . $id . '/';
      }
    }

    foreach (\array_values($assets) as $index => $entry) {
      $name = \is_array($entry) ? ($entry['name'] ?? NULL) : NULL;
      if (!\is_string($name) || !\preg_match('/^[a-zA-Z0-9._-]+\.svg$/', $name)) {
        $context
          ->buildViolation('Asset names must consist of letters, numbers, dots, dashes, and underscores, and use the .svg extension.')
          ->atPath("[$index][name]")
          ->addViolation();
      }
      $uri = \is_array($entry) ? ($entry['uri'] ?? NULL) : NULL;
      if ($expected_uri_prefix !== NULL && \is_string($uri) && !\str_starts_with($uri, $expected_uri_prefix)) {
        $context
          ->buildViolation("Asset URIs must be located in this icon library's directory, %directory.", ['%directory' => $expected_uri_prefix])
          ->atPath("[$index][uri]")
          ->addViolation();
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function toArray(): array {
    $properties = parent::toArray();
    // Omit NULL properties entirely: `assets` must satisfy its NotBlank
    // constraint, and omitting NULL `description` and `template` keeps config
    // exports minimal.
    foreach (['description', 'template', 'assets'] as $key) {
      if ($properties[$key] === NULL) {
        unset($properties[$key]);
      }
    }
    return $properties;
  }

}
