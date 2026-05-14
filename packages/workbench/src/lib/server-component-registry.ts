/**
 * Caches the set of `canvas.component.{id}` IDs registered server-side, so
 * the workbench can detect content template references that point at
 * components the developer hasn't pushed yet.
 *
 * Lives in the workbench dev-server proxy domain (`/canvas/api/...`) so
 * authentication is handled by the same OAuth flow as the draft preview.
 *
 * Strategy:
 *   - Lazy-fetch on first call; cache in module scope for the workbench
 *     session.
 *   - `forceRefresh: true` invalidates the cache, but the actual refetch is
 *     throttled to once per `MIN_REFETCH_INTERVAL_MS` to avoid request
 *     storms when a render encounters multiple suspected unknowns.
 *   - Concurrent callers share the in-flight promise.
 */

const ENDPOINT = '/canvas/api/v0/config/component?_format=json';
const MIN_REFETCH_INTERVAL_MS = 2000;

export interface ServerComponentShape {
  propKeys: string[];
  slotKeys: string[];
}

export type ServerComponentRegistry = {
  ids: Set<string>;
  shapes: Map<string, ServerComponentShape>;
  versions: Map<string, string>;
};

let cached: ServerComponentRegistry | null = null;
let inFlight: Promise<ServerComponentRegistry> | null = null;
let lastFetchedAt = 0;

function extractShape(item: Record<string, unknown>): ServerComponentShape {
  // The `/canvas/api/v0/config/component` list endpoint returns Component
  // entities where prop names live under `propSources` and slot names under
  // `metadata.slots`.
  const propSources = item.propSources;
  const metadata = item.metadata;
  const metadataSlots =
    metadata && typeof metadata === 'object' && !Array.isArray(metadata)
      ? (metadata as Record<string, unknown>).slots
      : undefined;
  return {
    propKeys:
      propSources &&
      typeof propSources === 'object' &&
      !Array.isArray(propSources)
        ? Object.keys(propSources).sort()
        : [],
    slotKeys:
      metadataSlots &&
      typeof metadataSlots === 'object' &&
      !Array.isArray(metadataSlots)
        ? Object.keys(metadataSlots).sort()
        : [],
  };
}

async function fetchRegistry(
  signal?: AbortSignal,
): Promise<ServerComponentRegistry> {
  const response = await fetch(ENDPOINT, {
    credentials: 'include',
    headers: { Accept: 'application/json' },
    ...(signal ? { signal } : {}),
  });
  if (!response.ok) {
    throw new Error(
      `Failed to load registered component list (status ${response.status}).`,
    );
  }
  const data = (await response.json()) as unknown;
  // The endpoint returns either an array or an entity-id-keyed object —
  // tolerate both. Each item carries an `id` field naming the Component
  // config entity (e.g. "js.hero", "sdc.foo.bar").
  const items: unknown[] = Array.isArray(data)
    ? data
    : data && typeof data === 'object'
      ? Object.values(data as Record<string, unknown>)
      : [];
  const ids = new Set<string>();
  const shapes = new Map<string, ServerComponentShape>();
  const versions = new Map<string, string>();
  for (const item of items) {
    if (item && typeof item === 'object') {
      const record = item as Record<string, unknown>;
      const id = record.id;
      if (typeof id === 'string' && id.length > 0) {
        ids.add(id);
        shapes.set(id, extractShape(record));
        if (typeof record.version === 'string' && record.version.length > 0) {
          versions.set(id, record.version);
        }
      }
    }
  }
  return { ids, shapes, versions };
}

export async function getRegisteredComponentIds(
  opts: { forceRefresh?: boolean; signal?: AbortSignal } = {},
): Promise<Set<string>> {
  const registry = await getServerComponentRegistry(opts);
  return registry.ids;
}

export async function getServerComponentRegistry(
  opts: { forceRefresh?: boolean; signal?: AbortSignal } = {},
): Promise<ServerComponentRegistry> {
  if (
    opts.forceRefresh &&
    Date.now() - lastFetchedAt >= MIN_REFETCH_INTERVAL_MS
  ) {
    cached = null;
    inFlight = null;
  }
  if (cached) return cached;
  if (inFlight) return inFlight;
  inFlight = (async () => {
    try {
      const registry = await fetchRegistry(opts.signal);
      cached = registry;
      lastFetchedAt = Date.now();
      return registry;
    } finally {
      inFlight = null;
    }
  })();
  return inFlight;
}

/** Test-only: clear the cache. */
export function _resetRegisteredComponentIdsForTest(): void {
  cached = null;
  inFlight = null;
  lastFetchedAt = 0;
}
