import { Provider } from 'react-redux';
import { MemoryRouter, useLocation, useNavigate } from 'react-router';
import { describe, expect, it } from 'vitest';
import { act, render, screen } from '@testing-library/react';

import { makeStore } from '@/app/store';
import {
  StableComponentSelectionProvider,
  useStableComponentSelection,
} from '@/hooks/useStableComponentSelection';

describe('useStableComponentSelection', () => {
  it('keeps the handlers and the consumer stable across navigation, and selects on the current route', () => {
    const store = makeStore();
    const seen: ReturnType<typeof useStableComponentSelection>[] = [];
    let navigate: ReturnType<typeof useNavigate>;

    const Consumer = () => {
      seen.push(useStableComponentSelection());
      return null;
    };
    const Location = () => {
      navigate = useNavigate();
      return <div data-testid="path">{useLocation().pathname}</div>;
    };

    render(
      <Provider store={store}>
        <MemoryRouter initialEntries={['/editor/canvas_page/1']}>
          <StableComponentSelectionProvider>
            <Consumer />
            <Location />
          </StableComponentSelectionProvider>
        </MemoryRouter>
      </Provider>,
    );

    act(() => seen[0].setSelectedComponent('uuid-a'));
    expect(screen.getByTestId('path')).toHaveTextContent(
      '/editor/canvas_page/1/component/uuid-a',
    );

    act(() => navigate('/editor/canvas_page/2'));
    act(() => seen[0].handleComponentSelection('uuid-b', false));
    expect(screen.getByTestId('path')).toHaveTextContent(
      '/editor/canvas_page/2/component/uuid-b',
    );
    expect(store.getState().ui.selection.items).toEqual(['uuid-b']);

    // The consumer rendered once: neither navigation nor selection re-rendered it.
    expect(seen).toHaveLength(1);
  });
});
