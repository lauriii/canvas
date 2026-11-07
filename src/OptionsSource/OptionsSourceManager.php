<?php

declare(strict_types=1);

namespace Drupal\canvas\OptionsSource;

use Drupal\canvas\Attribute\OptionsSource;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Plugin\DefaultPluginManager;

/**
 * Manages the sources of options for component props.
 *
 * @see \Drupal\canvas\OptionsSource\OptionsSourceInterface
 */
final class OptionsSourceManager extends DefaultPluginManager {

  /**
   * @param \Traversable<string, string> $namespaces
   */
  public function __construct(\Traversable $namespaces, CacheBackendInterface $cache_backend, ModuleHandlerInterface $module_handler) {
    parent::__construct(
      'Plugin/Canvas/OptionsSource',
      $namespaces,
      $module_handler,
      OptionsSourceInterface::class,
      OptionsSource::class,
    );
    $this->alterInfo('canvas_options_source_info');
    $this->setCacheBackend($cache_backend, 'canvas_options_sources');
  }

  /**
   * Creates the source that a prop's `x-canvas-options` configures.
   *
   * @param array<string, mixed> $options
   *   The value of `x-canvas-options`.
   *
   * @throws \Drupal\Component\Plugin\Exception\PluginException
   */
  public function createFromPropSchema(array $options): OptionsSourceInterface {
    $source = $options['source'] ?? NULL;
    unset($options['source']);
    $instance = $this->createInstance((string) $source, $options);
    \assert($instance instanceof OptionsSourceInterface);
    return $instance;
  }

  /**
   * Validates a prop's `x-canvas-options`.
   *
   * @return list<string>
   *   Problems, empty if it is valid.
   */
  public function validatePropSchema(mixed $options): array {
    if (!\is_array($options) || !\is_string($options['source'] ?? NULL)) {
      return ['Must be a map with a `source`, such as `{ source: entity, entity_type: taxonomy_term }`.'];
    }
    if (!$this->hasDefinition($options['source'])) {
      return [\sprintf('The source "%s" does not exist. Available sources: %s.', $options['source'], implode(', ', \array_keys($this->getDefinitions())))];
    }
    return $this->createFromPropSchema($options)->validateConfiguration();
  }

}
