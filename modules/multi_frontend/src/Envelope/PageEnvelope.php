<?php

declare(strict_types=1);

namespace Drupal\multi_frontend\Envelope;

use Drupal\Core\Cache\CacheableDependencyInterface;
use Drupal\Core\Cache\CacheableDependencyTrait;

/**
 * A page: metadata, regions of nodes, and the union of their cacheability.
 */
final class PageEnvelope implements CacheableDependencyInterface {

  use CacheableDependencyTrait;

  /**
   * @param array<string, mixed> $page
   *   Page metadata: title, langcode, layout.
   * @param array<string, \Drupal\multi_frontend\Envelope\EnvelopeNodeInterface[]> $regions
   *   Region name to nodes. Only "content" is populated in this
   *   implementation; the remaining regions come from the active theme's
   *   block layout, which is theme-scoped config and is deliberately not
   *   reimplemented here.
   */
  public function __construct(
    public readonly array $page,
    public readonly array $regions,
    CacheableDependencyInterface $cacheability,
  ) {
    $this->setCacheability($cacheability);
  }

  /**
   * Returns the envelope as a plain, JSON-serializable array.
   *
   * @return array<string, mixed>
   *   The envelope.
   */
  public function toArray(): array {
    $regions = [];
    foreach ($this->regions as $name => $nodes) {
      $regions[$name] = \array_map(
        static fn (EnvelopeNodeInterface $node): array => $node->toArray(),
        $nodes,
      );
    }
    return [
      'page' => (object) $this->page,
      'regions' => (object) $regions,
      'cacheability' => CacheabilityNormalizer::normalize($this),
    ];
  }

}
