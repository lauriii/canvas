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
        // Let response handlers finish before waiters read their results.
        setTimeout(() => idleWaiters.splice(0).forEach((resolve) => resolve()));
      }
    });
  };

  return {
    /**
     * Queues a change, replacing any change for the same key not yet sent.
     *
     * @param urgent - Send without waiting for a pause, for example for a change
     *   that is not typing.
     */
    schedule(key: string, value: T, urgent = false) {
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
      if (urgent || (averageGap === undefined && !sending)) {
        timer = setTimeout(flush, 0);
        return;
      }
      const pause = Math.min(
        MAX_PAUSE,
        Math.max(MIN_PAUSE, 1.5 * (averageGap ?? MIN_PAUSE)),
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
