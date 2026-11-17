/**
 * SWR for SSR.
 *
 * Only useSWR() is here, and resetIslandFetches() for the framework
 * renderer. Importing anything else from swr fails, and the island falls
 * back to CSR.
 *
 * SSR renders no loading state, so useSWR() just calls the fetcher and
 * throws the promise until it settles. Preact's async renderer then renders
 * the component again.
 *
 * What it fetched is the island's hydration data under "swr": each key with
 * what its fetcher resolved to. In the browser, Canvas's island client
 * renderer gives it to SWR, so the first render there has the same data.
 */

import { provideHydrationData } from '@/canvas_ssr/hydration-data';

// Pending and settled fetches of the island being rendered, by key.
//
// Not component state, since the async renderer throws away the component
// and its hooks every time it suspends. They can't be dropped once read:
// every suspend renders the component again, and it reads them again.
const fetchResults = new Map();

// Counts islands, so a fetch that settles after its island ended isn't
// recorded for the next.
let island = 0;

/**
 * Forgets every fetch, for the next island.
 *
 * The framework renderer calls it before and after each island, so no
 * island sees what another fetched, for the user it was fetched for.
 */
export function resetIslandFetches() {
  fetchResults.clear();
  island++;
}

// Resolves a key, which may be a function.
function resolveKey(key) {
  if (typeof key === 'function') {
    try {
      return key();
    } catch {
      return null;
    }
  }
  return key;
}

// What SWR identifies a key by, as SWR's own serialize() and stableHash() do,
// so the server fetches once for keys the browser takes as one, such as
// objects with their properties in another order. A string key is itself.
// An array or plain object is its items, by sorted property name, without
// undefined ones. A date is its ISO string.
//
// SWR identifies any other object, such as a Map, a URLSearchParams or a
// function, and an object that holds itself, by its identity. An instance of
// a class counts as a plain object, for SWR as here. That's a new one each time the
// component renders again after suspending, and isn't the same in the
// browser, so such a key throws, and the island falls back to CSR.
function stableHash(value, holders) {
  const typeName = Object.prototype.toString.call(value);
  if (typeName === '[object Date]') {
    return value.toJSON();
  }
  if (Object(value) !== value || typeName === '[object RegExp]') {
    if (typeof value === 'symbol') {
      return value.toString();
    }
    return typeof value === 'string' ? JSON.stringify(value) : String(value);
  }
  const isArray = Array.isArray(value);
  if ((!isArray && typeName !== '[object Object]') || holders.includes(value)) {
    throw new Error('An SWR key with an object SWR identifies by its identity, such as a Map or one that holds itself, can\'t be rendered on the server.');
  }
  holders.push(value);
  let hash;
  if (isArray) {
    hash = '@';
    for (let index = 0; index < value.length; index++) {
      hash += `${stableHash(value[index], holders)},`;
    }
  } else {
    hash = '#';
    const names = Object.keys(value).sort();
    for (let index = names.length - 1; index >= 0; index--) {
      const name = names[index];
      if (value[name] !== undefined) {
        hash += `${name}:${stableHash(value[name], holders)},`;
      }
    }
  }
  holders.pop();
  return hash;
}

const serializeKey = (key) => (typeof key === 'string' ? key : stableHash(key, []));

/**
 * Lists what this island's useSWR() calls fetched, as its hydration data.
 *
 * @return {Array<[*, *]>|undefined}
 *   Each key, as useSWR() was given it, with what its fetcher resolved to,
 *   or undefined if none has.
 */
function hydrationData() {
  const entries = [];
  for (const record of fetchResults.values()) {
    if (record.status === 'fulfilled') {
      entries.push([record.key, record.value]);
    }
  }
  return entries.length === 0 ? undefined : entries;
}

// What useSWR() returns once the data is in.
function settledResult(data) {
  return { data, error: undefined, isLoading: false, isValidating: false, mutate: () => {} };
}

// Returns the data once fetched, and throws the promise until then. A failed
// fetch, such as one SSR blocks, throws its error, so the island falls back
// to CSR.
function useSWR(key, fetcher, options) {
  const resolvedKey = resolveKey(key);
  const fallbackData = options?.fallbackData;

  // As in SWR, a falsy key, such as null or "", an empty array, or no
  // fetcher, means don't fetch. SWR serializes each of those keys as "".
  if (!resolvedKey || (Array.isArray(resolvedKey) && resolvedKey.length === 0) || typeof fetcher !== 'function') {
    return settledResult(fallbackData);
  }

  const recordKey = serializeKey(resolvedKey);
  const record = fetchResults.get(recordKey);

  if (record) {
    if (record.status === 'fulfilled') {
      return settledResult(record.value);
    }
    if (record.status === 'rejected') {
      // The browser may be able to fetch what SSR can't.
      throw record.error;
    }
    throw record.promise;
  }

  const pending = { status: 'pending', promise: null };
  const fetchedFor = island;
  const keep = (settled) => {
    if (island === fetchedFor) {
      fetchResults.set(recordKey, settled);
      provideHydrationData('swr', hydrationData());
    }
  };
  pending.promise = Promise.resolve()
    .then(() => fetcher(resolvedKey))
    .then(
      (value) => keep({ status: 'fulfilled', key: resolvedKey, value }),
      (error) => keep({ status: 'rejected', error }),
    );
  fetchResults.set(recordKey, pending);
  throw pending.promise;
}

export default useSWR;
export { useSWR };
