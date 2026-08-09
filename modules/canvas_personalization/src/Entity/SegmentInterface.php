<?php

declare(strict_types=1);

namespace Drupal\canvas_personalization\Entity;

use Drupal\canvas\Entity\CanvasHttpApiEligibleConfigEntityInterface;
use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\Core\Entity\EntityWithPluginCollectionInterface;
use Drupal\Core\Plugin\DefaultLazyPluginCollection;

/**
 * Provides an interface defining a personalization segment entity type.
 *
 * We can't really shape arrays, as we don't know the plugins themselves.
 *
 * @phpstan-type SegmentConditionSettings array{id: string, negate: bool}
 */
interface SegmentInterface extends ConfigEntityInterface, EntityWithPluginCollectionInterface, CanvasHttpApiEligibleConfigEntityInterface {

  public function addSegmentRule(string $plugin_id, array $settings): self;

  public function removeSegmentRule(string $plugin_id): self;

  public function getSegmentRules(): array;

  public function getSegmentRulesPluginCollection(): DefaultLazyPluginCollection;

  /**
   * @return array<string|\Stringable>
   */
  public function summary(): array;

}
