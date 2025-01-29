<?php

declare(strict_types=1);

namespace Drupal\experience_builder\Entity;

use Drupal\Core\Config\Entity\ConfigEntityBase;
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
 *      "form" = {
 *        "delete" = "Drupal\Core\Entity\EntityDeleteForm"
 *      },
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
 *      "delete-form" = "/admin/structure/component/delete/{id}",
 *      "collection" = "/admin/structure/component",
 *      "enable" = "/admin/structure/component/{id}/enable",
 *      "disable" = "/admin/structure/component/{id}/disable",
 *    },
 *    config_export = {
 *      "label",
 *      "id",
 *      "source",
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
   * {@inheritdoc}
   */
  public function calculateDependencies() {
    parent::calculateDependencies();

    // @todo parent method seems to do this anyway, fix in https://www.drupal.org/project/experience_builder/issues/3484673
    $dependencies = $this->getComponentSource()->getDependencies($this->settings);
    foreach ($dependencies as $type => $providers) {
      foreach ($providers as $provider) {
        if ($this->providerExists($provider)) {
          $this->addDependency($type, $provider);
        }
      }
    }

    return $this;
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
   * {@inheritdoc}
   */
  protected function providerExists(string $provider): bool {
    return $this->moduleHandler()->moduleExists($provider) || $this->themeHandler()->themeExists($provider);
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

    $info += [
      'id' => $this->id(),
      'name' => (string) $this->label(),
      'category' => (string) $this->getCategory(),
      'source' => (string) $this->getComponentSource()->getPluginDefinition()['label'],
    ];

    return ClientSideRepresentation::create(
      values: $info + [
        'id' => $this->id(),
        'name' => (string) $this->label(),
        'category' => (string) $this->getCategory(),
        'source' => (string) $this->getComponentSource()->getPluginDefinition()['label'],
      ],
      preview: $build,
    )->addCacheableDependency($this);
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
