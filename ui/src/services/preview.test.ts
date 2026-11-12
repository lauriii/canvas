import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { makeStore } from '@/app/store';
import { selectPreviewHtml } from '@/features/pagePreview/previewSlice';
import { EditorFrameContext } from '@/features/ui/uiSlice';
import {
  previewApi,
  registerClientSidePreviewUpdate,
} from '@/services/preview';

const patchArg = (title: string) => ({
  type: EditorFrameContext.ENTITY,
  componentInstanceUuid: 'uuid-1',
  componentType: 'js.card@abc',
  model: { resolved: { title } } as any,
});

// Mocks fetch: the CSRF token resolves at once, each PATCH waits until the
// test responds to it.
const mockFetch = () => {
  const patches: Array<(html: string) => void> = [];
  vi.spyOn(globalThis, 'fetch').mockImplementation(async (input) => {
    const url = input instanceof Request ? input.url : String(input);
    if (url.includes('session/token')) {
      return new Response('token');
    }
    return new Promise((resolve) => {
      patches.push((html) =>
        resolve(
          new Response(
            JSON.stringify({ html, layout: [], model: {}, autoSaves: {} }),
            { headers: { 'Content-Type': 'application/json' } },
          ),
        ),
      );
    });
  });
  return patches;
};

const settle = () => new Promise((resolve) => setTimeout(resolve, 0));

describe('updateComponent', () => {
  beforeEach(() => {
    // The PATCH URL takes the entity from the editor URL.
    window.history.pushState({}, '', '/canvas/editor/canvas_page/1');
    // Resolve the API's relative URLs, as a browser does.
    const NativeRequest = Request;
    vi.stubGlobal(
      'Request',
      class extends NativeRequest {
        constructor(input: RequestInfo | URL, init?: RequestInit) {
          super(
            typeof input === 'string'
              ? new URL(input, window.location.origin)
              : input,
            init,
          );
        }
      },
    );
  });
  afterEach(() => {
    vi.restoreAllMocks();
    vi.unstubAllGlobals();
  });

  it('does not show preview HTML that predates a client-side update', async () => {
    const patches = mockFetch();
    const store = makeStore();
    const first = store.dispatch(
      previewApi.endpoints.updateComponent.initiate(patchArg('a')),
    );
    const second = store.dispatch(
      previewApi.endpoints.updateComponent.initiate(patchArg('ab')),
    );
    await settle();
    // The user keeps typing; the preview is updated client-side.
    registerClientSidePreviewUpdate();
    const third = store.dispatch(
      previewApi.endpoints.updateComponent.initiate(patchArg('abc')),
    );
    await settle();
    expect(patches).toHaveLength(3);

    patches[0]('<p>a</p>');
    await first;
    expect(selectPreviewHtml(store.getState())).toBe('');

    // Responses arriving out of order are skipped too.
    patches[2]('<p>abc</p>');
    await third;
    expect(selectPreviewHtml(store.getState())).toBe('<p>abc</p>');
    patches[1]('<p>ab</p>');
    await second;
    expect(selectPreviewHtml(store.getState())).toBe('<p>abc</p>');
  });
});
