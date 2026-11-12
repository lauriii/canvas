import { MemoryRouter, Route, Routes } from 'react-router';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { render } from '@testing-library/react';

import TranslationRouteGuard from './TranslationRouteGuard';

const mocks = vi.hoisted(() => ({
  navigate: vi.fn(),
  forked: [] as string[],
  editedLayout: undefined as object | undefined,
}));
vi.mock('@/hooks/useWorkingLanguage', () => ({
  useWorkingLanguage: () => ({
    hasOwnLayout: (langcode: string) => mocks.forked.includes(langcode),
  }),
  useNavigateToTranslation: () => mocks.navigate,
}));
vi.mock('@/services/componentAndLayout', () => ({
  useGetPageLayoutQuery: () => ({ currentData: mocks.editedLayout }),
}));

const renderAt = (url: string) =>
  render(
    <MemoryRouter initialEntries={[url]}>
      <Routes>
        <Route
          path="/editor/:entityType/:entityId/*"
          element={<TranslationRouteGuard />}
        />
      </Routes>
    </MemoryRouter>,
  );

describe('TranslationRouteGuard', () => {
  beforeEach(() => {
    mocks.navigate.mockReset();
    mocks.forked = [];
    mocks.editedLayout = undefined;
  });

  it('edits a language shown that has a custom layout', () => {
    mocks.forked = ['de'];
    renderAt('/editor/canvas_page/8/component/abc?language=de&a=b');
    // The rest of the address is kept.
    expect(mocks.navigate).toHaveBeenCalledWith(
      '/editor/canvas_page/8/component/abc?a=b&translation=de',
      { replace: true },
    );
  });

  it('leaves a language shown without a custom layout', () => {
    renderAt('/editor/canvas_page/8?language=de');
    expect(mocks.navigate).not.toHaveBeenCalled();
  });

  it('shows an edited language that no longer has a custom layout', () => {
    mocks.editedLayout = {
      translations: { available: ['en', 'de'], forked: [] },
    };
    renderAt('/editor/canvas_page/8?translation=de');
    expect(mocks.navigate).toHaveBeenCalledWith(
      '/editor/canvas_page/8?language=de',
      { replace: true },
    );
  });

  it('edits the original without naming its language', () => {
    mocks.editedLayout = {
      translations: { available: ['en', 'de'], forked: ['de'] },
    };
    renderAt('/editor/canvas_page/8?translation=en');
    expect(mocks.navigate).toHaveBeenCalledWith('/editor/canvas_page/8', {
      replace: true,
    });
  });

  it('waits for the state of an edited language', () => {
    renderAt('/editor/canvas_page/8?translation=de');
    expect(mocks.navigate).not.toHaveBeenCalled();
    mocks.editedLayout = {
      translations: { available: ['en', 'de'], forked: ['de'] },
    };
    renderAt('/editor/canvas_page/8?translation=de');
    expect(mocks.navigate).not.toHaveBeenCalled();
  });
});
