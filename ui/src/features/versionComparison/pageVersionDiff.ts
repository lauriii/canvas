import type {
  ComponentNode,
  RootLayoutModel,
} from '@/features/layout/layoutModelSlice';

/**
 * The parts of a layout API response that the diff review compares.
 */
export type PageVersionData = RootLayoutModel & {
  entity_form_fields: Record<string, unknown>;
};

type DiffOp<T> = { type: 'same' | 'added' | 'removed'; value: T };

export type ValueChange = { name: string; before: string; after: string };

export type PageVersionChange =
  | { kind: 'field'; label: string; before: string; after: string }
  | {
      kind: 'added' | 'removed';
      node: ComponentNode;
      parent: string;
      props: { name: string; value: string }[];
    }
  | { kind: 'moved'; node: ComponentNode; from: string; to: string }
  | {
      kind: 'props';
      node: ComponentNode;
      props: ValueChange[];
    };

/**
 * Diffs two sequences with a longest common subsequence table.
 */
const diffSequences = <T>(before: T[], after: T[]): DiffOp<T>[] => {
  const n = before.length;
  const m = after.length;
  const lengths = Array.from({ length: n + 1 }, () =>
    new Array<number>(m + 1).fill(0),
  );
  for (let i = n - 1; i >= 0; i--) {
    for (let j = m - 1; j >= 0; j--) {
      lengths[i][j] =
        before[i] === after[j]
          ? lengths[i + 1][j + 1] + 1
          : Math.max(lengths[i + 1][j], lengths[i][j + 1]);
    }
  }

  const ops: DiffOp<T>[] = [];
  let i = 0;
  let j = 0;
  while (i < n && j < m) {
    if (before[i] === after[j]) {
      ops.push({ type: 'same', value: before[i] });
      i++;
      j++;
    } else if (lengths[i + 1][j] >= lengths[i][j + 1]) {
      ops.push({ type: 'removed', value: before[i++] });
    } else {
      ops.push({ type: 'added', value: after[j++] });
    }
  }
  while (i < n) ops.push({ type: 'removed', value: before[i++] });
  while (j < m) ops.push({ type: 'added', value: after[j++] });
  return ops;
};

// ponytail: labels are derived from form keys ("path[0][alias]" → "Path alias"); use the entity form's field labels if these read poorly.
const fieldLabel = (key: string): string => {
  const label = key
    .replace(/\[\d+\]/g, '')
    .replace(/\[value\]$/, '')
    .replace(/\[([^\]]+)\]/g, ' $1')
    .replace(/_/g, ' ')
    .trim();
  return label.charAt(0).toUpperCase() + label.slice(1);
};

const rawValue = (value: unknown): string => {
  if (value === undefined || value === null || value === '') return '';
  return typeof value === 'string' ? value : JSON.stringify(value);
};

/**
 * Formats a value for display, showing HTML strings (rich text) as text.
 */
const readableValue = (value: unknown): string => {
  const raw = rawValue(value);
  if (typeof value !== 'string' || !/<[a-z][^>]*>/i.test(raw)) return raw;
  const doc = new DOMParser().parseFromString(raw, 'text/html');
  return (doc.body.textContent ?? '').replace(/\s+/g, ' ').trim();
};

const diffValues = (
  before: Record<string, unknown>,
  after: Record<string, unknown>,
  keys: Iterable<string>,
): ValueChange[] =>
  [...keys].flatMap((name) => {
    if (rawValue(before[name]) === rawValue(after[name])) return [];
    const readableBefore = readableValue(before[name]);
    const readableAfter = readableValue(after[name]);
    // Markup-only changes (for example, added bold) read the same as text.
    return readableBefore === readableAfter
      ? [{ name, before: rawValue(before[name]), after: rawValue(after[name]) }]
      : [{ name, before: readableBefore, after: readableAfter }];
  });

const propValues = (props: Record<string, unknown>) =>
  Object.entries(props).flatMap(([name, value]) => {
    const readable = readableValue(value);
    return readable ? [{ name, value: readable }] : [];
  });

type Placement = { node: ComponentNode; parent: string; parentKey: string };

const collectPlacements = (
  layout: RootLayoutModel['layout'],
): Map<string, Placement> => {
  const placements = new Map<string, Placement>();
  const visit = (
    components: ComponentNode[],
    parent: string,
    parentKey: string,
  ) => {
    components.forEach((node) => {
      placements.set(node.uuid, { node, parent, parentKey });
      node.slots.forEach((slot) =>
        visit(slot.components, slot.name, `${node.uuid}/${slot.id}`),
      );
    });
  };
  layout.forEach((region) => visit(region.components, region.name, region.id));
  return placements;
};

/**
 * Lists page data, component tree, and component prop changes.
 */
export const diffPageVersions = (
  before: PageVersionData,
  after: PageVersionData,
): PageVersionChange[] => {
  const changes: PageVersionChange[] = [];

  const beforeFields = before.entity_form_fields ?? {};
  const afterFields = after.entity_form_fields ?? {};
  const fieldKeys = [
    ...new Set([...Object.keys(beforeFields), ...Object.keys(afterFields)]),
  ].filter(
    // Path alias plumbing that editors do not edit.
    (key) => !/^path\[\d+\]\[(pid|source)\]$/.test(key),
  );
  diffValues(beforeFields, afterFields, fieldKeys).forEach(
    ({ name, ...values }) =>
      changes.push({ kind: 'field', label: fieldLabel(name), ...values }),
  );

  const beforePlacements = collectPlacements(before.layout);
  const afterPlacements = collectPlacements(after.layout);

  beforePlacements.forEach(({ node, parent }, uuid) => {
    if (!afterPlacements.has(uuid)) {
      changes.push({
        kind: 'removed',
        node,
        parent,
        props: propValues(before.model[uuid]?.resolved ?? {}),
      });
    }
  });

  // A component moved when its parent changed, or when its order relative to
  // the siblings present in both versions changed.
  const siblingOrder = (
    placements: Map<string, Placement>,
    other: Map<string, Placement>,
  ) => {
    const order = new Map<string, string[]>();
    placements.forEach(({ parentKey }, uuid) => {
      if (other.get(uuid)?.parentKey !== parentKey) return;
      order.set(parentKey, [...(order.get(parentKey) ?? []), uuid]);
    });
    return order;
  };
  const beforeOrder = siblingOrder(beforePlacements, afterPlacements);
  const afterOrder = siblingOrder(afterPlacements, beforePlacements);
  const reordered = new Set<string>();
  beforeOrder.forEach((uuids, parentKey) => {
    diffSequences(uuids, afterOrder.get(parentKey) ?? [])
      .filter((op) => op.type === 'added')
      .forEach((op) => reordered.add(op.value));
  });

  afterPlacements.forEach(({ node, parent, parentKey }, uuid) => {
    const previous = beforePlacements.get(uuid);
    if (!previous) {
      changes.push({
        kind: 'added',
        node,
        parent,
        props: propValues(after.model[uuid]?.resolved ?? {}),
      });
      return;
    }
    if (previous.parentKey !== parentKey || reordered.has(uuid)) {
      changes.push({
        kind: 'moved',
        node,
        from: previous.parent,
        to: parent,
      });
    }

    const beforeProps = before.model[uuid]?.resolved ?? {};
    const afterProps = after.model[uuid]?.resolved ?? {};
    const props = diffValues(
      beforeProps,
      afterProps,
      new Set([...Object.keys(beforeProps), ...Object.keys(afterProps)]),
    );
    if (props.length) {
      changes.push({ kind: 'props', node, props });
    }
  });

  return changes;
};
