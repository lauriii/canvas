import { Provider } from 'react-redux';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { act, renderHook } from '@testing-library/react';

import { makeStore } from '@/app/store';
import { setCurrentRoute } from '@/features/ui/uiSlice';

import useRouteSync from './useRouteSync';

const mockNavigate = vi.fn();

vi.mock('react-router-dom', async () => {
  const originalModule = await vi.importActual('react-router-dom');
  return {
    ...originalModule,
    useNavigate: () => mockNavigate,
  };
});

describe('useRouteSync', () => {
  let store: ReturnType<typeof makeStore>;

  beforeEach(() => {
    store = makeStore();
    mockNavigate.mockClear();
  });

  const createWrapper = (initialEntries: string[] = ['/']) => {
    return ({ children }: { children: React.ReactNode }) => (
      <Provider store={store}>
        <MemoryRouter initialEntries={initialEntries}>{children}</MemoryRouter>
      </Provider>
    );
  };

  describe('React Router → Redux', () => {
    it('should set currentRoute with initial pathname', () => {
      const wrapper = createWrapper(['/initial-route?param=value#section']);
      renderHook(() => useRouteSync(), { wrapper });

      const uiState = store.getState().ui;
      expect(uiState.currentRoute).to.deep.equal({
        pathname: '/initial-route',
        search: '?param=value',
        hash: '#section',
      });
    });

    it('should set currentRoute when location changes', () => {
      const wrapper1 = createWrapper(['/initial-route?param=value#section']);
      const { unmount } = renderHook(() => useRouteSync(), {
        wrapper: wrapper1,
      });
      unmount();

      // Create a new hook with a different route.
      const wrapper2 = createWrapper(['/new-route?param=value2#section2']);
      renderHook(() => useRouteSync(), { wrapper: wrapper2 });

      // Should update to new route.
      expect(store.getState().ui.currentRoute).to.deep.equal({
        pathname: '/new-route',
        search: '?param=value2',
        hash: '#section2',
      });
    });

    it('should only dispatch when pathname actually changes', () => {
      const wrapper = createWrapper(['/same-route']);
      const dispatchSpy = vi.spyOn(store, 'dispatch');
      const initialDispatchCount = dispatchSpy.mock.calls.length;

      const { rerender } = renderHook(() => useRouteSync(), { wrapper });

      // First render should dispatch.
      expect(dispatchSpy.mock.calls.length).toBeGreaterThan(
        initialDispatchCount,
      );

      // Reset the spy.
      dispatchSpy.mockClear();

      // Re-render with same route - should not dispatch again.
      rerender();

      // Should not have dispatched again (useEffect dependency didn't change).
      expect(dispatchSpy).not.toHaveBeenCalled();
    });

    it('should handle empty search and hash in URL', () => {
      const wrapper = createWrapper(['/simple-route']);
      renderHook(() => useRouteSync(), { wrapper });

      const uiState = store.getState().ui;
      expect(uiState.currentRoute.pathname).toBe('/simple-route');
      expect(uiState.currentRoute.search).toBe('');
      expect(uiState.currentRoute.hash).toBe('');
    });
  });

  describe('Redux → React Router', () => {
    it('should navigate when Redux state changes', () => {
      const wrapper = createWrapper(['/start-route']);
      renderHook(() => useRouteSync(), { wrapper });

      // Change Redux state (simulating undo/redo action).
      act(() => {
        store.dispatch(
          setCurrentRoute({
            pathname: '/redux-changed-route',
            search: '?param=value',
            hash: '#section',
          }),
        );
      });

      expect(mockNavigate).toHaveBeenCalledExactlyOnceWith({
        pathname: '/redux-changed-route',
        search: '?param=value',
        hash: '#section',
      });
    });

    it('should not navigate when Redux route matches current location', () => {
      const wrapper = createWrapper(['/same-route?param=value#section']);
      renderHook(() => useRouteSync(), { wrapper });

      // Dispatch the same route that's already current.
      act(() => {
        store.dispatch(
          setCurrentRoute({
            pathname: '/same-route',
            search: '?param=value',
            hash: '#section',
          }),
        );
      });

      expect(mockNavigate).not.toHaveBeenCalled();
    });

    it('should prevent infinite loops', () => {
      const wrapper = createWrapper(['/initial-route?param=value#section']);
      const dispatchSpy = vi.spyOn(store, 'dispatch');
      renderHook(() => useRouteSync(), { wrapper });

      dispatchSpy.mockClear();

      // Simulate Redux state change (like from undo/redo).
      act(() => {
        store.dispatch(
          setCurrentRoute({
            pathname: '/new-route-from-redux',
            search: '?param=value',
            hash: '#section',
          }),
        );
      });

      // Should not cause additional dispatches (no infinite loop).
      // The programmatic navigation flag should prevent this.
      expect(dispatchSpy).toHaveBeenCalledOnce();
    });

    it('should not navigate when Redux route has empty pathname', () => {
      const wrapper = createWrapper(['/current-route']);
      renderHook(() => useRouteSync(), { wrapper });

      // Dispatch empty pathname.
      act(() => {
        store.dispatch(
          setCurrentRoute({
            pathname: '',
            search: '',
            hash: '',
          }),
        );
      });

      expect(mockNavigate).not.toHaveBeenCalled();
    });

    it('should handle navigation with no pathname or hash in URL', () => {
      const wrapper = createWrapper(['/start-route']);
      renderHook(() => useRouteSync(), { wrapper });

      // Change Redux state with only search parameters.
      act(() => {
        store.dispatch(
          setCurrentRoute({
            pathname: '/same-path',
            search: '',
            hash: '',
          }),
        );
      });

      expect(mockNavigate).toHaveBeenCalledExactlyOnceWith({
        pathname: '/same-path',
        search: '',
        hash: '',
      });
    });

    it('should handle initial empty route in Redux', () => {
      const wrapper = createWrapper(['/some-route']);
      renderHook(() => useRouteSync(), { wrapper });

      // Initial Redux state should be updated with current route.
      const uiState = store.getState().ui;
      expect(uiState.currentRoute.pathname).toBe('/some-route');
    });

    it('should handle multiple rapid Redux changes', () => {
      const wrapper = createWrapper(['/start-route']);
      renderHook(() => useRouteSync(), { wrapper });

      // Make multiple rapid changes.
      act(() => {
        store.dispatch(
          setCurrentRoute({
            pathname: '/route-1',
            search: '',
            hash: '',
          }),
        );
        store.dispatch(
          setCurrentRoute({
            pathname: '/route-2',
            search: '',
            hash: '',
          }),
        );
        store.dispatch(
          setCurrentRoute({
            pathname: '/route-3',
            search: '',
            hash: '',
          }),
        );
      });

      // Should navigate to the final route.
      expect(mockNavigate).toHaveBeenCalledExactlyOnceWith({
        pathname: '/route-3',
        search: '',
        hash: '',
      });
    });
  });
});
