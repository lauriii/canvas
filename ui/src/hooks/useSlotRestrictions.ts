import { useCallback, useMemo } from 'react';
import { useDndContext } from '@dnd-kit/core';
import { createSelector } from '@reduxjs/toolkit';

import { useAppSelector } from '@/app/hooks';
import { selectLayout } from '@/features/layout/layoutModelSlice';
import {
  findComponentByUuid,
  recurseNodes,
  slotAtPath,
} from '@/features/layout/layoutUtils';
import {
  componentIdFromNodeType,
  getSlotRule,
  rejectPlacement,
} from '@/features/layout/slot-utils';
import { useGetComponentsQuery } from '@/services/componentAndLayout';

import type { Active } from '@dnd-kit/core';
import type {
  ComponentNode,
  RegionNode,
  SlotNode,
} from '@/features/layout/layoutModelSlice';
import type { Rejection, SlotRule } from '@/features/layout/slot-utils';
import type { CanvasComponent, ComponentsList } from '@/types/Component';
import type { Pattern } from '@/types/Pattern';

/**
 * The component IDs a drag would place, or undefined when it places nothing.
 *
 * A pattern places its root components; everything else places one component.
 */
export const draggedComponentIds = (
  active: Active | null,
): string[] | undefined => {
  const data = active?.data?.current;
  if (!data) {
    return undefined;
  }
  if (data.type === 'pattern') {
    const pattern = data.item as Pattern | undefined;
    return pattern?.layoutModel?.layout?.map((node) =>
      componentIdFromNodeType(node.type),
    );
  }
  if (data.item?.id) {
    return [(data.item as CanvasComponent).id];
  }
  if (data.component?.type) {
    return [componentIdFromNodeType(data.component.type)];
  }
  return undefined;
};

/**
 * Every component in the layout, keyed by UUID.
 *
 * Memoised across consumers: there is a drop zone per slot and per component,
 * and each of them needs its parent, so scanning the layout once per zone is
 * O(zones x tree) work repeated on every keystroke.
 */
const selectComponentsByUuid = createSelector([selectLayout], (layout) => {
  const byUuid = new Map<string, ComponentNode>();
  layout.forEach((region) =>
    recurseNodes(region, (node) => {
      byUuid.set(node.uuid, node);
    }),
  );
  return byUuid;
});

/**
 * The component that owns a slot; a slot node's id is `<uuid>/<slot name>`.
 */
const parentComponentOf = (
  slot: SlotNode,
  byUuid: Map<string, ComponentNode>,
  components: ComponentsList | undefined,
): CanvasComponent | undefined => {
  const parentNode = byUuid.get(slot.id.split('/')[0]);
  return parentNode
    ? components?.[componentIdFromNodeType(parentNode.type)]
    : undefined;
};

/**
 * The author-facing name of a slot.
 */
const slotTitleOf = (
  slot: SlotNode,
  parentComponent: CanvasComponent | undefined,
): string => parentComponent?.metadata?.slots?.[slot.name]?.title ?? slot.name;

/**
 * Why a slot will not take these components, or null when it will.
 */
const rejectionFor = (
  slot: SlotNode,
  parentComponent: CanvasComponent | undefined,
  componentIds: string[],
  components: ComponentsList | undefined,
): Rejection | null => {
  const rule = getSlotRule(parentComponent, slot.name, components);
  const slotTitle = slotTitleOf(slot, parentComponent);
  const occupancy = slot.components.length + componentIds.length - 1;
  for (const componentId of componentIds) {
    const rejection = rejectPlacement(
      rule,
      componentId,
      slotTitle,
      occupancy,
      components,
    );
    if (rejection) {
      return rejection;
    }
  }
  return null;
};

/**
 * Why an insertion path will not take these components, or null when it will.
 *
 * A plain function rather than a hook, because AI placement runs outside
 * React's render cycle and places components one after another: it has to
 * re-read the layout as it stands after the previous placement, not a snapshot
 * captured when the response arrived.
 *
 * @see \Drupal\canvas\SlotRestrictions
 */
export const rejectPlacementAtPath = (
  layout: RegionNode[],
  path: number[],
  componentIds: string[],
  components: ComponentsList | undefined,
): Rejection | null => {
  const slot = slotAtPath(layout, path);
  if (!slot) {
    // The insertion targets a region, which declares no restrictions.
    return null;
  }
  const parentNode = findComponentByUuid(layout, slot.id.split('/')[0]);
  return rejectionFor(
    slot,
    parentNode
      ? components?.[componentIdFromNodeType(parentNode.type)]
      : undefined,
    componentIds,
    components,
  );
};

/**
 * Resolves the restrictions that apply to a slot.
 *
 * @see \Drupal\canvas\SlotRestrictions
 */
export const useSlotRule = (slot: SlotNode | undefined): SlotRule => {
  const byUuid = useAppSelector(selectComponentsByUuid);
  const { data: components } = useGetComponentsQuery();
  return useMemo(() => {
    if (!slot) {
      return getSlotRule(undefined, '', components);
    }
    return getSlotRule(
      parentComponentOf(slot, byUuid, components),
      slot.name,
      components,
    );
  }, [slot, byUuid, components]);
};

/**
 * The author-facing name of a slot.
 */
export const useSlotTitle = (slot: SlotNode | undefined): string => {
  const byUuid = useAppSelector(selectComponentsByUuid);
  const { data: components } = useGetComponentsQuery();
  return useMemo(
    () =>
      slot
        ? slotTitleOf(slot, parentComponentOf(slot, byUuid, components))
        : '',
    [slot, byUuid, components],
  );
};

/**
 * Why the drag in progress cannot be dropped into a slot, or null when it can.
 *
 * Returns null when nothing is being dragged, so a drop zone that is not
 * currently a target is never reported as refusing anything.
 */
export const useDropRejection = (
  slot: SlotNode | undefined,
): Rejection | null => {
  const { active } = useDndContext();
  const rule = useSlotRule(slot);
  const { data: components } = useGetComponentsQuery();
  const slotTitle = useSlotTitle(slot);

  return useMemo(() => {
    if (!slot || !active) {
      return null;
    }
    const componentIds = draggedComponentIds(active);
    if (!componentIds || componentIds.length === 0) {
      return null;
    }
    // Moving a component within the slot it already occupies does not add to
    // it, so a full slot can still be reordered.
    const draggedUuid = active.data?.current?.component?.uuid;
    const isReorderWithinSlot = Boolean(
      draggedUuid &&
      slot.components.some((child) => child.uuid === draggedUuid),
    );
    // A pattern is refused as a whole if any of its roots does not fit, and
    // counts against the slot's capacity for every root it would add.
    const occupancy =
      slot.components.length +
      (isReorderWithinSlot ? 0 : componentIds.length - 1);
    for (const componentId of componentIds) {
      const rejection = rejectPlacement(
        rule,
        componentId,
        slotTitle,
        occupancy,
        components,
        isReorderWithinSlot,
      );
      if (rejection) {
        return rejection;
      }
    }
    return null;
  }, [slot, active, rule, components, slotTitle]);
};

/**
 * Why a slot will not take these components, outside of a drag.
 *
 * The single gate for every insertion path that does not go through drag and
 * drop: inserting from the library, moving into a slot, duplicating, pasting,
 * and AI placement.
 */
export const useSlotRejection = (): ((
  slot: SlotNode | undefined,
  componentIds: string[],
) => Rejection | null) => {
  const byUuid = useAppSelector(selectComponentsByUuid);
  const { data: components } = useGetComponentsQuery();
  return useCallback(
    (slot, componentIds) =>
      slot
        ? rejectionFor(
            slot,
            parentComponentOf(slot, byUuid, components),
            componentIds,
            components,
          )
        : null,
    [byUuid, components],
  );
};

/**
 * Whether a component may be added to a slot, outside of a drag.
 */
export const useCanPlaceInSlot = (): ((
  slot: SlotNode | undefined,
  componentIds: string[],
) => boolean) => {
  const reject = useSlotRejection();
  return useCallback(
    (slot, componentIds) => reject(slot, componentIds) === null,
    [reject],
  );
};
