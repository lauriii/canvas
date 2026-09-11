<?php

declare(strict_types=1);

namespace Drupal\multi_frontend\Envelope;

use Drupal\Core\Cache\CacheableDependencyInterface;
use Drupal\Core\Cache\CacheableDependencyTrait;

/**
 * Rendered markup for a subtree that has not been converted.
 *
 * This is the graceful-degradation half of the contract and the reason the
 * change can be introduced gradually: an unconverted module keeps working,
 * and its output is explicitly opaque rather than pretending to be typed.
 * It is also countable, which is what turns degradation into a ratchet
 * instead of an excuse.
 */
final class HtmlNode implements EnvelopeNodeInterface {

  use CacheableDependencyTrait;

  public function __construct(
    public readonly string $markup,
    CacheableDependencyInterface $cacheability,
  ) {
    $this->setCacheability($cacheability);
  }

  /**
   * {@inheritdoc}
   */
  public function toArray(): array {
    return [
      'type' => 'html',
      'markup' => $this->markup,
      'cacheability' => CacheabilityNormalizer::normalize($this),
    ];
  }

}
