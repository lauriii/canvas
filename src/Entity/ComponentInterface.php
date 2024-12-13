<?php

declare(strict_types=1);

namespace Drupal\experience_builder\Entity;

use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\Core\Entity\EntityWithPluginCollectionInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\experience_builder\ComponentSource\ComponentSourceInterface;

/**
 * Defines an interface for Component config entities.
 */
interface ComponentInterface extends ConfigEntityInterface, EntityWithPluginCollectionInterface {

  /**
   * Gets the human-readable category of the component.
   *
   * @return string|\Drupal\Core\StringTranslation\TranslatableMarkup
   *   The human-readable category of the component.
   */
  public function getCategory(): string|TranslatableMarkup;

  /**
   * Gets the component source plugin.
   *
   * @return \Drupal\experience_builder\ComponentSource\ComponentSourceInterface
   *   The component source plugin.
   */
  public function getComponentSource(): ComponentSourceInterface;

}
