<?php

declare(strict_types=1);

namespace Drupal\multi_frontend;

use Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Plugin\DefaultPluginManager;
use Drupal\multi_frontend\Attribute\ComponentProducer;

/**
 * Discovers and instantiates component producers.
 *
 * Keyed by producer ID, indexed by component ID and by subject, so that one
 * component can be produced from several kinds of subject.
 */
final class ComponentProducerManager extends DefaultPluginManager {

  public function __construct(
    \Traversable $namespaces,
    CacheBackendInterface $cache_backend,
    ModuleHandlerInterface $module_handler,
  ) {
    parent::__construct(
      'Plugin/ComponentProducer',
      $namespaces,
      $module_handler,
      ComponentProducerInterface::class,
      ComponentProducer::class,
    );
    $this->alterInfo('component_producer_info');
    $this->setCacheBackend($cache_backend, 'component_producer_plugins');
  }

  /**
   * {@inheritdoc}
   *
   * @phpstan-ignore-next-line missingType.parameter
   */
  public function processDefinition(&$definition, $plugin_id): void {
    parent::processDefinition($definition, $plugin_id);
    // A producer ID must contain a dot. Reserved path segments such as
    // "schema" are therefore never ambiguous with a producer ID in a URL.
    // @see \Drupal\multi_frontend\Routing\ComponentApiRoutes
    if (!str_contains((string) $plugin_id, '.')) {
      throw new InvalidPluginDefinitionException(
        (string) $plugin_id,
        \sprintf('The component producer "%s" must have an ID containing a dot, such as "album.photo".', $plugin_id),
      );
    }
  }

  /**
   * Returns the entity type ID a producer accepts, if it accepts one.
   */
  public static function getSubjectEntityTypeId(array $definition): ?string {
    $subject = $definition['subject'] ?? '';
    return str_starts_with($subject, 'entity:')
      ? substr($subject, strlen('entity:'))
      : NULL;
  }

}
