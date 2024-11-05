<?php

declare(strict_types=1);

namespace Drupal\experience_builder\ComponentSource;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Plugin\DefaultPluginManager;
use Drupal\experience_builder\Attribute\ComponentSource;

/**
 * Defines a plugin manager for component source plugins.
 *
 * @see \Drupal\experience_builder\Attribute\ComponentSource
 * @see \Drupal\experience_builder\ComponentSource\ComponentSourceInterface
 * @see \Drupal\experience_builder\ComponentSource\ComponentSourceBase
 */
final class ComponentSourceManager extends DefaultPluginManager {

  /**
   * @param \Traversable<string, string> $namespaces
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache_backend
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   */
  public function __construct(\Traversable $namespaces, CacheBackendInterface $cache_backend, ModuleHandlerInterface $module_handler) {
    parent::__construct(
      'Plugin/ExperienceBuilder/ComponentSource',
      $namespaces,
      $module_handler,
      ComponentSourceInterface::class,
      ComponentSource::class
    );
    $this->alterInfo('experience_builder_component_source');
    $this->setCacheBackend($cache_backend, 'experience_builder_component_source');
  }

}
