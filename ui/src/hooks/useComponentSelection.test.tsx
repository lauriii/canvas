import { Provider } from 'react-redux';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { act, renderHook } from '@testing-library/react';

import { makeStore } from '@/app/store';

import useComponentSelection from './useComponentSelection';

import type React from 'react';

const mockNavigate = vi.fn();

vi.mock('react-router-dom', async () => {
  const originalModule = await vi.importActual('react-router-dom');
  return {
    ...originalModule,
    useNavigate: () => mockNavigate,
  };
});

describe('useComponentSelection', () => {
  let store: ReturnType<typeof makeStore>;

  beforeEach(() => {
    store = makeStore();
    mockNavigate.mockClear();
  });

  const createWrapper =
    (pathname: string) =>
    ({ children }: { children: React.ReactNode }) => (
      <Provider store={store}>
        <MemoryRouter initialEntries={[pathname]}>{children}</MemoryRouter>
      </Provider>
    );

  it('writes the selection to the URL on the entity editor route', () => {
    const { result } = renderHook(() => useComponentSelection(), {
      wrapper: createWrapper('/editor/canvas_page/1'),
    });

    act(() => {
      result.current.setSelectedComponent('a-component-uuid');
    });

    expect(mockNavigate).toHaveBeenCalledWith({
      pathname: '/editor/canvas_page/1/component/a-component-uuid',
      search: '',
      hash: '',
    });
  });

  it('removes the selection from the URL on the entity editor route', () => {
    const { result } = renderHook(() => useComponentSelection(), {
      wrapper: createWrapper(
        '/editor/canvas_page/1/component/a-component-uuid',
      ),
    });

    act(() => {
      result.current.unsetSelectedComponent();
    });

    expect(mockNavigate).toHaveBeenCalledWith({
      pathname: '/editor/canvas_page/1',
      search: '',
      hash: '',
    });
  });

  it('leaves the code editor URL alone when the selection changes', () => {
    // /code-editor/component/:codeComponentId identifies a code component, not
    // a component instance, so rewriting it would open the wrong component or
    // land on a route that does not exist.
    // @see https://www.drupal.org/i/3536807
    const { result } = renderHook(() => useComponentSelection(), {
      wrapper: createWrapper('/code-editor/component/my_code'),
    });

    act(() => {
      result.current.setSelectedComponent('a-component-uuid');
    });

    expect(mockNavigate).not.toHaveBeenCalled();
    // The selection is still recorded; only the URL is left alone.
    expect(store.getState().ui.selection.items).toEqual(['a-component-uuid']);

    act(() => {
      result.current.unsetSelectedComponent();
    });

    expect(mockNavigate).not.toHaveBeenCalled();
    expect(store.getState().ui.selection.items).toEqual([]);
  });

  it('leaves a template route without a preview entity alone', () => {
    // /template/:entityType/:bundle/:viewMode stops short of an entity, so it
    // has no component tree and no route to append a selection to.
    const { result } = renderHook(() => useComponentSelection(), {
      wrapper: createWrapper('/template/node/article/full'),
    });

    act(() => {
      result.current.setSelectedComponent('a-component-uuid');
    });

    expect(mockNavigate).not.toHaveBeenCalled();
  });
});
