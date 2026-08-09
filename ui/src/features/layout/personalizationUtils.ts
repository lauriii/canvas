import { NodeType } from './layoutModelSlice';

import type {
  ComponentModels,
  ComponentNode,
  RegionNode,
  SlotNode,
} from './layoutModelSlice';

// Component entity IDs of the personalization switch/case pair. Layout node
// `type` strings carry a version suffix (`p13n.switch@<version>`), so nodes
// are identified by the part before the `@`.
export const SWITCH_COMPONENT_ID = 'p13n.switch';
export const CASE_COMPONENT_ID = 'p13n.case';

// The default variant always exists, always matches, and is conventionally
// the last entry of a switch's `variants` list.
export const DEFAULT_VARIANT_ID = 'default';

// Variant IDs are machine names, local to their switch.
export const VARIANT_ID_PATTERN = /^[a-z0-9_]+$/;

// The single slot both the switch and the case component define.
export const P13N_SLOT_NAME = 'content';

export function getComponentTypeId(node: ComponentNode): string {
  return node.type.split('@')[0];
}

export function isSwitchNode(node: ComponentNode | SlotNode): boolean {
  return (
    node.nodeType === NodeType.Component &&
    getComponentTypeId(node) === SWITCH_COMPONENT_ID
  );
}

export function isCaseNode(node: ComponentNode | SlotNode): boolean {
  return (
    node.nodeType === NodeType.Component &&
    getComponentTypeId(node) === CASE_COMPONENT_ID
  );
}

/**
 * Finds the root-level personalization switch of a region, if any.
 */
export function findRootSwitch(
  region: RegionNode | undefined,
): ComponentNode | null {
  if (!region) {
    return null;
  }
  return region.components.find(isSwitchNode) ?? null;
}

/**
 * Returns the `content` slot of a switch or case component.
 */
export function getContentSlot(node: ComponentNode): SlotNode | null {
  return node.slots.find((slot) => slot.name === P13N_SLOT_NAME) ?? null;
}

/**
 * Returns the case nodes of a switch, in priority order.
 */
export function getSwitchCases(switchNode: ComponentNode): ComponentNode[] {
  return getContentSlot(switchNode)?.components ?? [];
}

export function getCaseVariantId(
  model: ComponentModels,
  caseNode: ComponentNode,
): string | undefined {
  const variantId = model[caseNode.uuid]?.resolved?.variant_id;
  return typeof variantId === 'string' ? variantId : undefined;
}

/**
 * Returns the segment IDs a case targets, in stored order.
 */
export function getCaseSegmentIds(
  model: ComponentModels,
  caseNode: ComponentNode,
): string[] {
  const segments = model[caseNode.uuid]?.resolved?.segments;
  return Array.isArray(segments) ? (segments as string[]) : [];
}

/**
 * Formats a machine variant ID for display: snake_case becomes sentence
 * case, and the default variant is labeled "Default".
 */
export function humanizeVariantId(variantId: string): string {
  if (variantId === DEFAULT_VARIANT_ID) {
    return 'Default';
  }
  const words = variantId
    .split(/[_\s]+/)
    .filter(Boolean)
    .join(' ');
  if (!words) {
    return variantId;
  }
  return words.charAt(0).toUpperCase() + words.slice(1);
}

export function isCaseDisabled(
  model: ComponentModels,
  caseNode: ComponentNode,
): boolean {
  return model[caseNode.uuid]?.resolved?.disabled === true;
}

/**
 * Returns the ordered variant IDs of a switch component.
 */
export function getSwitchVariants(
  model: ComponentModels,
  switchUuid: string,
): string[] {
  const variants = model[switchUuid]?.resolved?.variants;
  return Array.isArray(variants) ? (variants as string[]) : [];
}

/**
 * Returns the variant previewed for a switch, defaulting to the default
 * variant when no explicit choice was made.
 */
export function getPreviewedVariant(
  previewedVariants: Record<string, string>,
  switchUuid: string,
): string {
  return previewedVariants[switchUuid] ?? DEFAULT_VARIANT_ID;
}

/**
 * Collects every switch component in the layout, at any depth.
 */
export function findSwitchNodes(layout: RegionNode[]): ComponentNode[] {
  const switches: ComponentNode[] = [];
  const collect = (components: ComponentNode[]) => {
    for (const component of components) {
      if (isSwitchNode(component)) {
        switches.push(component);
      }
      for (const slot of component.slots) {
        collect(slot.components);
      }
    }
  };
  for (const region of layout) {
    collect(region.components);
  }
  return switches;
}

/**
 * Filters a slot's children for editor display. The preview renders every
 * case of a switch, but the editor shows one variant at a time: inside a
 * switch's slot only the case of the previewed variant is kept. Children of
 * any other parent pass through unchanged.
 */
export function filterSlotComponentsForPreview(
  slot: SlotNode,
  parentNode: ComponentNode | undefined,
  model: ComponentModels,
  previewedVariants: Record<string, string>,
): ComponentNode[] {
  if (!parentNode || !isSwitchNode(parentNode)) {
    return slot.components;
  }
  const activeVariantId = getPreviewedVariant(
    previewedVariants,
    parentNode.uuid,
  );
  return slot.components.filter(
    (component) =>
      !isCaseNode(component) ||
      getCaseVariantId(model, component) === activeVariantId,
  );
}
