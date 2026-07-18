/**
 * Orders all layout-endpoint writes (full-render POSTs, persist POSTs,
 * component PATCHes) on a single client-side chain.
 *
 * Auto-save writes must reach the server in dispatch order: a PATCH sent
 * while a persist for an earlier structural change is still pending would
 * read a stale draft structure server-side. Unlike the previous single-flight
 * queue (whose superseded callers received promises that never resolved),
 * every caller's promise settles with its own result.
 *
 * Read-only requests (the partial render endpoint) do not use this chain;
 * they run concurrently and are aborted on supersession.
 */
let chain: Promise<unknown> = Promise.resolve();

export function enqueueLayoutWrite<T>(write: () => Promise<T>): Promise<T> {
  const result = chain.then(write, write);
  // The chain itself must survive rejections so later writes still run.
  chain = result.catch(() => {});
  return result;
}
