import { useCallback, useMemo } from 'react';
import { useDndContext } from '@dnd-kit/core';

import { useAppSelector } from '@/app/hooks';
import { selectLayout } from '@/features/layout/layoutModelSlice';
import { findComponentByUuid } from '@/features/layout/layoutUtils';
import {
  componentIdFromNodeType,
  getSlotRule,
  rejectPlacement,
} from '@/features/layout/slot-utils';
import { useGetComponentsQuery } from '@/services/componentAndLayout';

import type { Active } from '@dnd-kit/core';
import type { SlotNode } from '@/features/layout/layoutModelSlice';
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
 * Resolves the restrictions that apply to a slot.
 *
 * @see \Drupal\canvas\SlotRestrictions
 */
export const useSlotRule = (slot: SlotNode | undefined): SlotRule => {
  const layout = useAppSelector(selectLayout);
  const { data: components } = useGetComponentsQuery();
  return useMemo(() => {
    if (!slot) {
      return getSlotRule(undefined, '', components);
    }
    // A slot node's id is `<parent component uuid>/<slot name>`.
    const parentUuid = slot.id.split('/')[0];
    const parentNode = findComponentByUuid(layout, parentUuid);
    const parentComponent = parentNode
      ? components?.[componentIdFromNodeType(parentNode.type)]
      : undefined;
    return getSlotRule(parentComponent, slot.name, components);
  }, [slot, layout, components]);
};

/**
 * Lists the components a slot accepts, for a filtered add menu.
 */
export const useSlotCandidates = (
  slot: SlotNode | undefined,
): CanvasComponent[] => {
  const rule = useSlotRule(slot);
  const { data: components } = useGetComponentsQuery();
  return useMemo(() => {
    const all = Object.values((components ?? {}) as ComponentsList).filter(
      (component) => !component.broken,
    );
    return rule.allowed === null
      ? all
      : all.filter((component) => rule.allowed?.includes(component.id));
  }, [rule, components]);
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
 * The author-facing name of a slot.
 */
export const useSlotTitle = (slot: SlotNode | undefined): string => {
  const layout = useAppSelector(selectLayout);
  const { data: components } = useGetComponentsQuery();
  return useMemo(() => {
    if (!slot) {
      return '';
    }
    const parentNode = findComponentByUuid(layout, slot.id.split('/')[0]);
    const parentComponent = parentNode
      ? components?.[componentIdFromNodeType(parentNode.type)]
      : undefined;
    const definition = parentComponent?.metadata?.slots?.[slot.name];
    return definition?.title ?? slot.name;
  }, [slot, layout, components]);
};

/**
 * Whether a component may be added to a slot, outside of a drag.
 *
 * Used by the insertion paths that do not go through drag and drop: inserting
 * from the library, moving into a slot, duplicating, and AI placement.
 */
export const useCanPlaceInSlot = (): ((
  slot: SlotNode | undefined,
  componentIds: string[],
) => boolean) => {
  const layout = useAppSelector(selectLayout);
  const { data: components } = useGetComponentsQuery();
  return useCallback(
    (slot, componentIds) => {
      if (!slot) {
        return true;
      }
      const parentNode = findComponentByUuid(layout, slot.id.split('/')[0]);
      const parentComponent = parentNode
        ? components?.[componentIdFromNodeType(parentNode.type)]
        : undefined;
      const rule = getSlotRule(parentComponent, slot.name, components);
      const occupancy = slot.components.length + componentIds.length - 1;
      return componentIds.every(
        (componentId) =>
          rejectPlacement(
            rule,
            componentId,
            slot.name,
            occupancy,
            components,
          ) === null,
      );
    },
    [layout, components],
  );
};
