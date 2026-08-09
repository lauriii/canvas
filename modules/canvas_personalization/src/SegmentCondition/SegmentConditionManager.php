<?php

declare(strict_types=1);

namespace Drupal\canvas_personalization\SegmentCondition;

use Drupal\canvas_personalization\Attribute\SegmentCondition;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Plugin\DefaultPluginManager;

/**
 * Defines a plugin manager for segment condition plugins.
 *
 * @see \Drupal\canvas_personalization\Attribute\SegmentCondition
 * @see \Drupal\canvas_personalization\SegmentCondition\SegmentConditionInterface
 * @see \Drupal\canvas_personalization\SegmentCondition\SegmentConditionBase
 */
final class SegmentConditionManager extends DefaultPluginManager {

  /**
   * @param \Traversable<string, string> $namespaces
   */
  public function __construct(\Traversable $namespaces, CacheBackendInterface $cache_backend, ModuleHandlerInterface $module_handler) {
    parent::__construct('Plugin/SegmentCondition', $namespaces, $module_handler, SegmentConditionInterface::class, SegmentCondition::class);
    $this->alterInfo('segment_condition_info');
    $this->setCacheBackend($cache_backend, 'segment_condition_plugins');
  }

}
