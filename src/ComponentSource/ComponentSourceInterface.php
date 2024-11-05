<?php

declare(strict_types=1);

namespace Drupal\experience_builder\ComponentSource;

use Drupal\Component\Plugin\ConfigurableInterface;
use Drupal\Component\Plugin\DependentPluginInterface;
use Drupal\Component\Plugin\DerivativeInspectionInterface;
use Drupal\Component\Plugin\PluginInspectionInterface;
use Drupal\Core\Plugin\ContextAwarePluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\experience_builder\Entity\Component;
use Drupal\experience_builder\Plugin\Field\FieldType\ComponentTreeItem;

/**
 * Defines an interface for component source plugins.
 *
 * A Component is a config entity created by a site builder that allows
 * placement of that component in Experience Builder.
 *
 * Each Component config entity is handled by a component source. For example
 * there might be:
 * - an SDC component source — which renders a single-directory component and
 *   needs values for each required SDC prop
 * - a block plugin component source — which renders the a block and needs
 *   settings for the block plugin
 *
 * @see \Drupal\experience_builder\Attribute\ComponentSource
 * @see \Drupal\experience_builder\ComponentSource\ComponentSourceBase
 * @see \Drupal\experience_builder\ComponentSource\ComponentSourceManager
 * @phpstan-import-type ComponentClientSideTypeAny from \Drupal\experience_builder\Controller\ApiComponentsController
 * @phpstan-import-type ComponentClientSideTypeSdc from \Drupal\experience_builder\Controller\ApiComponentsController
 */
interface ComponentSourceInterface extends PluginInspectionInterface, DerivativeInspectionInterface, ConfigurableInterface, DependentPluginInterface, ContextAwarePluginInterface {

  /**
   * Gets the source plugin dependencies.
   *
   * @param array $settings
   *   The key-value pairs stored in a Component config entity's "settings"
   *   property.
   *
   * @return array
   *   An array of dependencies, keyed by provider.
   *
   * @todo Refactor/clean this up in https://www.drupal.org/project/experience_builder/issues/3484673.
   */
  public function getDependencies(array $settings): array;

  /**
   * Gets the definition of the component plugin.
   *
   * @return array
   *   The component plugin definition.
   */
  public function getComponentPluginDefinition(): array;

  /**
   * Gets the component plugin.
   *
   * @return \Drupal\Core\Plugin\Component|\Drupal\Core\Block\BlockPluginInterface
   *   The component plugin definition.
   *
   * @todo Decide what to do about the return type here
   */
  public function getComponentPlugin();

  /**
   * Gets a description of the component.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   Description.
   */
  public function getComponentDescription(): TranslatableMarkup;

  /**
   * Renders a component for the given instance.
   *
   * @param array $inputs
   *   Component inputs.
   *
   * @return array
   *   Render array.
   */
  public function renderComponent(array $inputs): array;

  /**
   * Hydrates a component with any data, including slots.
   *
   * @return array{'slots'?: array<string, string>}
   */
  public function hydrateComponent(string $uuid, ComponentTreeItem $item): array;

  /**
   * Gets the plugin definition.
   *
   * @return array
   *   Plugin definition.
   */
  public function getPluginDefinition(): array;

  /**
   * Returns information the client side needs for the XB UI.
   *
   * @param \Drupal\experience_builder\Entity\Component $component
   *   A component config entity that uses this source.
   *
   * @phpstan-return ComponentClientSideTypeAny|ComponentClientSideTypeSdc
   *   Metadata for the client side.
   *
   * @see \Drupal\experience_builder\Controller\ApiComponentsController
   * @todo Refine in https://www.drupal.org/project/experience_builder/issues/3484678
   */
  public function getClientSideInfo(Component $component, ?bool $cache_tags = TRUE): array;

}
