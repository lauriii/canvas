import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { configureStore } from '@reduxjs/toolkit';

import { BRAND_KIT_ID } from '@/features/brandKit/constants';
import { brandKitApi } from '@/services/brandKit';

import type { BrandKit, BrandKitColor } from '@/types/CodeComponent';

const makeColor = (id: string, hex: string): BrandKitColor => ({
  id,
  name: id,
  cssVariable: `--${id}`,
  weight: 0,
  value: { colorSpace: 'srgb', components: [0, 0, 0], alpha: null, hex },
});

/** The colors the fake server currently stores. */
let serverColors: BrandKitColor[] = [];

/** Settles the write the test is holding open. */
let releaseWrite: (status: number) => void = () => {};

/** When set, the auto-save read waits on this before responding. */
let heldAutoSave: Promise<void> | null = null;

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

beforeEach(() => {
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
        return jsonResponse(brandKitBody(), 200);
      }

      // RTK Query passes a fully built Request, so `init` carries no body.
      const body = await request
        .clone()
        .json()
        .catch(() => undefined);
      const id = url.split('/').pop() ?? '';
      // The write is held open so a test can assert the optimistic state while
      // it is still in flight, then decide how it settles.
      // The server assigns an id on create; the client never sends one.
      const created = { ...(body ?? {}), id: 'assigned-by-server' };
      return new Promise<Response>((resolve) => {
        releaseWrite = (status) => {
          if (status >= 400) {
            resolve(jsonResponse({ errors: ['Rejected'] }, status));
            return;
          }
          if (method === 'POST') {
            serverColors = [...serverColors, created as BrandKitColor];
            resolve(jsonResponse(created, status));
            return;
          }
          serverColors = serverColors.map((color) =>
            color.id === id ? { ...color, ...(body ?? {}) } : color,
          );
          resolve(jsonResponse({ ...body, id }, status));
        };
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
 * Initializes the test store with the given colors and an active Brand kit subscription.
 */
const setup = async (colors: BrandKitColor[]) => {
  serverColors = colors;
  const store = configureStore({
    reducer: {
      [brandKitApi.reducerPath]: brandKitApi.reducer,
      configuration: () => ({ baseUrl: 'http://localhost/' }),
    },
    middleware: (getDefaultMiddleware) =>
      getDefaultMiddleware().concat(brandKitApi.middleware),
  });

  // Ensure the initial data load finishes before returning the store.
  await store.dispatch(
    brandKitApi.endpoints.getBrandKit.initiate(BRAND_KIT_ID),
  );
  await flush();

  const readHex = (id: string): string | null | undefined =>
    brandKitApi.endpoints.getBrandKit
      .select(BRAND_KIT_ID)(store.getState())
      .data?.colors?.find((color) => color.id === id)?.value.hex;

  /** Reads the color the auto-save draft carries, which the UI must ignore. */
  const readDraftHex = (id: string): string | null | undefined =>
    brandKitApi.endpoints.getAutoSave
      .select(BRAND_KIT_ID)(store.getState())
      .data?.data.colors?.find((color) => color.id === id)?.value.hex;

  // Initiates the edit synchronously so tests can assert the immediate UI
  // updates. Callers should await flush() to complete the request.
  const editColor = (id: string, hex: string) =>
    store.dispatch(
      brandKitApi.endpoints.updateColor.initiate({
        id,
        changes: { value: makeColor(id, hex).value },
      }),
    );

  /** Reads the name a separate caller (e.g. the row's rename field) writes. */
  const readName = (id: string): string | null | undefined =>
    brandKitApi.endpoints.getBrandKit
      .select(BRAND_KIT_ID)(store.getState())
      .data?.colors?.find((color) => color.id === id)?.name;

  // Updates only the color name independently of its hex value.
  const renameColor = (id: string, name: string) =>
    store.dispatch(
      brandKitApi.endpoints.updateColor.initiate({ id, changes: { name } }),
    );

  const readIds = (): string[] =>
    brandKitApi.endpoints.getBrandKit
      .select(BRAND_KIT_ID)(store.getState())
      .data?.colors?.map((color) => color.id) ?? [];

  const createColor = (name: string) =>
    store.dispatch(
      brandKitApi.endpoints.createColor.initiate({
        name,
        cssVariable: `--${name}`,
        weight: 0,
        value: makeColor(name, '#00ff00').value,
      }),
    );

  return {
    store,
    readHex,
    readName,
    readIds,
    readDraftHex,
    editColor,
    renameColor,
    createColor,
  };
};

describe('brand kit color optimistic edits', () => {
  it('applies an edit before the request settles', async () => {
    const { readHex, editColor } = await setup([makeColor('a', '#ff0000')]);

    const edit = editColor('a', '#00ff00');
    await flush();

    // The cache already shows the new value while the write is still open.
    expect(readHex('a')).toBe('#00ff00');

    releaseWrite(200);
    await edit;
    await flush();
    expect(readHex('a')).toBe('#00ff00');
  });

  it('rolls back to the stored value when the write is rejected', async () => {
    const { readHex, editColor } = await setup([makeColor('a', '#ff0000')]);

    const edit = editColor('a', '#00ff00');
    await flush();
    expect(readHex('a')).toBe('#00ff00');

    releaseWrite(422);
    await edit;
    await flush();

    // The UI must not keep showing a value the server refused to store.
    expect(readHex('a')).toBe('#ff0000');
  });

  it('shows a new color before the create completes', async () => {
    const { readIds, createColor } = await setup([makeColor('a', '#ff0000')]);

    const create = createColor('b');
    await flush();

    // The row is there while the request is still open, under a stand-in id.
    expect(readIds()).toHaveLength(2);
    expect(readIds()[1]).toMatch(/^pending-/);

    releaseWrite(201);
    await create;
    await flush();

    // The response's id replaces the stand-in, so the row is actionable.
    expect(readIds()).toEqual(['a', 'assigned-by-server']);
  });

  it('removes an optimistically added color when the create is rejected', async () => {
    const { readIds, createColor } = await setup([makeColor('a', '#ff0000')]);

    const create = createColor('b');
    await flush();
    expect(readIds()).toHaveLength(2);

    releaseWrite(422);
    await create;
    await flush();

    // The rejected color must not linger in a list that claims to be stored.
    expect(readIds()).toEqual(['a']);
  });

  it('ignores an auto-save response that lands mid-write', async () => {
    // Hold the auto-save response so it lands after the optimistic patch, so we
    // can confirm the stale draft data doesn't revert the UI.
    let releaseAutoSave: () => void = () => {};
    heldAutoSave = new Promise<void>((resolve) => {
      releaseAutoSave = resolve;
    });

    const { store, readHex, readDraftHex, editColor } = await setup([
      makeColor('a', '#ff0000'),
    ]);
    store.dispatch(brandKitApi.endpoints.getAutoSave.initiate(BRAND_KIT_ID));
    await flush();

    const edit = editColor('a', '#00ff00');
    await flush();
    expect(readHex('a')).toBe('#00ff00');

    releaseAutoSave();
    await flush();

    // Verify that the UI ignores the outdated auto-save draft and continues
    // reading the optimistic patch.
    expect(readDraftHex('a')).toBe('#ff0000');
    expect(readHex('a')).toBe('#00ff00');

    releaseWrite(200);
    await edit;
  });

  it('does not roll back a newer edit when an older write is rejected', async () => {
    const { readHex, editColor } = await setup([makeColor('a', '#ff0000')]);

    // Dispatch two edits before either completes; capture both release handles.
    const edit1 = editColor('a', '#0000ff'); // blue
    await flush();
    const releaseEdit1 = releaseWrite; // captured before edit2 overwrites it

    const edit2 = editColor('a', '#00ff00'); // green
    await flush();
    const releaseEdit2 = releaseWrite;

    // Both patches applied; cache shows the latest value.
    expect(readHex('a')).toBe('#00ff00');

    // Edit 1 fails — must not revert edit 2's value.
    releaseEdit1(422);
    await edit1;
    await flush();
    expect(readHex('a')).toBe('#00ff00');

    // Edit 2 succeeds.
    releaseEdit2(200);
    await edit2;
    await flush();
    expect(readHex('a')).toBe('#00ff00');
  });

  it('rolls back both edits when two writes to different fields on the same color are both rejected', async () => {
    // Set up two concurrent edits targeting different fields on the same color.
    const { readHex, readName, editColor, renameColor } = await setup([
      makeColor('a', '#ff0000'),
    ]);

    const edit = editColor('a', '#0000ff'); // hex popover
    await flush();
    const releaseEdit = releaseWrite; // captured before rename overwrites it

    const rename = renameColor('a', 'Renamed'); // row rename field
    await flush();
    const releaseRename = releaseWrite;

    // Both patches applied optimistically.
    expect(readHex('a')).toBe('#0000ff');
    expect(readName('a')).toBe('Renamed');

    // Reject the earlier hex edit while the newer rename is still pending.
    releaseEdit(422);
    await edit;
    await flush();

    // Reject the concurrent rename and assert that both failed edits are fully
    // reverted.
    releaseRename(422);
    await rename;
    await flush();

    expect(readHex('a')).toBe('#ff0000');
    expect(readName('a')).toBe('a');
  });
});
