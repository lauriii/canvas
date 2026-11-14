import { Provider } from 'react-redux';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { renderHook } from '@testing-library/react';

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
import { componentAndLayoutApi } from '@/services/componentAndLayout';
import { previewApi, useQueuedPostPreviewMutation } from '@/services/preview';

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
    const patch = async (text: string, uuid = C) => {
      const request = store.dispatch(
        previewApi.endpoints.updateComponent.initiate({
          type: EditorFrameContext.ENTITY,
          componentInstanceUuid: uuid,
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
          model: { [uuid]: { resolved: { text } } },
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

  it('keeps a move whose render is yet to be sent when a prop edit response arrives', async () => {
    const { store, patch } = setup();
    const old = { [C]: { resolved: { text: 'old' } } };
    store.dispatch(
      setInitialLayoutModel({ layout, model: old, updatePreview: false }),
    );
    const settlePatch = await patch('new');
    // E.g. its render waits for a Drupal AJAX request.
    const moved = structuredClone(layout);
    store.dispatch(
      setLayoutModel({ layout: moved, model: old, updatePreview: true }),
    );
    await settlePatch();
    expect(selectLayout(store.getState())).toBe(moved);
    expect(selectModel(store.getState())[C]).toEqual({
      resolved: { text: 'new' },
    });
    expect(selectPreviewHtml(store.getState())).not.toBe('<p>new</p>');
  });

  it('sends a prop value again when a render without a layout edit overtook it', async () => {
    const { store, patch, render } = setup();
    store.dispatch(
      setInitialLayoutModel({
        layout,
        model: { [C]: { resolved: { text: 'old' } } },
        updatePreview: false,
      }),
    );
    const settlePatch = await patch('new');
    // E.g. for an edit of the page title.
    const settleRender = await render('old');
    await settleRender();
    await settlePatch();
    expect(selectModel(store.getState())[C]).toEqual({
      resolved: { text: 'new' },
    });
    expect(selectUpdatePreview(store.getState())).toBe(true);
  });

  it("doesn't take a prop edit to be overtaken by the response to another one", async () => {
    const { store, patch } = setup();
    const D = '22222222-2222-4222-8222-222222222222';
    store.dispatch(
      setInitialLayoutModel({
        layout,
        model: {
          [C]: { resolved: { text: 'old' } },
          [D]: { resolved: { text: 'old' } },
        },
        updatePreview: false,
      }),
    );
    const settleC = await patch('c');
    const settleD = await patch('d', D);
    await settleC();
    await settleD();
    expect(selectUpdatePreview(store.getState())).toBe(false);
    expect(selectPreviewHtml(store.getState())).toBe('<p>d</p>');
  });

  it('sends a prop value again when a render was on its way as it was sent', async () => {
    const { store, patch, render } = setup();
    store.dispatch(
      setInitialLayoutModel({
        layout,
        model: { [C]: { resolved: { text: 'old' } } },
        updatePreview: false,
      }),
    );
    // The server may save that render after the prop value.
    const settleRender = await render('old');
    const settlePatch = await patch('new');
    await settlePatch();
    await settleRender();
    expect(selectModel(store.getState())[C]).toEqual({
      resolved: { text: 'new' },
    });
    expect(selectUpdatePreview(store.getState())).toBe(true);
  });

  it('sends a prop value again when a render was queued after it was sent', async () => {
    const { store, patch } = setup();
    const old = { [C]: { resolved: { text: 'old' } } };
    store.dispatch(
      setInitialLayoutModel({ layout, model: old, updatePreview: false }),
    );
    const { result } = renderHook(() => useQueuedPostPreviewMutation(), {
      wrapper: ({ children }) => <Provider store={store}>{children}</Provider>,
    });
    const [post] = result.current;
    const arg = { entityType: 'node', entityId: '1', entity_form_fields: {} };
    // A move is rendered; a prop edit is sent meanwhile.
    post({ ...arg, layout, model: old });
    await flush();
    const settlePatch = await patch('new');
    // A second move, whose render is queued behind the first.
    const moved = structuredClone(layout);
    store.dispatch(
      setLayoutModel({ layout: moved, model: old, updatePreview: true }),
    );
    post({ ...arg, layout: moved, model: old });
    await flush();
    await settlePatch();
    expect(selectLayout(store.getState())).toBe(moved);
    expect(selectUpdatePreview(store.getState())).toBe(true);
    // Answer the renders.
    respond[0]({ html: '<p>moved</p>', autoSaves: {} });
    await flush();
    await flush();
    respond[respond.length - 1]({ html: '<p>moved</p>', autoSaves: {} });
    await flush();
  });

  it("doesn't apply an overtaken prop edit of a component that was removed", async () => {
    const { store, patch, render } = setup();
    store.dispatch(
      setInitialLayoutModel({
        layout,
        model: { [C]: { resolved: { text: 'old' } } },
        updatePreview: false,
      }),
    );
    const settlePatch = await patch('new');
    const empty = [{ ...layout[0], components: [] }];
    store.dispatch(
      setLayoutModel({ layout: empty, model: {}, updatePreview: true }),
    );
    const settleRender = await render('old');
    await settleRender();
    store.dispatch(
      setLayoutModel({ layout: empty, model: {}, updatePreview: false }),
    );
    await settlePatch();
    expect(selectModel(store.getState())).toEqual({});
    expect(selectUpdatePreview(store.getState())).toBe(false);
  });

  it('ignores the response to a prop edit when a later edit of the component was sent', async () => {
    const { store, patch, isStale } = setup();
    // Each keystroke is shown in the preview, then sent.
    notePreviewEdit();
    const settleA = await patch('a');
    notePreviewEdit();
    const settleAb = await patch('ab');
    await settleAb();
    expect(isStale()).toBe(false);
    await settleA();
    expect(selectPreviewHtml(store.getState())).toBe('<p>ab</p>');
    expect(selectModel(store.getState())[C]).toEqual({
      resolved: { text: 'ab' },
    });
    expect(isStale()).toBe(false);
  });

  it('ignores the render of a template layout answered after a later one', async () => {
    const { store } = setup();
    window.history.pushState({}, '', '/canvas/template/node/article/full/1');
    const post = async (text: string) => {
      const request = store.dispatch(
        componentAndLayoutApi.endpoints.postTemplateLayout.initiate({
          layout,
          model: {},
          entity_form_fields: {},
        }),
      );
      await flush();
      const index = respond.length - 1;
      return async () => {
        respond[index]({ html: `<p>${text}</p>`, autoSaves: {} });
        await request;
        await flush();
      };
    };
    const settleFirst = await post('first');
    const settleSecond = await post('second');
    await settleSecond();
    await settleFirst();
    expect(selectPreviewHtml(store.getState())).toBe('<p>second</p>');
  });
});
