import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { configureStore } from '@reduxjs/toolkit';

import { brandKitApi } from '@/services/brandKit';

import type { BrandKit, BrandKitColor } from '@/types/CodeComponent';

const BRAND_KIT_ID = 'global';

const makeColor = (id: string, hex: string): BrandKitColor => ({
  id,
  name: id,
  cssVariable: `--${id}`,
  weight: 0,
  value: { colorSpace: 'srgb', components: [0, 0, 0], alpha: null, hex },
});

/**
 * A write captured by the fetch stub, which the test settles by hand.
 *
 * Holding every write open is what makes ordering deterministic: a test can
 * apply two optimistic edits and then settle them in whichever order it needs,
 * which cannot be arranged reliably against a real server.
 */
interface PendingWrite {
  url: string;
  method: string;
  succeed: () => void;
  fail: (status?: number) => void;
}

/** The colors the fake server currently stores. */
let serverColors: BrandKitColor[] = [];
let pending: PendingWrite[] = [];

const jsonResponse = (body: unknown, status: number) =>
  new Response(JSON.stringify(body), {
    status,
    headers: { 'Content-Type': 'application/json' },
  });

const brandKitBody = (): BrandKit => ({
  id: BRAND_KIT_ID,
  label: 'Global brand kit',
  fonts: null,
  colors: serverColors,
});

const brandKitResponse = () => jsonResponse(brandKitBody(), 200);

/** When set, the auto-save read waits on this before responding. */
let heldAutoSave: Promise<void> | null = null;

/**
 * Applies a write to the fake server's stored colors.
 */
const applyToServer = (method: string, id: string, body: unknown) => {
  if (method === 'DELETE') {
    serverColors = serverColors.filter((color) => color.id !== id);
    return;
  }
  if (method === 'POST') {
    serverColors = [...serverColors, body as BrandKitColor];
    return;
  }
  const changes = (body ?? {}) as Partial<BrandKitColor>;
  serverColors = serverColors.map((color) =>
    color.id === id ? { ...color, ...changes } : color,
  );
};

beforeEach(() => {
  pending = [];
  heldAutoSave = null;
  vi.stubGlobal(
    'fetch',
    async (input: RequestInfo | URL, init?: RequestInit) => {
      const request = new Request(input, init);
      const { url, method } = request;

      // Mutations fetch a CSRF token first; answer it so the write itself is
      // the only request under the test's control.
      if (url.includes('session/token')) {
        return new Response('test-csrf-token', { status: 200 });
      }
      // Reads, including the reconcile refetch that invalidation triggers,
      // always return current server truth.
      if (method === 'GET') {
        if (url.includes('/config/auto-save/brand_kit/')) {
          if (heldAutoSave) {
            await heldAutoSave;
          }
          return jsonResponse({ data: brandKitBody(), autoSaves: {} }, 200);
        }
        if (url.includes('/config/brand_kit/')) {
          return brandKitResponse();
        }
        return jsonResponse({}, 404);
      }

      const id = url.endsWith('/config/color')
        ? ''
        : (url.split('/').pop() ?? '');
      // RTK Query passes a fully built Request, so `init` carries no body.
      const body = await request
        .clone()
        .json()
        .catch(() => undefined);
      return new Promise<Response>((resolve) => {
        pending.push({
          url,
          method,
          succeed: () => {
            applyToServer(method, id, body);
            resolve(jsonResponse({ ...body, id }, 200));
          },
          fail: (status = 422) =>
            resolve(jsonResponse({ errors: ['Rejected'] }, status)),
        });
      });
    },
  );
});

afterEach(() => {
  vi.unstubAllGlobals();
});

/** Lets pending microtasks and timers run. */
const flush = () => new Promise((resolve) => setTimeout(resolve, 0));

/**
 * Returns the captured write for a color id, failing loudly if absent.
 *
 * Matches the trailing path segment; a bare substring match would also hit
 * "canvas", "api", and "color" elsewhere in the URL.
 */
const writeFor = (id: string, index = 0): PendingWrite => {
  const matches = pending.filter((write) => write.url.endsWith(`/${id}`));
  const match = matches[index];
  if (!match) {
    throw new Error(`No write captured for color ${id} at index ${index}`);
  }
  return match;
};

/**
 * Builds a store subscribed to the Brand kit query, seeded from the fake
 * server. The live subscription keeps the cache entry from being evicted when
 * a mutation invalidates it.
 */
const setup = async (colors: BrandKitColor[]) => {
  serverColors = colors;
  const store = configureStore({
    reducer: {
      [brandKitApi.reducerPath]: brandKitApi.reducer,
      // The base query reads `state.configuration` for the base URL. Color
      // mutations are excluded from auto-save injection, so no other slice is
      // needed here.
      configuration: () => ({ baseUrl: 'http://localhost/' }),
    },
    middleware: (getDefaultMiddleware) =>
      getDefaultMiddleware().concat(brandKitApi.middleware),
  });

  // Await the initial load: if it were still in flight it would land on top of
  // the first optimistic patch and silently overwrite it.
  await store.dispatch(
    brandKitApi.endpoints.getBrandKit.initiate(BRAND_KIT_ID),
  );
  await flush();
  pending = [];

  const readColors = (): BrandKitColor[] =>
    brandKitApi.endpoints.getBrandKit.select(BRAND_KIT_ID)(store.getState())
      .data?.colors ?? [];

  const readHex = (id: string): string | null | undefined =>
    readColors().find((color) => color.id === id)?.value.hex;

  /**
   * Reads the color the way `useBrandKitColors` does: the auto-save draft's
   * colors win over the canonical ones when a draft is loaded.
   */
  const readEffectiveHex = (id: string): string | null | undefined => {
    const draft = brandKitApi.endpoints.getAutoSave.select(BRAND_KIT_ID)(
      store.getState(),
    ).data?.data;
    const colors = draft?.colors ?? readColors();
    return colors.find((color) => color.id === id)?.value.hex;
  };

  // Deliberately not async: returning the mutation promise from an async
  // helper would make `await editColor(...)` wait on a request the test has
  // not settled yet. Callers await `flush()` themselves.
  const editColor = (id: string, hex: string) =>
    store.dispatch(
      brandKitApi.endpoints.updateColor.initiate({
        id,
        changes: { value: makeColor(id, hex).value },
      }),
    );

  const deleteColor = (id: string) =>
    store.dispatch(brandKitApi.endpoints.deleteColor.initiate(id));

  const createColor = (id: string, hex: string) =>
    store.dispatch(
      brandKitApi.endpoints.createColor.initiate(makeColor(id, hex)),
    );

  return {
    store,
    readColors,
    readHex,
    readEffectiveHex,
    editColor,
    deleteColor,
    createColor,
  };
};

describe('brand kit color optimistic writes', () => {
  it('applies an edit before the request settles', async () => {
    const { readHex, editColor } = await setup([makeColor('a', '#ff0000')]);

    const edit = editColor('a', '#00ff00');
    await flush();

    // The cache already shows the new value while the write is still open.
    expect(readHex('a')).toBe('#00ff00');
    expect(pending).toHaveLength(1);

    writeFor('a').succeed();
    await edit;
    await flush();
    expect(readHex('a')).toBe('#00ff00');
  });

  it('rolls back to the stored value when the write is rejected', async () => {
    const { readHex, editColor } = await setup([makeColor('a', '#ff0000')]);

    const edit = editColor('a', '#00ff00');
    await flush();
    expect(readHex('a')).toBe('#00ff00');

    writeFor('a').fail(422);
    await edit;
    await flush();

    // The UI must not keep showing a value the server refused to store.
    expect(readHex('a')).toBe('#ff0000');
  });

  it('keeps the last intent when responses arrive out of order', async () => {
    const { readHex, editColor } = await setup([makeColor('a', '#ff0000')]);

    const first = editColor('a', '#00ff00');
    await flush();
    const second = editColor('a', '#0000ff');
    await flush();
    expect(readHex('a')).toBe('#0000ff');

    // The later write lands first and reconciles.
    writeFor('a', 1).succeed();
    await second;
    await flush();
    expect(readHex('a')).toBe('#0000ff');

    // Then the earlier request fails. Assert before anything else can refetch,
    // so this checks the rollback itself rather than a later server response
    // quietly repairing the damage.
    writeFor('a', 0).fail(500);
    await first;

    // The stale failure must not resurrect the value the user moved away from.
    expect(readHex('a')).toBe('#0000ff');
  });

  it('does not let a failed write disturb a different color', async () => {
    const { readHex, editColor } = await setup([
      makeColor('a', '#ff0000'),
      makeColor('b', '#ffffff'),
    ]);

    const editA = editColor('a', '#00ff00');
    await flush();
    const editB = editColor('b', '#000000');
    await flush();
    expect(readHex('a')).toBe('#00ff00');
    expect(readHex('b')).toBe('#000000');

    writeFor('a').fail(422);
    await editA;

    // Rolling back color a must leave color b's in-flight edit intact.
    expect(readHex('a')).toBe('#ff0000');
    expect(readHex('b')).toBe('#000000');

    writeFor('b').succeed();
    await editB;
    await flush();
    expect(readHex('b')).toBe('#000000');
  });

  it('removes a deleted color at once and restores it on failure', async () => {
    const { readColors, deleteColor } = await setup([
      makeColor('a', '#ff0000'),
      makeColor('b', '#ffffff'),
    ]);

    const remove = deleteColor('a');
    await flush();
    expect(readColors().map((color) => color.id)).toEqual(['b']);

    writeFor('a').fail(422);
    await remove;
    await flush();

    expect(readColors().map((color) => color.id)).toEqual(['a', 'b']);
  });

  it('keeps a color deleted when the delete succeeds', async () => {
    const { readColors, deleteColor } = await setup([
      makeColor('a', '#ff0000'),
      makeColor('b', '#ffffff'),
    ]);

    const remove = deleteColor('a');
    await flush();
    writeFor('a').succeed();
    await remove;
    await flush();

    expect(readColors().map((color) => color.id)).toEqual(['b']);
  });

  it('survives an auto-save response that lands mid-write', async () => {
    // Hold the auto-save read open so it resolves *after* the optimistic patch,
    // carrying pre-edit colors. useBrandKitColors prefers the draft, so without
    // re-application this response reverts the swatch to the old color.
    let releaseAutoSave: () => void = () => {};
    heldAutoSave = new Promise<void>((resolve) => {
      releaseAutoSave = resolve;
    });

    const { store, readEffectiveHex, editColor } = await setup([
      makeColor('a', '#ff0000'),
    ]);
    store.dispatch(brandKitApi.endpoints.getAutoSave.initiate(BRAND_KIT_ID));
    await flush();

    editColor('a', '#00ff00');
    await flush();
    expect(readEffectiveHex('a')).toBe('#00ff00');

    releaseAutoSave();
    await flush();

    // The stale draft must not overwrite the value the user just chose.
    expect(readEffectiveHex('a')).toBe('#00ff00');
  });

  it('shows a new color before the create completes', async () => {
    const { readColors, createColor } = await setup([
      makeColor('a', '#ff0000'),
    ]);

    const create = createColor('b', '#00ff00');
    await flush();

    // The client mints the id, so the row is present under its final
    // identifier while the request is still open.
    expect(readColors().map((color) => color.id)).toEqual(['a', 'b']);

    writeFor('color').succeed();
    await create;
    await flush();
    expect(readColors().map((color) => color.id)).toEqual(['a', 'b']);
  });

  it('removes an optimistically added color when the create is rejected', async () => {
    const { readColors, createColor } = await setup([
      makeColor('a', '#ff0000'),
    ]);

    const create = createColor('b', '#00ff00');
    await flush();
    expect(readColors().map((color) => color.id)).toEqual(['a', 'b']);

    writeFor('color').fail(422);
    await create;

    // The rejected color must not linger in a list that claims to be stored.
    expect(readColors().map((color) => color.id)).toEqual(['a']);
  });
});
