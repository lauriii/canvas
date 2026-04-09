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
 * Especially needed for block components, as their versions may change due to
 * upstream core changes in their config schema.
 */
trait DataProviderWithComponentTreeTrait {

  /**
   * Adds missing component versions to a component tree.
   *
   * This is helpful to simplify test expectations and fixtures that test
   * aspects besides component versions.
   *
   * This is necessary because block component versions may change due to
   * upstream changes in core, and tests that rely on hard-coded component
   * versions may fail and be compatible with fewer versions of core. In most
   * cases, there is no real need to hard-code a component version, so this
   * method exists to fill it in and allow the test to run.
   *
   * @param array $component_tree
   *   A component tree, which may contain `::ACTIVE_VERSION_IN_SUT::` as the
   *   component version.
   *
   * @return array
   *   The same component tree, but with each component instance that specifies
   *   `::ACTIVE_VERSION_IN_SUT::` as the version replaced by the active
   *   version of the referenced Component config entity.
   */
  protected static function populateActiveComponentVersionPlaceholders(array $component_tree): array {
    foreach ($component_tree as &$item) {
      if (!\array_key_exists('component_version', $item) || empty($item['component_version'])) {
        throw new \LogicException('component_version must be set for component instances in test data. Use `::ACTIVE_VERSION_IN_SUT::` to get the active version of the referenced Component config entity for testing purposes.');
      }
      if ($item['component_version'] === '::ACTIVE_VERSION_IN_SUT::') {
        $item['component_version'] = Component::load($item['component_id'])
          ?->getActiveVersion();
      }
    }
    return $component_tree;
  }

}
