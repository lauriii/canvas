/**
 * Serializes framework data refreshes and coalesces requests received while
 * one is running. The queued pass is important for Canvas: a newer auto-save
 * can arrive while a framework is still rendering the previous one.
 */
export function createAsyncRefreshQueue(
  refresh: () => Promise<unknown>,
  onError: (error: unknown) => void,
): {
  request: () => Promise<void>;
} {
  let pending = false;
  let active: Promise<void> | null = null;

  const drain = async (): Promise<void> => {
    try {
      do {
        pending = false;
        try {
          await refresh();
        } catch (error) {
          // One failed framework refresh must neither reject an intentionally
          // discarded request promise nor drop a newer queued auto-save.
          onError(error);
        }
      } while (pending);
    } finally {
      // Clear the active drain before its promise settles. A request queued by
      // another reaction to the completed refresh can then start a new drain
      // instead of attaching to a promise whose loop has already finished.
      active = null;
    }
  };

  return {
    request: () => {
      pending = true;
      active ??= drain();
      return active;
    },
  };
}
