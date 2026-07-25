import { hasSlotDefinitions } from '@/types/Component';

import type {
  CanvasComponent,
  ComponentsList,
  SlotDefinition,
  SlotRestrictions,
} from '@/types/Component';

/**
 * Mirrors \Drupal\canvas\SlotRestrictions.
 *
 * A component's slot may declare which components it expects and how many it
 * accepts (https://www.drupal.org/i/3514072). Drupal core does not enforce
 * these, leaving that to display building tools such as Canvas. This module is
 * the client half of that enforcement: it prevents an author from expressing an
 * invalid placement. The server rejects one if a client ever does.
 *
 * `minItems` is deliberately not enforced here: every slot starts out empty, so
 * a minimum can only be an obligation to meet before publishing, never a reason
 * to refuse a placement.
 */

/**
 * The rules that apply to one slot, resolved against the component library.
 */
export interface SlotRule {
  /** Component IDs the slot accepts, or null when it accepts anything. */
  allowed: string[] | null;
  minItems: number | null;
  maxItems: number | null;
  /** `expected` entries matching neither a component nor a tag in use. */
  unresolved: string[];
}

const UNRESTRICTED: SlotRule = {
  allowed: null,
  minItems: null,
  maxItems: null,
  unresolved: [],
};

/**
 * Normalizes an `expected` entry to a component ID, or null when it is a tag.
 *
 * Both delimiters are accepted because both spellings occur: core's issue
 * describes SDC plugin IDs (`provider:name`), while Canvas's own component IDs
 * use dots (`sdc.provider.name`, `js.name`, `block.plugin.id`).
 */
export const normalizeReference = (entry: string): string | null => {
  if (entry.includes(':')) {
    return `sdc.${entry.replaceAll(':', '.')}`;
  }
  return entry.includes('.') ? entry : null;
};

/**
 * Reads a slot's `expected` entries.
 *
 * @todo Decide whether to also read the legacy `allowedComponents` spelling in
 *   https://www.drupal.org/i/3563163. Reading it here but not server-side would
 *   let the client offer a placement that publishing then refuses.
 */
const expectedEntries = (restrictions: SlotRestrictions): string[] => {
  const expected = restrictions.expected ?? [];
  return Array.isArray(expected)
    ? expected.filter(
        (entry): entry is string => typeof entry === 'string' && entry !== '',
      )
    : [];
};

/**
 * Resolves one slot's declared restrictions against the component library.
 *
 * Resolution fails open: an `expected` list none of whose entries matches an
 * existing component or a tag in use is a mistake in the component's metadata,
 * and may not leave the slot impossible to fill.
 */
export const resolveSlotRule = (
  slotDefinition: SlotDefinition | undefined,
  components: ComponentsList | undefined,
): SlotRule => {
  if (!slotDefinition) {
    return UNRESTRICTED;
  }
  const minItems =
    typeof slotDefinition.minItems === 'number'
      ? slotDefinition.minItems
      : null;
  const maxItems =
    typeof slotDefinition.maxItems === 'number'
      ? slotDefinition.maxItems
      : null;
  const entries = expectedEntries(slotDefinition);
  if (entries.length === 0 || !components) {
    return { allowed: null, minItems, maxItems, unresolved: [] };
  }

  const allowed = new Set<string>();
  const unresolved: string[] = [];
  entries.forEach((entry) => {
    const reference = normalizeReference(entry);
    if (reference !== null) {
      if (components[reference]) {
        allowed.add(reference);
      } else {
        unresolved.push(entry);
      }
      return;
    }
    const tagged = Object.values(components).filter((component) =>
      component.tags?.includes(entry),
    );
    if (tagged.length === 0) {
      unresolved.push(entry);
      return;
    }
    tagged.forEach((component) => allowed.add(component.id));
  });

  return {
    allowed: allowed.size === 0 ? null : [...allowed],
    minItems,
    maxItems,
    unresolved,
  };
};

/**
 * Resolves the rule for a named slot of a component.
 */
export const getSlotRule = (
  parentComponent: CanvasComponent | undefined,
  slotName: string,
  components: ComponentsList | undefined,
): SlotRule => {
  if (!hasSlotDefinitions(parentComponent)) {
    return UNRESTRICTED;
  }
  return resolveSlotRule(
    parentComponent.metadata.slots[slotName] as SlotDefinition | undefined,
    components,
  );
};

/**
 * Why a slot will not accept a component, or null when it will.
 *
 * The reason is phrased as what the slot accepts rather than what it refuses:
 * an author who is told what fits can act on it.
 */
export type Rejection = { reason: string };

export const rejectPlacement = (
  rule: SlotRule,
  componentId: string | undefined,
  slotTitle: string,
  occupancy: number,
  components: ComponentsList | undefined,
  /** The slot already holds this component, so filling it does not add to it. */
  isReorderWithinSlot = false,
): Rejection | null => {
  if (
    rule.maxItems !== null &&
    !isReorderWithinSlot &&
    occupancy >= rule.maxItems
  ) {
    return {
      reason: `${slotTitle} is full (${occupancy} of ${rule.maxItems})`,
    };
  }
  if (rule.allowed === null || !componentId) {
    return null;
  }
  if (rule.allowed.includes(componentId)) {
    return null;
  }
  return {
    reason: `${slotTitle} accepts ${describeAllowed(rule, components)}`,
  };
};

/**
 * Names what a slot accepts, for an author rather than for a developer.
 */
export const describeAllowed = (
  rule: SlotRule,
  components: ComponentsList | undefined,
  limit = 3,
): string => {
  if (rule.allowed === null) {
    return 'any component';
  }
  const names = rule.allowed.map((id) => components?.[id]?.name ?? id);
  if (names.length <= limit) {
    return names.length > 1
      ? `${names.slice(0, -1).join(', ')} and ${names[names.length - 1]}`
      : names[0];
  }
  return `${names.slice(0, limit).join(', ')} and ${names.length - limit} more`;
};

/**
 * The component ID of a layout node, whose `type` is `<component id>@<version>`.
 */
export const componentIdFromNodeType = (type: string): string =>
  type.split('@')[0];
