<?php

declare(strict_types=1);

namespace Drupal\canvas\ComponentSource;

use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Field\FieldItemInterface;

/**
 * @internal
 *
 * Defines an interface for sources that render some slot subtrees themselves.
 *
 * Normally the component tree hydrates every child instance against the
 * tree's host entity and hands the rendered slots to the parent's source.
 * A source implementing this interface takes over rendering for the declared
 * slots: their child instances are excluded from regular hydration, and the
 * source instead receives the raw item values via the hydrated inputs key
 * ComponentTreeItemList::DEFERRED_SLOT_SUBTREES_KEY, so it can bind a
 * different context — e.g. the List element renders its item template once
 * per query result, with prop expressions resolving against that result
 * entity instead of the host entity.
 *
 * @see \Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItemList::getHydratedValue()
 * @see \Drupal\canvas\Plugin\Canvas\ComponentSource\ListComponent
 */
interface ComponentSourceWithDeferredSlotsInterface extends ComponentSourceWithSlotsInterface {

  /**
   * Gets the names of the slots whose subtrees this source renders itself.
   *
   * @return list<string>
   */
  public function getDeferredSlotNames(): array;

  /**
   * Gets a representative data context for the deferred slots.
   *
   * Items inside a deferred slot are validated and modeled against this
   * context instead of the tree's host entity. It is:
   * - a `FieldableEntityInterface` when each iteration binds a whole entity —
   *   e.g. a sample entity of a query-sourced List element's source bundle.
   *   That entity replaces the tree's host entity for the whole subtree.
   * - a `FieldItemInterface` when each iteration binds one value of a field of
   *   the tree's host entity. The host entity is *not* replaced then: entity
   *   field prop sources inside the subtree keep resolving against it, and item
   *   prop sources resolve against the item.
   * - NULL when no such context exists (an empty source); validation then
   *   degrades to structural checks, exactly like component trees stored in
   *   config.
   *
   * @param array $explicit_input
   *   The slot-defining instance's stored explicit input.
   * @param \Drupal\Core\Entity\FieldableEntityInterface|null $host_entity
   *   The tree's host entity, when there is one. A source iterating a field of
   *   the host entity needs it to produce an item.
   *
   * @see docs/adr/0021-item-template-data-context-is-a-field-item.md
   */
  public function getDeferredSlotContext(array $explicit_input, ?FieldableEntityInterface $host_entity = NULL): FieldableEntityInterface|FieldItemInterface|null;

}
