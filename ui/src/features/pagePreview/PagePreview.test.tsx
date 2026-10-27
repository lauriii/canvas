import { MemoryRouter, Route, Routes } from 'react-router';
import { describe, expect, it, vi } from 'vitest';
import { render, screen } from '@testing-library/react';

import PagePreview from './PagePreview';

const mocks = vi.hoisted(() => ({
  session: vi.fn(() => ({ statusText: 'Preview active' })),
  snapshot: vi.fn((): object => ({})),
  dispatch: vi.fn(),
  headless: { frontendUrl: 'https://app.example' } as object | null,
  layout: vi.fn((): object => ({})),
}));
vi.mock('@/app/hooks', () => ({
  useAppDispatch: () => mocks.dispatch,
  useAppSelector: () => undefined,
}));
vi.mock('react-error-boundary', () => ({
  useErrorBoundary: () => ({ showBoundary: vi.fn() }),
}));
vi.mock('@/hooks/useCanvasHeadlessSettings', () => ({
  useCanvasHeadlessSettings: () => mocks.headless,
}));
vi.mock('@/features/layout/preview/useHeadlessDraftSession', () => ({
  useHeadlessDraftSession: mocks.session,
}));
vi.mock('@/services/componentAndLayout', () => ({
  useGetPageLayoutQuery: mocks.layout,
}));
vi.mock('@/services/preview', () => ({
  useGetSnapshotPreviewQuery: mocks.snapshot,
  useQueuedPostPreviewMutation: () => [vi.fn()],
}));
vi.mock('@/utils/viewports', () => ({ getViewportSizes: () => [] }));

describe('read-only Headless preview', () => {
  it.each([
    [
      '/preview/canvas_page/7/full',
      '/preview/:entityType/:entityId/:width',
      undefined,
    ],
    [
      '/preview/template/node/article/7/teaser/full',
      '/preview/template/:entityType/:bundle/:entityId/:viewMode/:width',
      'teaser',
    ],
    [
      '/preview/page_variant/alternate/full',
      '/preview/:entityType/:entityId/:width',
      undefined,
    ],
  ])(
    'embeds the frontend with the selected language at %s',
    (path, route, viewMode) => {
      const preview = render(
        <MemoryRouter initialEntries={[`${path}?language=fr`]}>
          <Routes>
            <Route path={route} element={<PagePreview />} />
          </Routes>
        </MemoryRouter>,
      );
      expect(screen.getByTitle('Page preview')).not.toHaveAttribute('srcdoc');
      expect(mocks.session).toHaveBeenLastCalledWith(
        expect.any(Object),
        expect.any(Object),
        expect.any(String),
        expect.any(String),
        undefined,
        undefined,
        { viewMode, language: 'fr' },
      );
      expect(mocks.snapshot).toHaveBeenLastCalledWith(
        expect.any(Object),
        expect.objectContaining({ skip: true }),
      );
      preview.unmount();
    },
  );
});

describe('language preview', () => {
  it('re-fetches the translation after every default layout fetch', () => {
    mocks.headless = null;
    const refetch = vi.fn();
    mocks.snapshot.mockReturnValue({ refetch });
    const layout = (fetchedAt: number, available: string[]) =>
      mocks.layout.mockReturnValue({
        data: { translations: { available, forked: [] } },
        fulfilledTimeStamp: fetchedAt,
      });
    const tree = () => (
      <MemoryRouter
        initialEntries={['/preview/canvas_page/7/full?language=fr']}
      >
        <Routes>
          <Route
            path="/preview/:entityType/:entityId/:width"
            element={<PagePreview />}
          />
        </Routes>
      </MemoryRouter>
    );

    layout(1, ['en', 'fr']);
    const preview = render(tree());
    expect(refetch).not.toHaveBeenCalled();
    // A refetch that changes nothing about the active translation, e.g. after
    // forking another one, still resets the snapshot.
    layout(2, ['en', 'fr']);
    preview.rerender(tree());
    expect(refetch).toHaveBeenCalledTimes(1);
    // A deleted translation has nothing to re-fetch.
    layout(3, ['en']);
    preview.rerender(tree());
    expect(refetch).toHaveBeenCalledTimes(1);
    preview.unmount();
  });
});
