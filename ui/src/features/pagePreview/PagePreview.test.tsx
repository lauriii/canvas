import { MemoryRouter, Route, Routes } from 'react-router';
import { describe, expect, it, vi } from 'vitest';
import { render, screen } from '@testing-library/react';

import PagePreview from './PagePreview';

const mocks = vi.hoisted(() => ({
  session: vi.fn(() => ({ statusText: 'Preview active' })),
  snapshot: vi.fn(() => ({})),
  dispatch: vi.fn(),
}));
vi.mock('@/app/hooks', () => ({
  useAppDispatch: () => mocks.dispatch,
  useAppSelector: () => undefined,
}));
vi.mock('react-error-boundary', () => ({
  useErrorBoundary: () => ({ showBoundary: vi.fn() }),
}));
vi.mock('@/hooks/useCanvasHeadlessSettings', () => ({
  useCanvasHeadlessSettings: () => ({ frontendUrl: 'https://app.example' }),
}));
vi.mock('@/features/layout/preview/useHeadlessDraftSession', () => ({
  useHeadlessDraftSession: mocks.session,
}));
vi.mock('@/services/componentAndLayout', () => ({
  useGetPageLayoutQuery: vi.fn(() => ({ refetch: vi.fn() })),
}));
vi.mock('@/services/preview', () => ({
  useGetSnapshotPreviewQuery: mocks.snapshot,
  useQueuedPostPreviewMutation: () => [vi.fn()],
}));
vi.mock('@/utils/viewports', () => ({ getViewportSizes: () => [] }));
vi.mock('@/components/languageSelect/LanguageBar', () => ({
  default: () => <div data-testid="language-bar" />,
}));
vi.mock('@/features/layout/preview/ViewportSelector', () => ({
  default: () => <div data-testid="viewport-selector" />,
}));
vi.stubGlobal(
  'ResizeObserver',
  class {
    observe() {}
    disconnect() {}
  },
);

describe('Headless page in another language, in the editor', () => {
  it('embeds the frontend in that language under the language bar', () => {
    const view = render(
      <MemoryRouter initialEntries={['/editor/canvas_page/7?language=fr']}>
        <Routes>
          <Route
            path="/editor/:entityType/:entityId"
            element={<PagePreview />}
          />
        </Routes>
      </MemoryRouter>,
    );
    expect(screen.getByTestId('language-bar')).toBeInTheDocument();
    // At a viewport, and rendered before its size is known.
    expect(screen.getByTestId('canvas-language-viewport')).toContainElement(
      screen.getByTitle('Page preview'),
    );
    expect(screen.getByTestId('viewport-selector')).toBeInTheDocument();
    expect(mocks.session).toHaveBeenLastCalledWith(
      expect.any(Object),
      expect.any(Object),
      'canvas_page',
      '7',
      undefined,
      undefined,
      { viewMode: undefined, language: 'fr' },
    );
    view.unmount();
  });

  it('embeds a template in that language, in the template editor', () => {
    const view = render(
      <MemoryRouter
        initialEntries={['/template/node/article/teaser/7?language=fr']}
      >
        <Routes>
          <Route
            path="/template/:entityType/:bundle/:viewMode/:previewEntityId"
            element={<PagePreview />}
          />
        </Routes>
      </MemoryRouter>,
    );
    // Nothing loads the translations of a template in headless mode.
    expect(screen.queryByTestId('language-bar')).toBeNull();
    expect(mocks.session).toHaveBeenLastCalledWith(
      expect.any(Object),
      expect.any(Object),
      'node',
      '7',
      undefined,
      undefined,
      { viewMode: 'teaser', language: 'fr' },
    );
    view.unmount();
  });
});

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
      // The preview shows the page the way visitors see it: no language bar,
      // and its own width selector.
      expect(screen.queryByTestId('language-bar')).toBeNull();
      expect(screen.queryByTestId('canvas-language-viewport')).toBeNull();
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
