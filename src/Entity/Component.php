<?php

declare(strict_types=1);

namespace Drupal\experience_builder\Entity;

use Drupal\Core\Cache\RefinableCacheableDependencyInterface;
use Drupal\Core\Config\Entity\ConfigEntityBase;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Extension\ThemeHandlerInterface;
use Drupal\Core\Plugin\DefaultSingleLazyPluginCollection;
use Drupal\Core\Render\Markup;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\experience_builder\ClientSideRepresentation;
use Drupal\experience_builder\ComponentSource\ComponentSourceInterface;
use Drupal\experience_builder\ComponentSource\ComponentSourceManager;

/**
 * @todo Update these docs in https://drupal.org/i/3454519 to reflect changes.
 *
 * A config entity that exposes SDC components and blocks to the Experience Builder UI.
 * 1. There can be only one Component entity per component plugin.
 *
 *
 * @ConfigEntityType(
 *    id = \Drupal\experience_builder\Entity\Component::ENTITY_TYPE_ID,
 *    label = @Translation("Component"),
 *    label_singular = @Translation("component"),
 *    label_plural = @Translation("components"),
 *    label_collection = @Translation("Components"),
 *    admin_permission = "administer components",
 *    handlers = {
 *      "list_builder" = "Drupal\experience_builder\Form\ComponentListBuilder",
 *      "route_provider" = {
 *        "html" = "\Drupal\Core\Entity\Routing\AdminHtmlRouteProvider",
 *      }
 *    },
 *    entity_keys = {
 *      "id" = "id",
 *      "label" = "label",
 *      "status" = "status",
 *    },
 *    links = {
 *      "collection" = "/admin/appearance/component",
 *      "enable" = "/admin/appearance/component/{id}/enable",
 *      "disable" = "/admin/appearance/component/{id}/disable",
 *    },
 *    config_export = {
 *      "label",
 *      "id",
 *      "source",
 *      "provider",
 *      "category",
 *      "settings",
 *    },
*     constraints = {
 *      "ImmutableProperties" = {"id", "source"},
 *    }
 *  )
 *
 * @phpstan-type ComponentConfigEntityId string
 */
final class Component extends ConfigEntityBase implements ComponentInterface, XbHttpApiEligibleConfigEntityInterface {

  public const ENTITY_TYPE_ID = 'component';

  /**
   * The component entity ID.
   */
  protected string $id;

  /**
   * The human-readable label of the component.
   */
  protected ?string $label;

  /**
   * The source plugin ID.
   */
  protected string $source;

  /**
   * The provider of this component: a valid module or theme name, or NULL.
   *
   * NULL must be used to signal it's not provided by an extension. This is used
   * for "code components" for example — which are provided by entities.
   *
   * @see \Drupal\experience_builder\Plugin\ExperienceBuilder\ComponentSource\JsComponent
   */
  protected ?string $provider;

  /**
   * The human-readable category of the component.
   */
  protected string|TranslatableMarkup|null $category;

  /**
   * The source plugin settings.
   */
  protected array $settings = [];

  /**
   * Holds the plugin collection for the source plugin.
   */
  protected ?DefaultSingleLazyPluginCollection $sourcePluginCollection = NULL;

  /**
   * {@inheritdoc}
   */
  public function id(): string {
    return $this->id;
  }

  /**
   * {@inheritdoc}
   */
  public function getCategory(): string|TranslatableMarkup {
    // TRICKY: this PHP class allows this value to be `NULL` to avoid
    // \Drupal\Core\Config\Entity\ConfigEntityBase::set() triggering a PHP Type
    // error. Fortunately, all XB config entities have strict config schema
    // validation. Thanks to validation, NULL is absent from the return type.
    assert($this->category !== NULL);
    return $this->category;
  }

  /**
   * {@inheritdoc}
   */
  public function getComponentSource(): ComponentSourceInterface {
    return $this->sourcePluginCollection()->get($this->source);
  }

  /**
   * {@inheritdoc}
   *
   * @throws \Drupal\Core\Config\Schema\SchemaIncompleteException
   */
  public function save() {
    return parent::save();
  }

  /**
   * Gets the unique (plugin) interfaces for passed Component config entity IDs.
   *
   * @param array<ComponentConfigEntityId> $ids
   *   A list of (unique) Component config entity IDs.
   *
   * @return string[]
   *   The corresponding list of PHP FQCNs. Depending on the component type,
   *   this may be one unique class per Component config entity (ID), or the
   *   same class for all.
   *   For example: all SDC-sourced XB Components use the same (plugin) class
   *   (and even interface) interface, but every Block plugin-sourced XB
   *   Components has a unique (plugin) class, and often even a unique (plugin)
   *   interface.
   *   @see \Drupal\Core\Theme\ComponentPluginManager::$defaults
   */
  public static function getClasses(array $ids): array {
    return array_values(array_unique(array_filter(array_map(
      static fn (Component $component): ?string => $component->getComponentSource()->getReferencedPluginClass(),
      Component::loadMultiple($ids)
    ))));
  }

  /**
   * Returns the source plugin collection.
   */
  private function sourcePluginCollection(): DefaultSingleLazyPluginCollection {
    if (is_null($this->sourcePluginCollection)) {
      $this->sourcePluginCollection = new DefaultSingleLazyPluginCollection(\Drupal::service(ComponentSourceManager::class), $this->source, $this->settings);
    }
    return $this->sourcePluginCollection;
  }

  /**
   * {@inheritdoc}
   */
  public function getPluginCollections(): array {
    return array_filter([
      'settings' => $this->sourcePluginCollection(),
    ]);
  }

  /**
   * Works around the `ExtensionExists` constraint requiring a fixed type.
   *
   * @see \Drupal\Core\Extension\Plugin\Validation\Constraint\ExtensionExistsConstraintValidator
   * @see https://www.drupal.org/node/3353397
   */
  public static function providerExists(?string $provider): bool {
    if (is_null($provider)) {
      return TRUE;
    }
    $container = \Drupal::getContainer();
    return $container->get(ModuleHandlerInterface::class)->moduleExists($provider) || $container->get(ThemeHandlerInterface::class)->themeExists($provider);
  }

  /**
   * {@inheritdoc}
   *
   * Override the parent to enforce the string return type.
   *
   * @see \Drupal\Core\Entity\EntityStorageBase::create
   */
  public function uuid(): string {
    /** @var string */
    return parent::uuid();
  }

  /**
   * {@inheritdoc}
   *
   * This corresponds to `Component` in openapi.yml.
   *
   * @see ui/src/types/Component.ts
   * @see docs/adr/0005-Keep-the-front-end-simple.md
   */
  public function normalizeForClientSide(): ClientSideRepresentation {
    $info = $this->getComponentSource()->getClientSideInfo($this);

    $build = $info['build'];
    unset($info['build']);

    // Wrap each rendered component instance in HTML comments that allow the
    // client side to identify it.
    // @see \Drupal\experience_builder\Plugin\DataType\ComponentTreeHydrated::renderify()
    $component_config_entity_uuid = $this->uuid();
    $build['#prefix'] = Markup::create("<!-- xb-start-$component_config_entity_uuid -->");
    $build['#suffix'] = Markup::create("<!-- xb-end-$component_config_entity_uuid -->");
    return ClientSideRepresentation::create(
      values: $info + [
        'id' => $this->id(),
        'name' => (string) $this->label(),
        'library' => $this->computeUiLibrary()->value,
        'category' => (string) $this->getCategory(),
        'source' => (string) $this->getComponentSource()->getPluginDefinition()['label'],
      ],
      preview: $build,
    )->addCacheableDependency($this);
  }

  /**
   * Uses heuristics to compute the appropriate "library" in the XB UI.
   *
   * Each Component appears in a well-defined "library" in the XB UI. This is a
   * set of heuristics with a particular decision tree.
   *
   * @see https://www.drupal.org/project/experience_builder/issues/3498419#comment-15997505
   */
  private function computeUiLibrary(): LibraryEnum {
    $config = \Drupal::configFactory()->loadMultiple(['core.extension', 'system.theme']);
    $installed_modules = [
      'core',
      ...array_keys($config['core.extension']->get('module')),
    ];
    // @see \Drupal\Core\Extension\ThemeHandler::getDefault()
    $default_theme = $config['system.theme']->get('default');

    // 1. Is the component dynamic (consumes implicit inputs/context or has
    // logic)?
    if ($this->getComponentSource()->getPluginDefinition()['supportsImplicitInputs']) {
      return LibraryEnum::DynamicComponents;
    }

    // 2. Is the component provided by a module?
    if (in_array($this->provider, $installed_modules, TRUE)) {
      return $this->provider === 'experience_builder'
        // 2.B Is the providing module XB?
        ? LibraryEnum::Elements
        : LibraryEnum::ExtensionComponents;
    }

    // 3. Is the component provided by the default theme (or its base theme)?
    if ($this->provider === $default_theme) {
      return LibraryEnum::PrimaryComponents;
    }

    // 4. Is the component provided by neither a theme nor a module?
    if ($this->provider === NULL) {
      return LibraryEnum::PrimaryComponents;
    }

    throw new \LogicException('A Component is being normalized that belongs in no XB UI library.');
  }

  /**
   * {@inheritdoc}
   *
   * @see docs/config-management.md#3.1
   */
  public static function denormalizeFromClientSide(array $data): array {
    throw new \LogicException('Not supported: read-only for the client side, mutable only on the server side.');
  }

  /**
   * {@inheritdoc}
   */
  public static function refineListQuery(QueryInterface &$query, RefinableCacheableDependencyInterface $cacheability): void {
    $container = \Drupal::getContainer();
    $theme_handler = $container->get(ThemeHandlerInterface::class);
    $installed_themes = array_keys($theme_handler->listInfo());
    $default_theme = $theme_handler->getDefault();

    // Omit Components provided by installed-but-not-default themes. This keeps
    // all other Components:
    // - module-provided ones
    // - default theme-provided
    // - provided by something else than an extension, such as an entity.
    $or_group = $query->orConditionGroup()
      ->condition('provider', operator: 'NOT IN', value: array_diff($installed_themes, [$default_theme]))
      ->condition('provider', operator: 'IS NULL');
    $query->condition($or_group);

    // Reflect the conditions added to the query in the cacheability.
    $cacheability->addCacheTags([
      // The set of installed themes is stored in the `core.extension` config.
      'config:core.extension',
      // The default theme is stored in the `system.theme` config.
      'config:system.theme',
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function getSettings(): array {
    return $this->settings;
  }

  /**
   * {@inheritdoc}
   */
  public function setSettings(array $settings): self {
    $this->settings = $settings;
    // Reset the source plugin collection.
    $this->sourcePluginCollection?->setConfiguration($this->settings);
    return $this;
  }

}
