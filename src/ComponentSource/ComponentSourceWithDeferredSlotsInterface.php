<?php

declare(strict_types=1);

namespace Drupal\canvas\ComponentSource;

use Drupal\Core\Entity\FieldableEntityInterface;

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
   * Gets a representative data context entity for the deferred slots.
   *
   * Items inside a deferred slot are validated and modeled against this
   * entity instead of the tree's host entity — e.g. a sample entity of the
   * List element's source bundle. NULL when no such entity exists (an empty
   * source); validation then degrades to structural checks, exactly like
   * component trees stored in config.
   *
   * @param array $explicit_input
   *   The slot-defining instance's stored explicit input.
   */
  public function getDeferredSlotContextEntity(array $explicit_input): ?FieldableEntityInterface;

}
