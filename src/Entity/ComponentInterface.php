<?php

declare(strict_types=1);

namespace Drupal\canvas\Entity;

use Drupal\Core\Entity\EntityWithPluginCollectionInterface;
use Drupal\canvas\ComponentSource\ComponentSourceInterface;

/**
 * @internal
 *
 * Defines an interface for Component config entities.
 */
interface ComponentInterface extends VersionedConfigEntityInterface, EntityWithPluginCollectionInterface {

  public const string FALLBACK_VERSION = 'fallback';

  /**
   * Gets the component source plugin.
   *
   * @return \Drupal\canvas\ComponentSource\ComponentSourceInterface
   *   The component source plugin.
   */
  public function getComponentSource(): ComponentSourceInterface;

  /**
   * Gets component settings.
   *
   * @param string|null $version
   *   The version of the component we want the settings for. If omitted,
   *   defaults to the currently loaded version.
   *
   * @return array
   *   Component Settings.
   */
  public function getSettings(?string $version = NULL): array;

  /**
   * Gets component slot definitions.
   *
   * @param string|null $version
   *   The version of the component we want the slot definitions for. If
   *   omitted, defaults to the currently loaded version.
   *
   * @return array
   *   Slot definitions.
   */
  public function getSlotDefinitions(?string $version = NULL): array;

  /**
   * Sets component settings.
   *
   * @param array $settings
   *   Component Settings.
   */
  public function setSettings(array $settings): self;

}
