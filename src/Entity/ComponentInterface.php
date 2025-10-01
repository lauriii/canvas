<?php

declare(strict_types=1);

namespace Drupal\canvas\Entity;

use Drupal\Core\Entity\EntityWithPluginCollectionInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\canvas\ComponentSource\ComponentSourceInterface;

/**
 * Defines an interface for Component config entities.
 */
interface ComponentInterface extends VersionedConfigEntityInterface, EntityWithPluginCollectionInterface {

  public const string FALLBACK_VERSION = 'fallback';

  /**
   * Gets the human-readable category of the component, if any.
   *
   * Determine which Folder this Component will be placed in, if any.
   *
   * @return string|\Drupal\Core\StringTranslation\TranslatableMarkup|null
   *   The human-readable category of the component.
   *
   * @see \Drupal\canvas\Entity\Component::postSave()
   * @todo Remove in https://www.drupal.org/i/3549726
   */
  public function getCategory(): string|TranslatableMarkup|null;

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
   * @return array
   *   Component Settings.
   */
  public function getSettings(): array;

  public function getSlotDefinitions(): array;

  /**
   * Sets component settings.
   *
   * @param array $settings
   *   Component Settings.
   */
  public function setSettings(array $settings): self;

}
