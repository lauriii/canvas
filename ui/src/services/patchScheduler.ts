/**
 * Decides when to send component PATCHes while the user edits.
 *
 * - A change after a quiet moment is sent right away.
 * - While the user keeps typing, changes wait until a pause. A pause is 1.5
 *   times the recent gap between changes, so it adapts to typing speed.
 * - Changes never wait longer than MAX_WAIT ms, so the preview keeps up during
 *   long bursts.
 * - One request is in flight at a time, and only the newest change per key
 *   waits: it carries all of the component instance's props.
 */
const MIN_PAUSE = 120;
const MAX_PAUSE = 400;
const MAX_WAIT = 1000;
// Gaps longer than this are not typing.
const MAX_TYPING_GAP = 1000;

export function createPatchScheduler<T>(
  send: (value: T) => PromiseLike<unknown>,
) {
  const queued = new Map<string, T>();
  let timer: ReturnType<typeof setTimeout> | undefined;
  let sending = false;
  let isDue = false;
  let firstQueuedAt = 0;
  let lastChangeAt = -Infinity;
  let averageGap: number | undefined;
  const idleWaiters: Array<() => void> = [];

  const flush = () => {
    timer = undefined;
    if (sending) {
      isDue = true;
      return;
    }
    const next = queued.entries().next();
    if (next.done) {
      return;
    }
    const [key, value] = next.value;
    queued.delete(key);
    firstQueuedAt = Date.now();
    sending = true;
    Promise.resolve(send(value)).finally(() => {
      sending = false;
      if (isDue || (queued.size && timer === undefined)) {
        isDue = false;
        flush();
      }
      if (!sending && !queued.size) {
        // At once, so earlier waiters continue before any later caller. A
        // send resolves once its response is applied.
        idleWaiters.splice(0).forEach((resolve) => resolve());
      }
    });
  };

  return {
    /**
     * Queues a change, replacing any change for the same key not yet sent.
     *
     * @param urgent - Send without waiting for a pause, for example for a change
     *   that is not typing.
     * @param delay - Wait at least this long, still at most MAX_WAIT ms, for
     *   example for a change the preview already shows.
     */
    schedule(key: string, value: T, urgent = false, delay = 0) {
      const now = Date.now();
      const gap = now - lastChangeAt;
      lastChangeAt = now;
      if (gap < MAX_TYPING_GAP) {
        averageGap =
          averageGap === undefined ? gap : 0.7 * averageGap + 0.3 * gap;
      } else {
        averageGap = undefined;
      }
      if (!queued.size) {
        firstQueuedAt = now;
      }
      queued.set(key, value);
      clearTimeout(timer);
      if (urgent || (!delay && averageGap === undefined && !sending)) {
        timer = setTimeout(flush, 0);
        return;
      }
      const pause = Math.max(
        delay,
        Math.min(
          MAX_PAUSE,
          Math.max(MIN_PAUSE, 1.5 * (averageGap ?? MIN_PAUSE)),
        ),
      );
      timer = setTimeout(
        flush,
        Math.max(0, Math.min(pause, firstQueuedAt + MAX_WAIT - now)),
      );
    },
    /**
     * Whether a change for this key waits to be sent.
     */
    has: (key: string) => queued.has(key),
    /**
     * The change for this key waiting to be sent, if any.
     */
    get: (key: string) => queued.get(key),
    /**
     * Sends every waiting change now. Resolves once none waits or is in flight.
     */
    drain(): Promise<void> {
      if (!sending && !queued.size) {
        return Promise.resolve();
      }
      clearTimeout(timer);
      timer = undefined;
      if (sending) {
        isDue = true;
      } else {
        flush();
      }
      return new Promise((resolve) => idleWaiters.push(resolve));
    },
  };
}

const isEqual = (a: unknown, b: unknown) =>
  JSON.stringify(a) === JSON.stringify(b);

// A prop source without its value, which only repeats the resolved value.
const withoutValue = (source: unknown) => {
  if (typeof source !== 'object' || source === null) {
    return source;
  }
  const rest = { ...(source as Record<string, unknown>) };
  delete rest.value;
  return rest;
};

/**
 * Rebases a changed component model onto a newer version of the model it was
 * made on.
 *
 * Edited props keep the change; all others take their source and value from
 * `latest`. Without `editedProps`, a prop counts as edited if its resolved
 * value or its source (other than the source's copy of the value) differs
 * from `base`. That misses an edit back to the base value, so pass the edited
 * props when known.
 */
export const rebaseComponentModel = (
  base: { source: object; resolved: object },
  changed: { source: object; resolved: object },
  latest: { source: object; resolved: object },
  editedProps?: string[],
) => {
  const [baseSource, baseResolved, changedSource, changedResolved] = [
    base.source,
    base.resolved,
    changed.source,
    changed.resolved,
  ] as Record<string, unknown>[];
  const source: Record<string, unknown> = { ...latest.source };
  const resolved: Record<string, unknown> = { ...latest.resolved };
  new Set([
    ...Object.keys(baseSource),
    ...Object.keys(baseResolved),
    ...Object.keys(changedSource),
    ...Object.keys(changedResolved),
  ]).forEach((name) => {
    const isEdited = editedProps
      ? editedProps.includes(name)
      : !isEqual(baseResolved[name], changedResolved[name]) ||
        !isEqual(
          withoutValue(baseSource[name]),
          withoutValue(changedSource[name]),
        );
    if (!isEdited) {
      return;
    }
    [
      [source, changedSource],
      [resolved, changedResolved],
    ].forEach(([target, from]) => {
      if (name in from) {
        target[name] = from[name];
      } else {
        delete target[name];
      }
    });
  });
  return { source, resolved };
};
