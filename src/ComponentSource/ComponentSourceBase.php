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

}
