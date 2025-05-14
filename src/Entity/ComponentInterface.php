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

  /**
   * Gets component settings.
   *
   * @return array
   *   Component Settings.
   */
  public function getSettings(): array;

  /**
   * Sets component settings.
   *
   * @param array $settings
   *   Component Settings.
   */
  public function setSettings(array $settings): self;

  /**
   * Sets the component source plugin.
   *
   * Changing the source plugin involves both changing the `source` key and
   * resetting the plugin collection which may contain an instantiated instance
   * of the previous source. Use this method to safely change the source plugin,
   * using the generic ::set() method is not sufficient.
   *
   * The only appropriate times to call this are:
   * - XB itself calls it to set the special `fallback` source when a dependency
   *   of this Component was removed and instances of this Component exist
   * - a source plugin uses it to switch from the `fallback` back to itself,
   *   when the affected component has been reintroduced and is rediscovered by
   *   the source plugin
   *
   * Note: if a reintroduced component no longer has the same schema/shape for
   * its explicit input, a meaningful error message will inform the user that
   * the stored explicit input is not valid explicit input.
   *
   * @param string $source
   *   Component source plugin ID.
   *
   * @return self
   *
   * @see \Drupal\experience_builder\Entity\Component::onDependencyRemoval()
   * @see \Drupal\experience_builder\Plugin\ExperienceBuilder\ComponentSource\Fallback
   * @see \Drupal\experience_builder\Element\RenderSafeComponentContainer
   */
  public function setSource(string $source): self;

}
