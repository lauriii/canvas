import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { makeStore } from '@/app/store';
import { initialState as configuration } from '@/features/configuration/configurationSlice';
import {
  NodeType,
  selectLayout,
  selectModel,
  selectUpdatePreview,
  setInitialLayoutModel,
  setLayoutModel,
} from '@/features/layout/layoutModelSlice';
import {
  isPreviewEditedSince,
  notePreviewEdit,
} from '@/features/layout/preview/previewEdits';
import {
  selectPreviewHtml,
  selectPreviewHtmlEditsAtRequest,
} from '@/features/pagePreview/previewSlice';
import { EditorFrameContext } from '@/features/ui/uiSlice';
import { previewApi } from '@/services/preview';

import type { RegionNode } from '@/features/layout/layoutModelSlice';

const C = '11111111-1111-4111-8111-111111111111';
const layout: RegionNode[] = [
  {
    id: 'content',
    name: 'Content',
    nodeType: NodeType.Region,
    components: [
      {
        uuid: C,
        type: 'sdc.test.a@1',
        nodeType: NodeType.Component,
        slots: [],
      },
    ],
  },
];

/** Settles the held requests, in the order they were sent. */
let respond: Array<(body: unknown) => void> = [];

const flush = () => new Promise((resolve) => setTimeout(resolve, 0));

describe('updateComponent', () => {
  beforeEach(() => {
    respond = [];
    window.history.pushState({}, '', '/canvas/editor/node/1');
    vi.stubGlobal('fetch', async (input: RequestInfo | URL) => {
      if ((input as Request).url.includes('session/token')) {
        return new Response('token');
      }
      return new Promise<Response>((resolve) => {
        respond.push((body) =>
          resolve(
            new Response(JSON.stringify(body), {
              headers: { 'Content-Type': 'application/json' },
            }),
          ),
        );
      });
    });
  });
  afterEach(async () => {
    vi.unstubAllGlobals();
    // Let the timer popCanvasLayoutRequest() starts run while the document
    // still exists.
    await new Promise((resolve) => setTimeout(resolve, 20));
  });

  const setup = () => {
    const store = makeStore({
      configuration: { ...configuration, baseUrl: 'http://localhost/' },
    });
    const patch = async (text: string) => {
      const request = store.dispatch(
        previewApi.endpoints.updateComponent.initiate({
          type: EditorFrameContext.ENTITY,
          componentInstanceUuid: C,
          componentType: 'sdc.test.a@1',
          model: { resolved: { text } },
        }),
      );
      await flush();
      const index = respond.length - 1;
      return async () => {
        respond[index]({
          html: `<p>${text}</p>`,
          layout,
          model: { [C]: { resolved: { text } } },
          autoSaves: {},
        });
        await request;
        await flush();
      };
    };
    // A render built from the store, e.g. after a move.
    const render = async (text: string) => {
      const request = store.dispatch(
        previewApi.endpoints.postPreview.initiate({
          entityType: 'node',
          entityId: '1',
          layout,
          model: { [C]: { resolved: { text } } },
          entity_form_fields: {},
        }),
      );
      await flush();
      const index = respond.length - 1;
      return async () => {
        respond[index]({ html: `<p>moved, ${text}</p>`, autoSaves: {} });
        await request;
        await flush();
      };
    };
    const isStale = () =>
      isPreviewEditedSince(selectPreviewHtmlEditsAtRequest(store.getState()));
    return { store, patch, render, isStale };
  };

  // A prop value reaches the model only with the PATCH response, so a render
  // requested meanwhile, e.g. after a move, doesn't have it, and its response
  // has the layout from before the move.
  it('keeps a later move and sends a prop value again when the render of the move overtook it', async () => {
    const { store, patch, render } = setup();
    const old = { [C]: { resolved: { text: 'old' } } };
    store.dispatch(
      setInitialLayoutModel({ layout, model: old, updatePreview: false }),
    );
    const settlePatch = await patch('new');
    const moved = structuredClone(layout);
    store.dispatch(
      setLayoutModel({ layout: moved, model: old, updatePreview: true }),
    );
    notePreviewEdit();
    const settleRender = await render('old');
    await settleRender();
    await settlePatch();
    expect(selectLayout(store.getState())).toBe(moved);
    expect(selectModel(store.getState())).toEqual({
      [C]: { resolved: { text: 'new' } },
    });
    // The preview requests a render of both.
    expect(selectUpdatePreview(store.getState())).toBe(true);
    expect(selectPreviewHtml(store.getState())).toBe('<p>moved, old</p>');
  });

  it('applies a prop edit that no render overtook', async () => {
    const { store, patch } = setup();
    const settlePatch = await patch('new');
    await settlePatch();
    expect(selectModel(store.getState())).toEqual({
      [C]: { resolved: { text: 'new' } },
    });
    expect(selectUpdatePreview(store.getState())).toBe(false);
    expect(selectPreviewHtml(store.getState())).toBe('<p>new</p>');
  });

  it('keeps a later move shown while its render is on its way', async () => {
    const { patch, render, isStale } = setup();
    const settlePatch = await patch('new');
    notePreviewEdit();
    const settleRender = await render('old');
    await settlePatch();
    expect(isStale()).toBe(true);
    await settleRender();
    expect(isStale()).toBe(false);
  });

  it('keeps typing shown when an earlier response arrives last', async () => {
    const { patch, isStale } = setup();
    // Each keystroke is shown in the preview, then sent.
    notePreviewEdit();
    const settleA = await patch('a');
    notePreviewEdit();
    const settleAb = await patch('ab');
    await settleAb();
    expect(isStale()).toBe(false);
    await settleA();
    expect(isStale()).toBe(true);
  });
});
