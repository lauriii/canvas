<?php

declare(strict_types=1);

namespace Drupal\canvas\ComponentSource;

use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItem;

/**
 * @internal
 *
 * Defines an interface for component source plugins to update its instances.
 *
 * Handles all component instance updating concerns, to maximize the number of
 * component instances using the active (aka latest) version of the Component
 * config entity.
 *
 * Typical example: a developer is developing an SDC, and while developing is
 * testing it in Canvas. They're adding a new required prop. Existing instances
 * should automatically update to the latest version available, by adding the
 * default value (the first one in examples) for the new prop.
 * @todo Actually implement that in https://www.drupal.org/i/3568602
 *
 * Affects only "edit time", should not be used at render time, because it:
 * 1) is too heavy in terms of performance;
 * 2) does more than the strictly required to be able to render the live version
 *    of the component.
 *
 * @see \Drupal\canvas\Attribute\ComponentSource::__construct(updater)
 * @see \Drupal\canvas\ComponentSource\ComponentSourceInterface
 */
interface ComponentInstanceUpdaterInterface {

  /**
   * Whether the version of component instance needs updating.
   *
   * @return bool
   */
  public function isUpdateNeeded(ComponentTreeItem $component_instance): bool;

  /**
   * Whether the version of component instance can be updated automatically.
   *
   * @return bool
   */
  public function canUpdate(ComponentTreeItem $component_instance): bool;

  /**
   * Updates the component instance to the latest available version.
   *
   * @return ComponentInstanceUpdateAttemptResult
   *   Enum representing if no update was needed, if it was not allowed, or if
   *   it succeeded.
   */
  public function update(ComponentTreeItem $component_instance): ComponentInstanceUpdateAttemptResult;

}
