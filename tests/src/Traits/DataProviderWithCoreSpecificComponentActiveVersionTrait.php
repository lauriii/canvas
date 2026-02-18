<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Traits;

use Drupal\canvas\Entity\Component;

/**
 * Trait to set component versions in data provider-defined component trees.
 *
 * Data provider-defined component trees cannot interact with a booted Drupal
 * and hence cannot call Component::load()->getActiveVersion(). This trait
 * allows specifying `::ACTIVE_VERSION_IN_SUT::` as a placeholder in the data
 * provider, which will be replaced with the active version of the component in
 * the system under test.
 *
 * Typically needed for block components, as their versions may change due to
 * upstream core changes in their config schema.
 */
trait DataProviderWithCoreSpecificComponentActiveVersionTrait {

  /**
   * Adds missing block component versions to a component tree.
   *
   * This is necessary because block component versions may change due to
   * upstream changes in core, and tests that rely on hard-coded component
   * versions may fail and be compatible with fewer versions of core. In most
   * cases, there is no real need to hard-code a component version, so this
   * method exists to fill it in and allow the test to run.
   *
   * @param array $component_tree
   *   A component tree structure. Any components that are blocks (i.e., the
   *   component_id starts with `block.`) that has no component_version set will
   *   get its version populated with the active version of the component.
   */
  protected static function addMissingBlockComponentVersions(array &$component_tree): void {
    foreach ($component_tree as &$item) {
      if (str_starts_with($item['component_id'], 'block.')) {
        if (!\array_key_exists('component_version', $item) || empty($item['component_version'])) {
          throw new \LogicException('component_version must be set for block component instances in test data. Use `::ACTIVE_VERSION_IN_SUT::` to get the active version of the referenced Component config entity for testing purposes.');
        }
        if ($item['component_version'] === '::ACTIVE_VERSION_IN_SUT::') {
          $item['component_version'] = Component::load($item['component_id'])
            ?->getActiveVersion();
        }
      }
    }
  }

}
