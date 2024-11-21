<?php

declare(strict_types=1);

namespace Drupal\experience_builder\Entity;

use Drupal\Core\Config\Entity\ConfigEntityBase;
use Drupal\Core\Plugin\DefaultSingleLazyPluginCollection;
use Drupal\experience_builder\ComponentSource\ComponentSourceInterface;
use Drupal\experience_builder\ComponentSource\ComponentSourceManager;
use Drupal\experience_builder\Plugin\ExperienceBuilder\ComponentSource\SingleDirectoryComponent;
use Drupal\experience_builder\PropSource\StaticPropSource;

/**
 * @todo Update these docs in https://drupal.org/i/3454519 to reflect changes.
 *
 * A config entity that exposes SDC components and blocks to the Experience Builder UI.
 * 1. There can be only one Component entity per component plugin.
 *
 *
 * @ConfigEntityType(
 *    id = "component",
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
 *      "settings",
 *    },
*     constraints = {
 *      "ImmutableProperties" = {"id", "source"},
 *    }
 *  )
 *
 * @phpstan-type ComponentConfigEntityId string
 */
final class Component extends ConfigEntityBase implements ComponentInterface {

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
  public function getComponentSource(): ComponentSourceInterface {
    return $this->sourcePluginCollection()->get($this->source);
  }

  /**
   * {@inheritdoc}
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
      fn (string $id): string => Component::load($id)?->getComponentSource()?->getComponentPluginDefinition()['class'] ?? '',
      $ids
    ))));
  }

  /**
   * Build the default prop source for a prop.
   *
   * @param string $prop_name
   *   The prop name.
   *
   * @return \Drupal\experience_builder\PropSource\StaticPropSource
   *   The prop source object.
   */
  public function getDefaultStaticPropSource(string $prop_name): StaticPropSource {
    assert(isset($this->settings['props']));
    assert(is_array($this->settings['props']));

    $source = $this->getComponentSource();
    // @todo handle non-SDC plugin sources, see https://www.drupal.org/project/experience_builder/issues/3484666
    assert($source instanceof SingleDirectoryComponent);
    if (!array_key_exists($prop_name, $source->getSchema()['properties'] ?? [])) {
      throw new \OutOfRangeException(sprintf("'%s' is not a prop on the '%s' component.", $prop_name, $this->getComponentPluginId()));
    }

    $sdc_prop_source = [
      'sourceType' => 'static:field_item:' . $this->settings['props'][$prop_name]['field_type'],
      'value' => $this->settings['props'][$prop_name]['default_value'],
      'expression' => $this->settings['props'][$prop_name]['expression'],
    ];
    if (array_key_exists('field_storage_settings', $this->settings['props'][$prop_name])) {
      $sdc_prop_source['sourceTypeSettings']['storage'] = $this->settings['props'][$prop_name]['field_storage_settings'];
    }
    if (array_key_exists('field_instance_settings', $this->settings['props'][$prop_name])) {
      $sdc_prop_source['sourceTypeSettings']['instance'] = $this->settings['props'][$prop_name]['field_instance_settings'];
    }

    return StaticPropSource::parse($sdc_prop_source);
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

  public function getComponentPluginId(): string {
    return $this->settings['plugin_id'];
  }

  /**
   * {@inheritdoc}
   */
  protected function providerExists(string $provider): bool {
    return $this->moduleHandler()->moduleExists($provider) || $this->themeHandler()->themeExists($provider);
  }

}
