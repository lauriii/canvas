<?php

declare(strict_types=1);

namespace Drupal\experience_builder\ComponentSource;

use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Plugin\ContextAwarePluginAssignmentTrait;
use Drupal\Core\Plugin\ContextAwarePluginTrait;
use Drupal\Core\Plugin\PluginBase;

/**
 * Defines a base class for component source plugins.
 *
 * @see \Drupal\experience_builder\Attribute\ComponentSource
 * @see \Drupal\experience_builder\ComponentSource\ComponentSourceInterface
 * @see \Drupal\experience_builder\ComponentSource\ComponentSourceManager
 */
abstract class ComponentSourceBase extends PluginBase implements ComponentSourceInterface {

  use ContextAwarePluginAssignmentTrait;
  use ContextAwarePluginTrait;

  /**
   * {@inheritdoc}
   */
  public function getConfiguration(): array {
    return $this->configuration;
  }

  /**
   * {@inheritdoc}
   */
  public function setConfiguration(array $configuration): void {
    $this->configuration = NestedArray::mergeDeep($this->defaultConfiguration(), $configuration);
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function getPluginDefinition(): array {
    $definition = parent::getPluginDefinition();
    assert(is_array($definition));
    return $definition;
  }

  /**
   * Returns the plugin dependencies being removed.
   *
   * The function recursively computes the intersection between all plugin
   * dependencies and all removed dependencies.
   *
   * Note: The two arguments do not have the same structure.
   *
   * @param array[] $plugin_dependencies
   *   A list of dependencies having the same structure as the return value of
   *   ConfigEntityInterface::calculateDependencies().
   * @param array[] $removed_dependencies
   *   A list of dependencies having the same structure as the input argument of
   *   ConfigEntityInterface::onDependencyRemoval().
   *
   * @return array
   *   A recursively computed intersection.
   *
   * @see \Drupal\Core\Config\Entity\ConfigEntityInterface::calculateDependencies()
   * @see \Drupal\Core\Config\Entity\ConfigEntityInterface::onDependencyRemoval()
   * @see \Drupal\Core\Entity\EntityDisplayBase::getPluginRemovedDependencies()
   * @todo Remove this verbatim copy of \Drupal\Core\Entity\EntityDisplayBase::getPluginRemovedDependencies() and move it to a trait in Drupal core.
   */
  protected function getPluginRemovedDependencies(array $plugin_dependencies, array $removed_dependencies) {
    $intersect = [];
    foreach ($plugin_dependencies as $type => $dependencies) {
      if ($removed_dependencies[$type]) {
        // Config and content entities have the dependency names as keys while
        // module and theme dependencies are indexed arrays of dependency names.
        // @see \Drupal\Core\Config\ConfigManager::callOnDependencyRemoval()
        if (in_array($type, ['config', 'content'])) {
          $removed = array_intersect_key($removed_dependencies[$type], array_flip($dependencies));
        }
        else {
          $removed = array_values(array_intersect($removed_dependencies[$type], $dependencies));
        }
        if ($removed) {
          $intersect[$type] = $removed;
        }
      }
    }
    return $intersect;
  }

}
