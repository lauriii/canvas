/**
 * @file
 * Prop values the Canvas editor holds ahead of this app's server data.
 *
 * The editor posts them into the preview frame: a scalar as it is typed, and
 * every prop of a component as Drupal resolved it once an auto-save returns.
 * The tree renders them over the server data at once and drops each one when
 * the server data shows the same value, so the server stays the source of
 * truth.
 */

interface LiveProp {
  value: unknown;
  /** The editor's count of typed edits when this value was current. */
  edit: number;
}

type LiveProps = ReadonlyMap<string, Readonly<Record<string, LiveProp>>>;

interface Tree {
  elements: Record<string, { props: Record<string, unknown> }>;
}

/**
 * How long a value is shown without the server data confirming it.
 *
 * A typed value is replaced by the saved one within this time, and a saved
 * one is confirmed by the next refresh. A value that outlives it belongs to
 * a save that failed, or differs from what the server renders.
 */
// ponytail: a fixed hold. An undo made between an auto-save and its refresh
// shows up to this much later; tie the hold to refresh ids if that matters.
export const LIVE_PROP_HOLD_MS = 5000;

let live: LiveProps = new Map();
const listeners = new Set<() => void>();

export const subscribeLiveProps = (listener: () => void) => {
  listeners.add(listener);
  return () => {
    listeners.delete(listener);
  };
};

export const getLiveProps = () => live;

const dropLiveProps = (
  shouldDrop: (prop: LiveProp, uuid: string, name: string) => boolean,
) => {
  let dropped = false;
  const next = new Map<string, Record<string, LiveProp>>();
  for (const [uuid, props] of live) {
    const kept = Object.entries(props).filter(
      ([name, prop]) => !shouldDrop(prop, uuid, name),
    );
    dropped ||= kept.length !== Object.keys(props).length;
    if (kept.length > 0) {
      next.set(uuid, Object.fromEntries(kept));
    }
  }
  if (dropped) {
    live = next;
    listeners.forEach((listener) => listener());
  }
};

/** Records a 'props' event of the draft session. */
export function receiveLiveProps(event: {
  componentUuid: string;
  props: Record<string, unknown>;
  edit: number;
}) {
  const current = live.get(event.componentUuid) ?? {};
  // A save that was requested before the latest keystroke must not put back
  // the text it was requested with.
  const received = Object.entries(event.props)
    .filter(([name]) => (current[name]?.edit ?? -Infinity) <= event.edit)
    .map(([name, value]) => [name, { value, edit: event.edit }] as const);
  if (received.length === 0) {
    return;
  }
  live = new Map(live).set(event.componentUuid, {
    ...current,
    ...Object.fromEntries(received),
  });
  listeners.forEach((listener) => listener());
  const held = new Set(received.map(([, prop]) => prop));
  setTimeout(() => dropLiveProps((prop) => held.has(prop)), LIVE_PROP_HOLD_MS);
}

/** Drops the props that the server data now shows. Call with new data. */
export function settleLiveProps(tree: Tree | null) {
  if (tree !== null) {
    dropLiveProps((prop, uuid, name) => {
      const element = tree.elements[uuid];
      return (
        element !== undefined &&
        // ponytail: compares serialized values, so a different key order
        // waits for the hold to end instead.
        JSON.stringify(element.props[name]) === JSON.stringify(prop.value)
      );
    });
  }
}

/** Returns the tree with the editor's props laid over the server's. */
export function withLiveProps<T extends Tree>(
  tree: T | null,
  props: LiveProps,
): T | null {
  if (tree === null || props.size === 0) {
    return tree;
  }
  const elements = { ...tree.elements };
  for (const [uuid, ahead] of props) {
    const element = elements[uuid];
    if (element) {
      elements[uuid] = {
        ...element,
        props: {
          ...element.props,
          ...Object.fromEntries(
            Object.entries(ahead).map(([name, { value }]) => [name, value]),
          ),
        },
      };
    }
  }
  return { ...tree, elements };
}
