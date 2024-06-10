<?php

declare(strict_types=1);

namespace Drupal\experience_builder\Entity;

use Drupal\Core\Config\Entity\ConfigEntityBase;
use Drupal\Core\Plugin\Component as ComponentPlugin;
use Drupal\Core\Theme\ComponentPluginManager;

/**
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
 *        "add" = "Drupal\experience_builder\Form\ComponentEditForm",
 *        "edit" = "Drupal\experience_builder\Form\ComponentEditForm",
 *        "delete" = "Drupal\Core\Entity\EntityDeleteForm"
 *      },
 *      "route_provider" = {
 *        "html" = "\Drupal\Core\Entity\Routing\AdminHtmlRouteProvider",
 *      }
 *    },
 *    entity_keys = {
 *      "id" = "component",
 *      "label" = "label",
 *    },
 *    links = {
 *      "edit-form" = "/admin/structure/component/edit/{component}",
 *      "delete-form" = "/admin/structure/component/delete/{component}",
 *      "collection" = "/admin/structure/component",
 *    },
 *    config_export = {
 *      "label",
 *      "component",
 *    }
 *  )
 */
final class Component extends ConfigEntityBase {

  /**
   * The human-readable label of the Experience Builder component.
   */
  protected ?string $label;

  /**
   * Component entity ID, based on component plugin machine name.
   *
   * Component plugin machine names are required to contain `:`, which is an
   * invalid character for a config entity ID. Hence this transformation.
   *
   * @see self::convertMachineNameToId()
   * @see \Drupal\Core\Plugin\Component::$machineName
   */
  protected string $component;

  /**
   * {@inheritdoc}
   */
  public function id() {
    return $this->get('component');
  }

  /**
   * {@inheritdoc}
   */
  public function calculateDependencies() {
    parent::calculateDependencies();

    $plugin_manager = \Drupal::service(ComponentPluginManager::class);
    assert($plugin_manager instanceof ComponentPluginManager);

    $component = $plugin_manager->find($this->getComponentMachineName());
    assert($component instanceof ComponentPlugin);

    $provider = $component->getBaseId();
    if ($this->moduleHandler()->moduleExists($provider)) {
      $this->addDependency('module', $provider);
    }
    elseif ($this->themeHandler()->themeExists($provider)) {
      $this->addDependency('theme', $provider);
    }

    return $this;
  }

  /**
   * @return string
   *
   * @see \Drupal\Core\Plugin\Component::$machineName
   */
  public function getComponentMachineName(): string {
    return self::convertIdToMachineName($this->get('component'));
  }

  /**
   * @param string $component_machine_name
   *
   * @return \Drupal\experience_builder\Entity\Component|null
   *
   * @see \Drupal\Core\Plugin\Component::$machineName
   */
  public static function loadByComponentMachineName(string $component_machine_name) {
    return parent::load(self::convertMachineNameToId($component_machine_name));
  }

  /**
   * The naming convention for components is [module/theme]:[component machine name], so we change '+' back to ':'.
   * @see https://www.drupal.org/docs/develop/theming-drupal/using-single-directory-components/api-for-single-directory-components
   * @see \Drupal\Core\Plugin\Discovery\DirectoryWithMetadataDiscovery::getDirectoryIterator()
   * @see \Drupal\Core\Plugin\Component::$machineName
   */
  public static function convertIdToMachineName(string $id): string {
    return str_replace('+', ':', $id);
  }

  /**
   * The naming convention for components is [module/theme]:[component machine name]. Colon is invalid config entity name, so we replace it with '+'.
   * @see https://www.drupal.org/docs/develop/theming-drupal/using-single-directory-components/api-for-single-directory-components
   *
   * @param string $machine_name
   *
   * @return string
   *
   * @see \Drupal\Core\Plugin\Component::$machineName
   */
  public static function convertMachineNameToId(string $machine_name): string {
    return str_replace(':', '+', $machine_name);
  }

}
