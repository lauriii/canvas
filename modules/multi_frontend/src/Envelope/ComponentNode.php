<?php

declare(strict_types=1);

namespace Drupal\multi_frontend\Envelope;

use Drupal\Core\Cache\CacheableDependencyInterface;
use Drupal\Core\Cache\CacheableDependencyTrait;

/**
 * A produced component: validated props, and nothing that cannot be JSON.
 */
final class ComponentNode implements EnvelopeNodeInterface {

  use CacheableDependencyTrait;

  /**
   * @param string $componentId
   *   The SDC plugin ID, such as "album:photo".
   * @param array<string, mixed> $props
   *   Props validated against the component's schema.
   * @param array<string, \Drupal\multi_frontend\Envelope\EnvelopeNodeInterface[]> $slots
   *   Slot name to nodes. Slots hold nodes rather than markup, which is how a
   *   converted component composes with one that is not converted yet.
   * @param array<string, string> $attributes
   *   Server-side decoration, as a plain string map. Not a prop: it is not
   *   data about the subject, it does not belong in the props schema, and it
   *   is how UI Styles, HTMX modules and accessibility tooling survive the
   *   crossing.
   * @param \Drupal\Core\Cache\CacheableDependencyInterface $cacheability
   *   Cacheability gathered while the props were produced.
   */
  public function __construct(
    public readonly string $componentId,
    public readonly array $props,
    public readonly array $slots,
    public readonly array $attributes,
    CacheableDependencyInterface $cacheability,
  ) {
    $this->setCacheability($cacheability);
  }

  /**
   * {@inheritdoc}
   */
  public function toArray(): array {
    $slots = [];
    foreach ($this->slots as $name => $nodes) {
      $slots[$name] = \array_map(
        static fn (EnvelopeNodeInterface $node): array => $node->toArray(),
        $nodes,
      );
    }
    return [
      'type' => 'component',
      'component' => $this->componentId,
      'props' => (object) $this->props,
      'slots' => (object) $slots,
      'attributes' => (object) $this->attributes,
      'cacheability' => CacheabilityNormalizer::normalize($this),
    ];
  }

}
