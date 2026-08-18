<?php

declare(strict_types=1);

namespace Drupal\canvas\ComponentSource;

/**
 * Defines an interface for sources whose slots render outside tree hydration.
 *
 * A regular slot's children are hydrated against the component tree's host
 * and rendered exactly once, before the parent receives them as finished
 * render arrays via ComponentSourceWithSlotsInterface::setSlots(). A source
 * implementing this interface opts its slots out of that: the tree excludes
 * every descendant of such a component instance from hydration and rendering,
 * and instead hands the source the raw stored item values. The source decides
 * how, how often, and against which host the subtree renders — for example
 * once per result row of a query, through a dangling component tree parented
 * to that row's entity.
 *
 * The raw items reach the source in renderComponent()'s $inputs under
 * self::DEFERRED_ITEMS_KEY, in stored order, with their original parent and
 * slot references intact. Direct children of the deferred component instance
 * reference its UUID as their parent. When deferred components nest, the
 * outermost instance receives the entire subtree; rendering it through a
 * dangling tree re-enters this mechanism for the inner instance.
 *
 * @internal
 *
 * @see \Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItemList::getHydratedValue()
 */
interface ComponentSourceWithDeferredSlotsInterface extends ComponentSourceWithSlotsInterface {

  /**
   * The key in renderComponent()'s $inputs holding the raw deferred items.
   *
   * The value is a list of stored component tree item values (uuid,
   * component_id, component_version, inputs, parent_uuid, slot).
   */
  public const string DEFERRED_ITEMS_KEY = 'deferred_slot_items';

}
