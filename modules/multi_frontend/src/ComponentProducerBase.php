<?php

declare(strict_types=1);

namespace Drupal\multi_frontend;

use Drupal\Core\Plugin\PluginBase;

/**
 * Base class for component producers.
 */
abstract class ComponentProducerBase extends PluginBase implements ComponentProducerInterface {

  /**
   * {@inheritdoc}
   */
  public function produceSlots(mixed $subject, ProducerContext $context): array {
    return [];
  }

  /**
   * Returns the component ID this producer produces props for.
   */
  public function getComponentId(): string {
    $definition = $this->getPluginDefinition();
    \assert(\is_array($definition));
    return $definition['component'];
  }

}
