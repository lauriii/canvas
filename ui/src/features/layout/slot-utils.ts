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
 * How full a governed slot is: what to say, and how loudly.
 *
 * `under` is an obligation the author still has to meet, `full` is a limit they
 * have reached, and `muted` is neither — so the counter is quiet while there is
 * nothing to act on and speaks up when there is. The one place this is phrased,
 * so the canvas and the Layers panel cannot drift apart.
 *
 * Null for a slot that declares no bounds: the presence of a counter is itself
 * the signal that the slot is governed.
 */
export interface SlotOccupancy {
  label: string;
  tone: 'muted' | 'under' | 'full';
}

export const slotOccupancy = (
  rule: SlotRule,
  occupancy: number,
): SlotOccupancy | null => {
  const of =
    rule.maxItems === null
      ? `${occupancy}`
      : `${occupancy} of ${rule.maxItems}`;
  if (rule.minItems !== null && occupancy < rule.minItems) {
    return { label: `${of}, needs ${rule.minItems}`, tone: 'under' };
  }
  if (rule.maxItems === null) {
    return null;
  }
  return {
    label: of,
    tone: occupancy >= rule.maxItems ? 'full' : 'muted',
  };
};

/** The heading shared by components a slot names directly, by ID. */
export const NAMED_IN_THIS_SLOT = 'Named in this slot';

/**
 * One heading's worth of the components a slot accepts.
 */
export interface SlotCandidateGroup {
  label: string;
  componentIds: string[];
}

/**
 * The heading a tag gets in an author-facing menu.
 *
 * Tags are written for developers (`accordion-content`); this is where they
 * have to read as language.
 */
const tagHeading = (tag: string): string => {
  const words = tag.replaceAll(/[-_]+/g, ' ').trim();
  return words.charAt(0).toUpperCase() + words.slice(1);
};

/**
 * Groups the components a slot accepts, under headings an author can read.
 *
 * Each tag in `expected` becomes its own heading; components named directly by
 * ID share one, so that a component author's explicit choice reads as a
 * deliberate one. Groups appear in the order the slot declares them, and a
 * component that matches more than one entry is listed under the first.
 */
export const groupSlotCandidates = (
  slotDefinition: SlotDefinition | undefined,
  components: ComponentsList | undefined,
): SlotCandidateGroup[] => {
  if (!slotDefinition || !components) {
    return [];
  }
  const groups: SlotCandidateGroup[] = [];
  const seen = new Set<string>();
  const addTo = (label: string, componentId: string) => {
    if (seen.has(componentId)) {
      return;
    }
    seen.add(componentId);
    const group = groups.find((candidate) => candidate.label === label);
    if (group) {
      group.componentIds.push(componentId);
    } else {
      groups.push({ label, componentIds: [componentId] });
    }
  };

  expectedEntries(slotDefinition).forEach((entry) => {
    const reference = normalizeReference(entry);
    if (reference !== null) {
      if (components[reference]) {
        addTo(NAMED_IN_THIS_SLOT, reference);
      }
      return;
    }
    Object.values(components)
      .filter((component) => component.tags?.includes(entry))
      .forEach((component) => addTo(tagHeading(entry), component.id));
  });

  return groups.filter((group) => group.componentIds.length > 0);
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
