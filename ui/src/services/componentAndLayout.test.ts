import { beforeEach, describe, expect, it, vi } from 'vitest';

import { makeStore } from '@/app/store';
import { initialState as configurationInitialState } from '@/features/configuration/configurationSlice';
import { NodeType, setLayoutModel } from '@/features/layout/layoutModelSlice';
import { componentAndLayoutApi } from '@/services/componentAndLayout';

import type { LayoutApiResponse } from '@/services/componentAndLayout';

// The endpoints under test run their `onQueryStarted` side effects against a
// real store, so the base query has to reach a real `fetch`.
const fetchMock = vi.fn<typeof fetch>();
vi.stubGlobal('fetch', fetchMock);

const SERVER_UUID = 'aaaaaaaa-1111-4111-8111-aaaaaaaaaaaa';
const SEEDED_UUID = 'bbbbbbbb-2222-4222-8222-bbbbbbbbbbbb';

// What the editor already holds before the query runs.
const seededLayoutModel = {
  layout: [
    {
      nodeType: NodeType.Region as const,
      id: 'content',
      name: 'Content',
      components: [
        {
          nodeType: NodeType.Component as const,
          uuid: SEEDED_UUID,
          type: 'sdc.canvas.heading',
          slots: [],
        },
      ],
    },
  ],
  model: { [SEEDED_UUID]: { resolved: { text: 'Client' } } },
};

// What the server returns, after e.g. a `hook_entity_presave` rewrote the tree.
const serverResponse: LayoutApiResponse = {
  layout: [
    {
      nodeType: NodeType.Region,
      id: 'content',
      name: 'Content',
      components: [
        {
          nodeType: NodeType.Component,
          uuid: SERVER_UUID,
          type: 'sdc.canvas.heading',
          slots: [],
        },
      ],
    },
  ],
  model: { [SERVER_UUID]: { resolved: { text: 'Server' } } },
  entity_form_fields: { 'title[0][value]': 'Server title' },
  isNew: false,
  isPublished: false,
  html: '<div>server html</div>',
  // `handleAutoSavesHashUpdate` keys this off the request URL taken from the
  // response meta, so the mocked response has to be a real `Response`.
  autoSaves: {
    'canvas/api/v0/layout/canvas_page/1': {
      autoSaveStartingPoint: 'starting-point',
      hash: 'hash',
    },
  },
};

const makeSeededStore = () => {
  // An absolute base URL: the base query builds a `Request`, which cannot parse
  // a root-relative URL outside a browser.
  const store = makeStore({
    configuration: {
      ...configurationInitialState,
      baseUrl: 'http://localhost/',
    },
  });
  store.dispatch(setLayoutModel({ ...seededLayoutModel, updatePreview: true }));
  return store;
};

describe('componentAndLayoutApi layout refresh', () => {
  beforeEach(() => {
    // A fresh `Response` per call: RTK Query consumes the body.
    fetchMock.mockImplementation(() =>
      Promise.resolve(
        new Response(JSON.stringify(serverResponse), {
          status: 200,
          headers: { 'Content-Type': 'application/json' },
        }),
      ),
    );
  });

  it('applies the layout model returned by getPageLayout', async () => {
    const store = makeSeededStore();

    await store.dispatch(
      componentAndLayoutApi.endpoints.getPageLayout.initiate({
        entityType: 'canvas_page',
        entityId: '1',
      }),
    );

    const { present } = store.getState().layoutModel;
    expect(present.layout).toEqual(serverResponse.layout);
    expect(present.model).toEqual(serverResponse.model);
    // A layout fetch must not schedule a preview refresh.
    expect(present.updatePreview).toBe(false);
  });

  it('leaves the layout model alone when getPageLayout requests a translation', async () => {
    const store = makeSeededStore();

    await store.dispatch(
      componentAndLayoutApi.endpoints.getPageLayout.initiate({
        entityType: 'canvas_page',
        entityId: '1',
        language: 'nl',
      }),
    );

    // The `onQueryStarted` success path ran to completion — without this the
    // test would also pass if the response had thrown into the `catch` branch.
    expect(store.getState().publishReview.autoSavesHash).toHaveProperty(
      'canvas/api/v0/layout/canvas_page/1',
    );
    const { present } = store.getState().layoutModel;
    expect(present.layout).toEqual(seededLayoutModel.layout);
    expect(present.model).toEqual(seededLayoutModel.model);
  });

  it('applies the layout model returned by getPatternLayout', async () => {
    const store = makeSeededStore();

    await store.dispatch(
      componentAndLayoutApi.endpoints.getPatternLayout.initiate('pattern_id'),
    );

    const { present } = store.getState().layoutModel;
    expect(present.layout).toEqual(serverResponse.layout);
    expect(present.model).toEqual(serverResponse.model);
    expect(present.updatePreview).toBe(false);
  });
});
