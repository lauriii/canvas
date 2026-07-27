import { afterEach, describe, expect, it, vi } from 'vitest';
import { Theme } from '@radix-ui/themes';
import { render, screen } from '@testing-library/react';

import Library from '@/components/sidePanel/Library';
import { getCanvasSettings } from '@/utils/drupal-globals';

vi.mock('@/components/list/ComponentList', () => ({
  default: ({ visibility }: { visibility: string }) => (
    <div data-testid="component-list">{visibility}</div>
  ),
}));

vi.mock('@/components/list/PatternList', () => ({
  default: () => <div>Pattern list</div>,
}));

vi.mock('@/components/sidePanel/LibraryToolbar', () => ({
  default: ({ showNewMenu }: { showNewMenu?: boolean }) => (
    <div>{showNewMenu ? 'New menu available' : 'New menu unavailable'}</div>
  ),
}));

vi.mock('@/hooks/useDebounce', () => ({
  default: (value: string) => value,
}));

const renderLibrary = () =>
  render(
    <Theme>
      <Library />
    </Theme>,
  );

describe('Library', () => {
  afterEach(() => {
    delete getCanvasSettings().canAccessHeadlessPreview;
    delete getCanvasSettings().canAdministerHeadlessFrontends;
    delete getCanvasSettings().headless;
  });

  it('shows only standard components without headless preview access', () => {
    renderLibrary();

    expect(screen.getByTestId('component-list')).toHaveTextContent(
      'non-external-only',
    );
    expect(
      screen.getByTestId('canvas-library-patterns-tab-select'),
    ).toBeInTheDocument();
    expect(screen.getByText('New menu available')).toBeInTheDocument();
  });

  // @see docs/ui-element-ids.md
  it('identifies both library tabs', () => {
    const { container } = renderLibrary();

    const keys = Array.from(
      container.querySelectorAll('[data-canvas-element="library-tab"]'),
    ).map((element) => element.getAttribute('data-canvas-element-key'));

    expect(keys).toEqual(['components', 'patterns']);
  });

  it('ignores configured headless settings without headless preview access', () => {
    getCanvasSettings().headless = {
      frontendUrl: 'https://frontend.example',
      frontends: ['https://frontend.example'],
      frontendOrigin: 'https://frontend.example',
      draftUrl: 'https://frontend.example/api/draft',
      assertionUrl: '/canvas-headless/assertion',
    };

    renderLibrary();

    expect(screen.getByTestId('component-list')).toHaveTextContent(
      'non-external-only',
    );
    expect(
      screen.getByTestId('canvas-library-patterns-tab-select'),
    ).toBeInTheDocument();
    expect(screen.getByText('New menu available')).toBeInTheDocument();
  });

  it('shows standard and converted components when no frontend is configured', () => {
    getCanvasSettings().canAccessHeadlessPreview = true;

    renderLibrary();

    expect(screen.getByTestId('component-list')).toHaveTextContent(
      'non-external-and-fallback-external',
    );
    expect(
      screen.getByTestId('canvas-library-patterns-tab-select'),
    ).toBeInTheDocument();
    expect(screen.getByText('New menu available')).toBeInTheDocument();
  });

  it('only exposes external components when a headless frontend is configured', () => {
    getCanvasSettings().canAccessHeadlessPreview = true;
    getCanvasSettings().headless = {
      frontendUrl: 'https://frontend.example',
      frontends: ['https://frontend.example'],
      frontendOrigin: 'https://frontend.example',
      draftUrl: 'https://frontend.example/api/draft',
      assertionUrl: '/canvas-headless/assertion',
    };

    renderLibrary();

    expect(screen.getByTestId('component-list')).toHaveTextContent(
      'external-only',
    );
    expect(
      screen.queryByTestId('canvas-library-patterns-tab-select'),
    ).not.toBeInTheDocument();
    expect(screen.getByText('New menu unavailable')).toBeInTheDocument();
  });
});
