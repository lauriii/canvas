<?php

declare(strict_types=1);

namespace Drupal\experience_builder\Plugin;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Theme\ComponentPluginManager as CoreComponentPluginManager;
use Drupal\experience_builder\Entity\Component;

/**
 * Decorator that auto-creates/updates an Experience Builder Component entity per SDC.
 *
 * @see \Drupal\experience_builder\Entity\Component
 */
class ComponentPluginManager extends CoreComponentPluginManager {

  protected EntityTypeManagerInterface $entityTypeManager;

  public function setEntityTypeManager(EntityTypeManagerInterface $entityTypeManager): void {
    $this->entityTypeManager = $entityTypeManager;
  }

  /**
   * {@inheritdoc}
   */
  protected function setCachedDefinitions($definitions): array {
    parent::setCachedDefinitions($definitions);

    $components = $this->entityTypeManager->getStorage('component')->loadMultiple();
    foreach ($definitions as $machine_name => $configuration) {
      if (!self::componentMeetsRequirements($configuration)) {
        continue;
      }

      $component_plugin = $this->createInstance($machine_name, $configuration);
      if (array_key_exists(Component::convertMachineNameToId($machine_name), $components)) {
        $component = Component::updateFromComponentPlugin($component_plugin);
      }
      else {
        $component = Component::createFromComponentPlugin($component_plugin);
      }
      $component->save();
    }

    return $definitions;
  }

  public static function componentMeetsRequirements(array $configuration): bool {
    if (isset($configuration['props']['required'])) {
      foreach ($configuration['props']['required'] as $prop) {
        // Every required prop must have >=1 example.
        if (empty($configuration['props']['properties'][$prop]['examples'])) {
          return FALSE;
        }
      }
    }
    foreach ($configuration['props']['properties'] as $prop) {
      // Every prop must have a title.
      if (!isset($prop['title'])) {
        return FALSE;
      }
      // @todo All props must have StorablePropShape: https://www.drupal.org/project/experience_builder/issues/3469461
    }
    return TRUE;
  }

}
