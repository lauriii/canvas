<?php

declare(strict_types=1);

namespace Drupal\canvas_personalization\Controller;

use Drupal\canvas_personalization\SegmentCondition\EnumeratesAudiencesInterface;
use Drupal\canvas_personalization\SegmentCondition\SegmentConditionManager;
use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Utility\Error;
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
    private readonly LoggerChannelInterface $logger,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get(SegmentConditionManager::class),
      $container->get('logger.channel.canvas_personalization'),
    );
  }

  /**
   * How long a listing that carries provider audiences stays fresh.
   *
   * Audiences live in someone else's product and can change at any time, so a
   * listing that includes them cannot be cached on module state alone.
   */
  private const int AUDIENCES_MAX_AGE = 300;

  public function __invoke(): CacheableJsonResponse {
    $definitions = [];
    $has_audiences = FALSE;
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
      $audiences = $this->audiences($id);
      if ($audiences !== NULL) {
        $definitions[$id]['audiences'] = $audiences;
        $has_audiences = TRUE;
      }
    }
    \ksort($definitions);

    $response = new CacheableJsonResponse($definitions);
    $cacheability = (new CacheableMetadata())->setCacheTags(['segment_condition_plugins']);
    if ($has_audiences) {
      $cacheability->setCacheMaxAge(self::AUDIENCES_MAX_AGE);
    }
    // Otherwise this varies only by which modules are installed, which the
    // plugin definition cache tracks; no per-request variation.
    $response->addCacheableDependency($cacheability);
    return $response;
  }

  /**
   * The audiences a condition offers, or NULL if it does not offer a list.
   *
   * A provider that cannot be reached returns nothing to choose from rather
   * than failing the whole listing: the authoring UI must still open, with the
   * audience typed by hand, when the provider is down.
   */
  private function audiences(string $plugin_id): ?array {
    try {
      $condition = $this->segmentConditionManager->createInstance($plugin_id);
    }
    catch (\Throwable) {
      return NULL;
    }
    if (!$condition instanceof EnumeratesAudiencesInterface) {
      return NULL;
    }
    try {
      $audiences = $condition->listAudiences();
    }
    catch (\Throwable $exception) {
      // ::listAudiences() is documented as not throwing, but a provider SDK
      // reaching the network is not something to take on faith in a controller
      // that has to keep answering.
      Error::logException($this->logger, $exception, 'Listing audiences for the %plugin_id segment condition failed.', ['%plugin_id' => $plugin_id]);
      return [];
    }
    return \array_map(strval(...), $audiences);
  }

}
