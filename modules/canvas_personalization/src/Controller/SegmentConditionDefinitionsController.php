<?php

declare(strict_types=1);

namespace Drupal\canvas_personalization\Controller;

use Drupal\canvas_personalization\SegmentCondition\SegmentConditionManager;
use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Lists the available segment condition types for the authoring UI.
 *
 * Without this, the client has no way to learn that a condition exists: the
 * segments dashboard would have to hard-code the shipped plugin IDs, and a
 * condition provided by a third-party segmentation provider module would be
 * invisible in the product UI even though the server evaluates it correctly.
 *
 * Only non-secret plugin metadata is exposed, and only to users who may
 * administer segments.
 */
final class SegmentConditionDefinitionsController implements ContainerInjectionInterface {

  public function __construct(
    private readonly SegmentConditionManager $segmentConditionManager,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self($container->get(SegmentConditionManager::class));
  }

  public function __invoke(): CacheableJsonResponse {
    $definitions = [];
    foreach ($this->segmentConditionManager->getDefinitions() as $id => $definition) {
      if (!\is_array($definition)) {
        continue;
      }
      $definitions[$id] = [
        'id' => $id,
        'label' => (string) ($definition['label'] ?? $id),
        // Whether the dashboard ships an editor for this condition. Anything
        // else is authored through the per-condition plugin form instead.
        'provider' => (string) ($definition['provider'] ?? ''),
      ];
    }
    \ksort($definitions);

    $response = new CacheableJsonResponse($definitions);
    // Varies only by which modules are installed, which the plugin definition
    // cache tracks; no per-request variation.
    $response->addCacheableDependency((new CacheableMetadata())->setCacheTags(['segment_condition_plugins']));
    return $response;
  }

}
