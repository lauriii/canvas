import { MemoryRouter } from 'react-router';
import { describe, expect, it, vi } from 'vitest';
import { Theme } from '@radix-ui/themes';
import { act, fireEvent, screen, waitFor } from '@testing-library/react';

import { NodeType } from '@/features/layout/layoutModelSlice';
import ComponentContextMenu from '@/features/layout/preview/ComponentContextMenu';
import {
  editorViewPortZoomIn,
  setEditorFrameViewPort,
} from '@/features/ui/uiSlice';
import { renderWithProviders } from '@/utils/test-utils';

import type { ComponentNode } from '@/features/layout/layoutModelSlice';

vi.mock('@/services/componentAndLayout', async () => {
  const actual = await vi.importActual('@/services/componentAndLayout');
  return {
    ...actual,
    useGetComponentsQuery: () => ({ data: {} }),
  };
});

const INSTANCE_COUNT = 5;

const componentNode = (index: number): ComponentNode => ({
  nodeType: NodeType.Component,
  uuid: `component-${index}`,
  type: 'sdc.example.card@0123456789abcdef',
  slots: [],
});

const renderMenus = () =>
  renderWithProviders(
    <MemoryRouter>
      <Theme>
        {Array.from({ length: INSTANCE_COUNT }, (_, index) => (
          <ComponentContextMenu key={index} component={componentNode(index)}>
            <div>Instance {index}</div>
          </ComponentContextMenu>
        ))}
      </Theme>
    </MemoryRouter>,
  );

/**
 * Returns a getter for the `keydown` events dispatched on `document` from now.
 */
const spyOnKeydownDispatches = () => {
  const spy = vi.spyOn(document, 'dispatchEvent');
  return () => spy.mock.calls.filter(([event]) => event.type === 'keydown');
};

describe('ComponentContextMenu', () => {
  it('does not dispatch keydown events from closed menus on mount or zoom', () => {
    const keydownDispatches = spyOnKeydownDispatches();
    const { store } = renderMenus();

    act(() => {
      store.dispatch(editorViewPortZoomIn());
    });

    expect(screen.queryByRole('menu')).not.toBeInTheDocument();
    expect(keydownDispatches()).toHaveLength(0);
  });

  it('closes the open menu when the user zooms', async () => {
    const { store } = renderMenus();

    fireEvent.contextMenu(screen.getByText('Instance 2'));
    expect(await screen.findByRole('menu')).toBeInTheDocument();

    act(() => {
      store.dispatch(editorViewPortZoomIn());
    });

    await waitFor(() => {
      expect(screen.queryByRole('menu')).not.toBeInTheDocument();
    });
  });

  it('keeps the open menu open when the viewport pans without zooming', async () => {
    const keydownDispatches = spyOnKeydownDispatches();
    const { store } = renderMenus();

    fireEvent.contextMenu(screen.getByText('Instance 2'));
    expect(await screen.findByRole('menu')).toBeInTheDocument();

    act(() => {
      store.dispatch(setEditorFrameViewPort({ x: 10, y: 20 }));
    });

    expect(screen.getByRole('menu')).toBeInTheDocument();
    expect(keydownDispatches()).toHaveLength(0);
  });
});
