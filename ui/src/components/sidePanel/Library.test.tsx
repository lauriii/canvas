import { afterEach, describe, expect, it, vi } from 'vitest';
import { Theme } from '@radix-ui/themes';
import { render, screen } from '@testing-library/react';

import Library from '@/components/sidePanel/Library';
import { getCanvasSettings } from '@/utils/drupal-globals';

vi.mock('@/components/list/ComponentList', () => ({
  default: ({
    externalComponentsOnly,
  }: {
    externalComponentsOnly: boolean;
  }) => (
    <div data-testid="component-list">
      {externalComponentsOnly ? 'External only' : 'All components'}
    </div>
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
    delete getCanvasSettings().headlessEnabled;
    delete getCanvasSettings().headless;
  });

  it('shows every component source when headless has no configured frontend', () => {
    getCanvasSettings().headlessEnabled = true;

    renderLibrary();

    expect(screen.getByTestId('component-list')).toHaveTextContent(
      'All components',
    );
    expect(
      screen.getByTestId('canvas-library-patterns-tab-select'),
    ).toBeInTheDocument();
    expect(screen.getByText('New menu available')).toBeInTheDocument();
  });

  it('only exposes external components when a headless frontend is configured', () => {
    getCanvasSettings().headlessEnabled = true;
    getCanvasSettings().headless = {
      frontendUrl: 'https://frontend.example',
      frontends: ['https://frontend.example'],
      frontendOrigin: 'https://frontend.example',
      draftUrl: 'https://frontend.example/api/draft',
      assertionUrl: '/canvas-headless/assertion',
    };

    renderLibrary();

    expect(screen.getByTestId('component-list')).toHaveTextContent(
      'External only',
    );
    expect(
      screen.queryByTestId('canvas-library-patterns-tab-select'),
    ).not.toBeInTheDocument();
    expect(screen.getByText('New menu unavailable')).toBeInTheDocument();
  });
});
