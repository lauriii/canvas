import { Provider } from 'react-redux';
import { MemoryRouter, Route, Routes } from 'react-router';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { act, render } from '@testing-library/react';

import { makeStore } from '@/app/store';
import {
  deleteNode,
  initialState as layoutModelInitialState,
} from '@/features/layout/layoutModelSlice';
import { setPageData } from '@/features/pageData/pageDataSlice';
import {
  EditorFrameContext,
  initialState as uiInitialState,
  UndoRedoActionCreators,
} from '@/features/ui/uiSlice';

import Preview from './Preview';

const mocks = vi.hoisted(() => ({
  postPreview: vi.fn(),
  postTemplatePreview: vi.fn(),
  postPatternPreview: vi.fn(),
}));

vi.mock('@/services/preview', async (importOriginal) => ({
  ...(await importOriginal<object>()),
  useQueuedPostPreviewMutation: () => [mocks.postPreview, { isLoading: false }],
}));
vi.mock('@/services/componentAndLayout', async (importOriginal) => ({
  ...(await importOriginal<object>()),
  useGetPageLayoutQuery: () => ({ isFetching: false }),
  usePostTemplateLayoutMutation: () => [
    mocks.postTemplatePreview,
    { isLoading: false },
  ],
  usePostPatternLayoutMutation: () => [
    mocks.postPatternPreview,
    { isLoading: false },
  ],
}));
vi.mock('react-error-boundary', () => ({
  useErrorBoundary: () => ({ showBoundary: vi.fn() }),
}));
vi.mock('@/hooks/useCanvasHeadlessSettings', () => ({
  useCanvasHeadlessSettings: () => null,
}));
vi.mock('@/hooks/useSyncTitle', () => ({ default: () => {} }));
vi.mock('@/utils/isAjaxing', () => ({ isAjaxing: () => false }));
vi.mock('@/features/layout/preview/Viewport', () => ({
  default: () => null,
}));

const layoutModel = {
  ...layoutModelInitialState,
  isInitialized: true,
  layout: [
    {
      nodeType: 'region' as const,
      id: 'content',
      name: 'Content',
      components: [
        {
          nodeType: 'component' as const,
          uuid: 'cta-1',
          type: 'sdc_test:my-cta',
          slots: [],
        },
        {
          nodeType: 'component' as const,
          uuid: 'cta-2',
          type: 'sdc_test:my-cta',
          slots: [],
        },
      ],
    },
  ],
  model: {
    'cta-1': { resolved: { text: 'One' }, source: {} },
    'cta-2': { resolved: { text: 'Two' }, source: {} },
  },
};

describe('Preview', () => {
  beforeEach(() => {
    mocks.postPreview.mockResolvedValue(undefined);
    mocks.postTemplatePreview.mockReturnValue({
      unwrap: () => Promise.resolve(),
    });
    mocks.postPatternPreview.mockReturnValue({
      unwrap: () => Promise.resolve(),
    });
  });

  it.each([
    [EditorFrameContext.ENTITY, mocks.postPreview],
    [EditorFrameContext.TEMPLATE, mocks.postTemplatePreview],
    [EditorFrameContext.PATTERN, mocks.postPatternPreview],
  ])(
    'sends one preview request per edit, undo and redo (%s context)',
    (editorFrameContext, post) => {
      const store = makeStore({
        layoutModel: { present: layoutModel, past: [], future: [] },
        pageData: { present: { title: 'Title' }, past: [], future: [] },
        ui: { ...uiInitialState, editorFrameContext },
      } as Parameters<typeof makeStore>[0]);
      render(
        <Provider store={store}>
          <MemoryRouter initialEntries={['/editor/canvas_page/1']}>
            <Routes>
              <Route
                path="/editor/:entityType/:entityId"
                element={<Preview />}
              />
            </Routes>
          </MemoryRouter>
        </Provider>,
      );
      expect(post).not.toHaveBeenCalled();

      const expectRequestCount = (count: number) => {
        expect(post).toHaveBeenCalledTimes(count);
        // Each request sends the store's current layout and model.
        const { layout, model } = store.getState().layoutModel.present;
        expect(post).toHaveBeenLastCalledWith(
          expect.objectContaining({ layout, model }),
        );
      };

      // A structural edit.
      act(() => {
        store.dispatch(deleteNode('cta-1'));
      });
      expectRequestCount(1);

      // Undo and redo of the structural edit.
      act(() => {
        store.dispatch(UndoRedoActionCreators.undo('layoutModel'));
      });
      expectRequestCount(2);
      act(() => {
        store.dispatch(UndoRedoActionCreators.redo('layoutModel'));
      });
      expectRequestCount(3);

      // A page data edit, then its undo.
      act(() => {
        store.dispatch(setPageData({ title: 'New title' }));
      });
      expectRequestCount(4);
      act(() => {
        store.dispatch(UndoRedoActionCreators.undo('pageData'));
      });
      expectRequestCount(5);

      // A structural edit after an undo still sends a request.
      act(() => {
        store.dispatch(deleteNode('cta-2'));
      });
      expectRequestCount(6);
    },
  );
});
