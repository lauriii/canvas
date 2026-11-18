/**
 * Pure helpers for the template editor's exposed-slot working set.
 *
 * The slice stores them camelCased, keyed by backing field name; the server
 * uses snake_case.
 */

import { extractErrorMessageFromApiResponse } from '@/features/error-handling/error-handling';
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
 * The Component that, as a slot's sole content, overrides the slot with
 * nothing. An empty slot inherits the template default instead.
 *
 * It is not in the component library, so the Layout API sends its full type as
 * `emptySlotMarkerType`.
 *
 * @see \Drupal\canvas\Plugin\Canvas\ComponentSource\Marker::EMPTY_SLOT_COMPONENT_ID
 */
export const CANVAS_SLOT_EMPTY_MARKER_ID = 'marker.empty_slot';

/**
 * The only view mode whose content templates can expose slots.
 *
 * @see \Drupal\canvas\Plugin\Validation\Constraint\ValidExposedSlotConstraint::EXPOSED_SLOTS_VIEW_MODE
 */
export const EXPOSED_SLOTS_VIEW_MODE = 'full';

/**
 * Whether a component node is the empty slot marker.
 */
export const isEmptySlotMarkerNode = (node: ComponentNode): boolean =>
  node.type.split('@')[0] === CANVAS_SLOT_EMPTY_MARKER_ID;

/**
 * The components of a slot excluding any empty slot marker.
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
 * Finds the exposed slot's field name and definition for a host component
 * and slot name.
 */
export const findExposedSlotEntry = (
  exposedSlots: Record<string, ExposedSlotDefinition> | undefined,
  componentUuid: string,
  slotName: string,
): { fieldName: string; definition: ExposedSlotDefinition } | null => {
  if (!exposedSlots) {
    return null;
  }
  for (const [fieldName, definition] of Object.entries(exposedSlots)) {
    if (
      definition.componentUuid === componentUuid &&
      definition.slotName === slotName
    ) {
      return { fieldName, definition };
    }
  }
  return null;
};

/**
 * The preview geometry key of an exposed slot.
 *
 * The preview anchors an exposed slot region with the template's slot marker,
 * so its geometry is keyed like a slot.
 */
export const exposedSlotGeometryId = (
  definition: ExposedSlotDefinition,
): string => `${definition.componentUuid}/${definition.slotName}`;

/**
 * Whether a per-content slot region inherits the template default.
 *
 * An exposed slot is inherited while it shows a non-empty template default: it
 * is selectable only as a whole and rejects drops until overridden.
 */
export const isInheritedSlotRegion = (
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
 * The state of a per-content slot region.
 *
 * - 'inherited': shows the template default.
 * - 'overridden': shows this content entity's own content.
 * - 'empty': the template default is empty and nothing was added yet, so
 *   there is nothing to override; components can be dropped in directly.
 */
export type SlotRegionState = 'inherited' | 'overridden' | 'empty';

/**
 * The state of a per-content slot region, as labeled in the Layers panel.
 *
 * NULL for a region that is not an exposed slot.
 */
export const getSlotRegionState = (
  regionId: string,
  exposedSlots: Record<string, ExposedSlotDefinition> | undefined,
  slotOverrides: Record<string, SlotOverrideState> | undefined,
  slotDefaults: Record<string, SlotDefaultContent | null> | undefined,
): SlotRegionState | null => {
  if (!exposedSlots?.[regionId]) {
    return null;
  }
  if (slotOverrides?.[regionId]?.overridden) {
    return 'overridden';
  }
  return slotDefaults?.[regionId] ? 'inherited' : 'empty';
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
): Array<{ fieldName: string; definition: ExposedSlotDefinition }> => {
  if (!exposedSlots) {
    return [];
  }
  const uuids = new Set(collectComponentUuids(component));
  return Object.entries(exposedSlots)
    .filter(([, definition]) => uuids.has(definition.componentUuid))
    .map(([fieldName, definition]) => ({ fieldName, definition }));
};

/**
 * The exposure-blocking conflict for a candidate slot, if any.
 *
 * Exposed slots must not nest (@see ValidExposedSlot):
 * - 'inside': the slot's host component is inside an exposed slot.
 * - 'contains': the slot's subtree hosts an exposed slot.
 */
export const findSlotNestingConflict = (
  exposedSlots: Record<string, ExposedSlotDefinition> | undefined,
  layout: RegionNode[],
  slot: SlotNode,
  hostComponentUuid: string,
): {
  direction: 'inside' | 'contains';
  fieldName: string;
  definition: ExposedSlotDefinition;
} | null => {
  if (!exposedSlots) {
    return null;
  }
  // 'inside': the host component is within another exposed slot's subtree.
  for (const [fieldName, definition] of Object.entries(exposedSlots)) {
    const exposedHost = findComponentByUuid(layout, definition.componentUuid);
    const exposedSlotNode = exposedHost?.slots.find(
      (node) => node.name === definition.slotName,
    );
    for (const child of exposedSlotNode?.components ?? []) {
      if (collectComponentUuids(child).includes(hostComponentUuid)) {
        return { direction: 'inside', fieldName, definition };
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
    Object.entries(exposedSlots).map(([fieldName, definition]) => [
      fieldName,
      {
        component_uuid: definition.componentUuid,
        slot_name: definition.slotName,
        label: definition.label,
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

/**
 * Describes a failed slot field creation request for the expose dialog.
 *
 * @param error
 *   The rejected RTK Query mutation error.
 * @param fallback
 *   The message when the response names no cause.
 *
 * @return
 *   The server's client error messages; for a successful response that is not
 *   JSON (e.g. output printed before it), that the field was created;
 *   otherwise the fallback.
 *
 * @see \Drupal\canvas\Controller\ApiContentTemplateSlotFieldController::create()
 */
export const getSlotFieldErrorMessage = (
  error: unknown,
  fallback: string,
): string => {
  const { status, originalStatus, data } = (error ?? {}) as {
    status?: number | string;
    originalStatus?: number;
    data?: { errors?: unknown[] };
  };
  if (
    status === 'PARSING_ERROR' &&
    originalStatus !== undefined &&
    originalStatus >= 200 &&
    originalStatus < 300
  ) {
    return 'The slot field was created, but the response could not be read. Select it in the slot field list.';
  }
  if (
    typeof status === 'number' &&
    status >= 400 &&
    status < 500 &&
    Array.isArray(data?.errors) &&
    data.errors.length > 0
  ) {
    return extractErrorMessageFromApiResponse(error);
  }
  return fallback;
};
