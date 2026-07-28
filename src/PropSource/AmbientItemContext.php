<?php

declare(strict_types=1);

namespace Drupal\canvas\PropSource;

use Drupal\Core\Field\FieldItemInterface;

/**
 * Carries the field item currently being iterated by an item template.
 *
 * A field-sourced List element renders its item template once per field item,
 * and inside that subtree two data contexts coexist: the tree's host entity
 * (which entity field prop sources resolve against, unchanged) and the current
 * field item (which item prop sources resolve against). Only the first travels
 * through the existing `evaluate(?FieldableEntityInterface $host_entity, …)`
 * signature, so the second is ambient.
 *
 * ponytail: a scoped static, rather than threading a context object through
 * ComponentSourceInterface::getExplicitInput(), PropSourceBase::evaluate() and
 * every implementation of both. It is safe because a component tree's hydration
 * and rendering both run synchronously inside ::within().
 *
 * @see \Drupal\canvas\PropSource\ItemPropSource
 * @see \Drupal\canvas\Plugin\Canvas\ComponentSource\ListComponent::renderItem()
 * @see docs/adr/0021-item-template-data-context-is-a-field-item.md
 *
 * @internal
 */
final class AmbientItemContext {

  private static ?FieldItemInterface $current = NULL;

  /**
   * Gets the field item being iterated, if any.
   */
  public static function get(): ?FieldItemInterface {
    return self::$current;
  }

  /**
   * Runs a callback with the given field item as the ambient item.
   *
   * Nesting restores the enclosing item, so a List inside another List's item
   * template does not leak its items outward.
   */
  public static function within(?FieldItemInterface $item, callable $callback): mixed {
    $previous = self::$current;
    self::$current = $item;
    try {
      return $callback();
    }
    finally {
      self::$current = $previous;
    }
  }

}
