/**
 * Pure helpers for the template editor's exposed-slot working set.
 *
 * The Redux slice stores exposed slots camelCased and keyed by the backing
 * field machine name (@see ExposedSlotDefinition in layoutModelSlice). The
 * server contract (config normalization and the template layout POST body) uses
 * the snake_case `{component_uuid, slot_name, label}` shape. These helpers
 * translate between the two and resolve exposed slots against layout nodes.
 */

import {
  findComponentByUuid,
  recurseNodes,
} from '@/features/layout/layoutUtils';

import type {
  ComponentNode,
  ExposedSlotDefinition,
  RegionNode,
  SlotDefaultContent,
  SlotNode,
  SlotOverrideState,
} from '@/features/layout/layoutModelSlice';

/**
 * The shipped, render-nothing Component that represents an *empty override* of
 * an exposed slot: a single instance of it, as a slot's sole content, tells the
 * server the entity deliberately renders nothing there (distinct from an absent
 * slot, which inherits the template default).
 *
 * It is excluded from the component library (config `status: false`), so its
 * active version is not discoverable via the components API and must be pinned
 * here. Keep the version in sync with the config file if the plugin changes.
 *
 * @see \Drupal\canvas\Entity\ComponentInterface::EMPTY_SLOT_MARKER_ID
 * @see config/install/canvas.component.canvas_slot_empty.marker.yml (active_version)
 */
export const CANVAS_SLOT_EMPTY_MARKER_ID = 'canvas_slot_empty.marker';
export const CANVAS_SLOT_EMPTY_MARKER_VERSION = '3b12c0b99a6caecc';
export const CANVAS_SLOT_EMPTY_MARKER_TYPE = `${CANVAS_SLOT_EMPTY_MARKER_ID}@${CANVAS_SLOT_EMPTY_MARKER_VERSION}`;

/**
 * Whether a component node is the empty-slot marker.
 */
export const isEmptySlotMarkerNode = (node: ComponentNode): boolean =>
  node.type.split('@')[0] === CANVAS_SLOT_EMPTY_MARKER_ID;

/**
 * The components of a slot excluding any empty-slot marker.
 *
 * A slot whose only content is the marker is an *empty override* and is treated
 * as empty for rendering, drop and layers purposes.
 */
export const filterNonMarkerComponents = (
  components: ComponentNode[],
): ComponentNode[] => components.filter((c) => !isEmptySlotMarkerNode(c));

/**
 * The server-side (snake_case) shape of one exposed-slot definition.
 *
 * @see \Drupal\canvas\Entity\ContentTemplate::normalizeForClientSide()
 */
export interface ExposedSlotServerDefinition {
  component_uuid: string;
  slot_name: string;
  label: string;
}

/**
 * The host component UUID of a slot node.
 *
 * Slot node ids follow the `${componentUuid}/${slotMachineName}` convention.
 *
 * @see _addNewComponentToLayout in layoutModelSlice
 */
export const getSlotHostComponentUuid = (slot: SlotNode): string =>
  slot.id.split('/')[0];

/**
 * Finds the exposed-slot alias + definition for a host component + slot name.
 */
export const findExposedSlotEntry = (
  exposedSlots: Record<string, ExposedSlotDefinition> | undefined,
  componentUuid: string,
  slotName: string,
): { alias: string; definition: ExposedSlotDefinition } | null => {
  if (!exposedSlots) {
    return null;
  }
  for (const [alias, definition] of Object.entries(exposedSlots)) {
    if (
      definition.componentUuid === componentUuid &&
      definition.slotName === slotName
    ) {
      return { alias, definition };
    }
  }
  return null;
};

/**
 * Whether a per-content slot region is a locked unit.
 *
 * A not-yet-overridden exposed slot whose template default has content is one
 * locked unit (@see decision 8 / ux-spec Phase 8): the server-rendered default
 * shows, the area is selectable only as a whole and rejects drops until
 * unlocked. Whether a default exists comes from the server's `slotDefaults`
 * side-channel. An exposed slot with an empty default, or one the entity has
 * overridden, is an ordinary editable area.
 */
export const isLockedSlotRegion = (
  regionId: string,
  exposedSlots: Record<string, ExposedSlotDefinition> | undefined,
  slotOverrides: Record<string, SlotOverrideState> | undefined,
  slotDefaults: Record<string, SlotDefaultContent | null> | undefined,
): boolean => {
  if (!exposedSlots?.[regionId]) {
    return false;
  }
  if (slotOverrides?.[regionId]?.overridden) {
    return false;
  }
  return !!slotDefaults?.[regionId];
};

/**
 * Collects the UUIDs of a component and all of its descendant components.
 */
export const collectComponentUuids = (component: ComponentNode): string[] => {
  const uuids: string[] = [component.uuid];
  recurseNodes(component, (node: ComponentNode) => {
    uuids.push(node.uuid);
  });
  return uuids;
};

/**
 * Exposed-slot entries whose host component is within the given subtree.
 *
 * Used by template-editor delete protection: deleting a component that hosts
 * (directly or via a descendant) an exposed slot must remove those definitions.
 */
export const findExposedSlotsInSubtree = (
  exposedSlots: Record<string, ExposedSlotDefinition> | undefined,
  component: ComponentNode,
): Array<{ alias: string; definition: ExposedSlotDefinition }> => {
  if (!exposedSlots) {
    return [];
  }
  const uuids = new Set(collectComponentUuids(component));
  return Object.entries(exposedSlots)
    .filter(([, definition]) => uuids.has(definition.componentUuid))
    .map(([alias, definition]) => ({ alias, definition }));
};

/**
 * The exposure-blocking conflict for a candidate slot, if any.
 *
 * Exposed slots must not nest (@see ValidExposedSlot): an exposed slot's
 * subtree is a per-entity-replaceable default, so an override of the outer
 * slot would orphan the inner slot's target. That cuts both ways, and this
 * resolves both directions so the UI can prevent the invalid exposure up
 * front instead of failing at save:
 * - 'inside': the candidate slot's host component already sits inside an
 *   exposed slot's subtree.
 * - 'contains': the candidate slot's own subtree hosts an already-exposed
 *   slot, which the exposure would swallow.
 */
export const findSlotNestingConflict = (
  exposedSlots: Record<string, ExposedSlotDefinition> | undefined,
  layout: RegionNode[],
  slot: SlotNode,
  hostComponentUuid: string,
): {
  direction: 'inside' | 'contains';
  alias: string;
  definition: ExposedSlotDefinition;
} | null => {
  if (!exposedSlots) {
    return null;
  }
  // 'inside': the host component is within another exposed slot's subtree.
  for (const [alias, definition] of Object.entries(exposedSlots)) {
    const exposedHost = findComponentByUuid(layout, definition.componentUuid);
    const exposedSlotNode = exposedHost?.slots.find(
      (node) => node.name === definition.slotName,
    );
    for (const child of exposedSlotNode?.components ?? []) {
      if (collectComponentUuids(child).includes(hostComponentUuid)) {
        return { direction: 'inside', alias, definition };
      }
    }
  }
  // 'contains': the candidate slot's subtree hosts an exposed slot.
  for (const child of slot.components) {
    const contained = findExposedSlotsInSubtree(exposedSlots, child);
    if (contained.length > 0) {
      return { direction: 'contains', ...contained[0] };
    }
  }
  return null;
};

/**
 * Transforms the slice's camelCase exposed slots into the server POST body shape.
 */
export const exposedSlotsToServer = (
  exposedSlots: Record<string, ExposedSlotDefinition> | undefined,
): Record<string, ExposedSlotServerDefinition> => {
  if (!exposedSlots) {
    return {};
  }
  return Object.fromEntries(
    Object.entries(exposedSlots).map(([alias, definition]) => [
      alias,
      {
        component_uuid: definition.componentUuid,
        slot_name: definition.slotName,
        label: definition.label,
      },
    ]),
  );
};

/**
 * Transforms server-side (snake_case) exposed slots into the slice shape.
 */
export const exposedSlotsFromServer = (
  exposedSlots: Record<string, ExposedSlotServerDefinition> | undefined,
): Record<string, ExposedSlotDefinition> => {
  if (!exposedSlots) {
    return {};
  }
  return Object.fromEntries(
    Object.entries(exposedSlots).map(([alias, definition]) => [
      alias,
      {
        label: definition.label,
        slotName: definition.slot_name,
        componentUuid: definition.component_uuid,
      },
    ]),
  );
};

/**
 * Counts the exposed slots in a server-side map.
 *
 * Used for the template-list count badge.
 */
export const countExposedSlots = (
  exposedSlots: Record<string, ExposedSlotServerDefinition> | undefined,
): number => (exposedSlots ? Object.keys(exposedSlots).length : 0);
