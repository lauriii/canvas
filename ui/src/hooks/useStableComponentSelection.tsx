import {
  createContext,
  useContext,
  useLayoutEffect,
  useMemo,
  useRef,
} from 'react';

import useComponentSelection from '@/hooks/useComponentSelection';

import type { ReactNode } from 'react';

type StableComponentSelection = Pick<
  ReturnType<typeof useComponentSelection>,
  'handleComponentSelection' | 'setSelectedComponent'
>;

const StableComponentSelectionContext =
  createContext<StableComponentSelection | null>(null);

/**
 * Provides component selection handlers that never change identity.
 *
 * useComponentSelection() reads the router location and subscribes to the
 * layout, the selection, the selected component and dev mode in the store, so
 * each component that calls it re-renders on every navigation, selection
 * change and layout change.
 * Components rendered once per component instance or per library component
 * (Layers rows, preview overlays, Library items) use
 * useStableComponentSelection() instead. The provider calls
 * useComponentSelection() once, and the handlers call its latest result.
 */
export const StableComponentSelectionProvider = ({
  children,
}: {
  children: ReactNode;
}) => {
  const utils = useComponentSelection();
  const latest = useRef(utils);
  useLayoutEffect(() => {
    latest.current = utils;
  });
  const handlers = useMemo<StableComponentSelection>(
    () => ({
      handleComponentSelection: (...args) =>
        latest.current.handleComponentSelection(...args),
      setSelectedComponent: (...args) =>
        latest.current.setSelectedComponent(...args),
    }),
    [],
  );
  return (
    <StableComponentSelectionContext.Provider value={handlers}>
      {children}
    </StableComponentSelectionContext.Provider>
  );
};

export const useStableComponentSelection = (): StableComponentSelection => {
  const handlers = useContext(StableComponentSelectionContext);
  if (!handlers) {
    throw new Error(
      'useStableComponentSelection() must be used within a StableComponentSelectionProvider.',
    );
  }
  return handlers;
};
