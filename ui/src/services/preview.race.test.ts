import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { makeStore } from '@/app/store';
import { EditorFrameContext } from '@/features/ui/uiSlice';
import { previewApi } from '@/services/preview';

import type { AppStore } from '@/app/store';

// Reproduction for #3547665: a real-time preview update must not be reverted by
// a stale server response from an earlier updateComponent request.
//
// Two updateComponent requests are dispatched in order (A then B). The mocked
// server responds to B first and to A last, so A's (older) response is the last
// to arrive. The preview must reflect B — the most recent request — not the
// stale A response that happened to land last.
describe('updateComponent stale response handling', () => {
  let store: AppStore;

  const STALE_HTML = 'HTML_FROM_STALE_REQUEST_A';
  const LATEST_HTML = 'HTML_FROM_LATEST_REQUEST_B';

  const respondJson = (body: unknown, delayMs: number) =>
    new Promise<Response>((resolve) => {
      setTimeout(() => {
        resolve(
          new Response(JSON.stringify(body), {
            status: 200,
            headers: { 'content-type': 'application/json' },
          }),
        );
      }, delayMs);
    });

  beforeEach(() => {
    // The base query extracts entity params from window.location, so point it
    // at a route the editor regex recognizes.
    window.history.pushState({}, '', '/canvas/editor/canvas_page/1');

    global.fetch = vi.fn(async (input: any, init?: any) => {
      const request =
        typeof input === 'string' ? new Request(input, init) : input;
      const url = request.url;
      if (url.includes('session/token')) {
        return new Response('csrf-token', { status: 200 });
      }
      const bodyText = await request.clone().text();
      // Request A carries the "first-value" marker and is answered last;
      // request B carries "second-value" and is answered first.
      if (bodyText.includes('first-value')) {
        return respondJson(
          { html: STALE_HTML, layout: [], model: { marker: 'A' } },
          60,
        );
      }
      return respondJson(
        { html: LATEST_HTML, layout: [], model: { marker: 'B' } },
        10,
      );
    }) as any;

    // fetchBaseQuery builds a Request from baseUrl + path; undici requires an
    // absolute URL, so preload an absolute baseUrl.
    store = makeStore({
      configuration: {
        baseUrl: 'http://localhost:3000/',
        entityType: 'canvas_page',
        entity: '1',
        isNew: false,
        isPublished: false,
        devMode: false,
        homepageStagedConfigExists: false,
      },
    });
  });

  afterEach(() => {
    vi.restoreAllMocks();
  });

  it('keeps the latest request html when an older response arrives last', async () => {
    const requestA = store.dispatch(
      previewApi.endpoints.updateComponent.initiate({
        type: EditorFrameContext.ENTITY,
        componentInstanceUuid: 'uuid-1',
        componentType: 'sdc.test@1',
        model: { resolved: { value: 'first-value' }, source: {} } as any,
      }),
    );
    const requestB = store.dispatch(
      previewApi.endpoints.updateComponent.initiate({
        type: EditorFrameContext.ENTITY,
        componentInstanceUuid: 'uuid-1',
        componentType: 'sdc.test@1',
        model: { resolved: { value: 'second-value' }, source: {} } as any,
      }),
    );

    await Promise.allSettled([requestA, requestB]);
    // Let the onQueryStarted lifecycle dispatches settle for both requests.
    await new Promise((resolve) => setTimeout(resolve, 100));

    expect(store.getState().preview.html).toBe(LATEST_HTML);
  });

  it('keeps an earlier success when a later request fails', async () => {
    // The mock answers request A (first-value) with a 200 and request B
    // (second-value) with a 422, so the newest request fails. A's committed
    // result must survive rather than being discarded because a newer request
    // started.
    global.fetch = vi.fn(async (input: any, init?: any) => {
      const request =
        typeof input === 'string' ? new Request(input, init) : input;
      const url = request.url;
      if (url.includes('session/token')) {
        return new Response('csrf-token', { status: 200 });
      }
      const bodyText = await request.clone().text();
      if (bodyText.includes('second-value')) {
        return new Response(JSON.stringify({ errors: ['invalid'] }), {
          status: 422,
          headers: { 'content-type': 'application/json' },
        });
      }
      return new Response(
        JSON.stringify({
          html: STALE_HTML,
          layout: [],
          model: { marker: 'A' },
        }),
        { status: 200, headers: { 'content-type': 'application/json' } },
      );
    }) as any;

    const requestA = store.dispatch(
      previewApi.endpoints.updateComponent.initiate({
        type: EditorFrameContext.ENTITY,
        componentInstanceUuid: 'uuid-1',
        componentType: 'sdc.test@1',
        model: { resolved: { value: 'first-value' }, source: {} } as any,
      }),
    );
    const requestB = store.dispatch(
      previewApi.endpoints.updateComponent.initiate({
        type: EditorFrameContext.ENTITY,
        componentInstanceUuid: 'uuid-1',
        componentType: 'sdc.test@1',
        model: { resolved: { value: 'second-value' }, source: {} } as any,
      }),
    );

    await Promise.allSettled([requestA, requestB]);
    await new Promise((resolve) => setTimeout(resolve, 50));

    expect(store.getState().preview.html).toBe(STALE_HTML);
  });

  it('still applies the html for a single (non-superseded) request', async () => {
    await store.dispatch(
      previewApi.endpoints.updateComponent.initiate({
        type: EditorFrameContext.ENTITY,
        componentInstanceUuid: 'uuid-1',
        componentType: 'sdc.test@1',
        model: { resolved: { value: 'second-value' }, source: {} } as any,
      }),
    );
    await new Promise((resolve) => setTimeout(resolve, 50));

    expect(store.getState().preview.html).toBe(LATEST_HTML);
  });
});
