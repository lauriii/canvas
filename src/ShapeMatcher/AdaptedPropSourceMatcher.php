<?php

declare(strict_types=1);

namespace Drupal\canvas\ShapeMatcher;

use Drupal\canvas\Plugin\AdapterManager;
use Drupal\canvas\PropShape\PropShape;

/**
 * Matches adapted prop sources against a JSON schema.
 *
 * Delegates to AdapterManager to find all adapters whose output schema matches
 * the given schema.
 *
 * @see \Drupal\canvas\Plugin\Adapter\AdapterInterface
 * @see \Drupal\canvas\PropSource\AdaptedPropSource
 *
 * @internal
 *
 * @todo Update in https://www.drupal.org/project/canvas/issues/3464003
 */
final readonly class AdaptedPropSourceMatcher {

  public function __construct(
    private AdapterManager $adapterManager,
  ) {}

  /**
   * Finds adapters whose output schema matches the given schema.
   *
   * @param bool $is_required
   *   Whether the prop shape to match is required or not.
   * @param \Drupal\canvas\PropShape\PropShape $prop_shape
   *   The prop shape to match.
   *
   * @return list<\Drupal\canvas\Plugin\Adapter\AdapterInterface>
   *   All adapters whose output schema matches the given schema.
   *
   * @throws \Drupal\Component\Plugin\Exception\PluginException
   */
  public function match(bool $is_required, PropShape $prop_shape): array {
    return $this->adapterManager->getDefinitionsByOutputSchema($prop_shape->schema);
  }

}
