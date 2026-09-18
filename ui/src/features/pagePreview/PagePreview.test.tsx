import { MemoryRouter, Route, Routes } from 'react-router-dom';
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
  useGetPageLayoutQuery: vi.fn(),
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
