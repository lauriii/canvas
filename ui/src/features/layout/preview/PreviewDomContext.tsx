import {
  createContext,
  useCallback,
  useContext,
  useMemo,
  useState,
} from 'react';

import type { ReactNode } from 'react';

export interface PreviewDomMaps {
  componentsMap: Record<string, { elements: HTMLElement[] }>;
}

interface PreviewDomProviderProps {
  children: ReactNode;
}

interface PreviewDomUpdater {
  updateDomMaps: (maps: PreviewDomMaps) => void;
  clearDomMaps: () => void;
}

const ValueContext = createContext<PreviewDomMaps | null>(null);
const UpdateContext = createContext<PreviewDomUpdater | null>(null);

function emptyDomMaps(): PreviewDomMaps {
  return { componentsMap: {} };
}

/** Returns same-origin preview DOM maps, or null for a headless preview. */
export const usePreviewDom = (): PreviewDomMaps | null =>
  useContext(ValueContext);

export const usePreviewDomUpdater = (): PreviewDomUpdater => {
  const context = useContext(UpdateContext);
  if (context === null) {
    throw new Error('usePreviewDomUpdater must be used within a Provider');
  }
  return context;
};

export const PreviewDomProvider: React.FC<PreviewDomProviderProps> = ({
  children,
}) => {
  const [maps, setMaps] = useState<PreviewDomMaps>(emptyDomMaps);
  const updateDomMaps = useCallback((next: PreviewDomMaps) => {
    setMaps((previous) => (domMapsEqual(previous, next) ? previous : next));
  }, []);
  const clearDomMaps = useCallback(() => setMaps(emptyDomMaps()), []);
  const updater = useMemo(
    () => ({ updateDomMaps, clearDomMaps }),
    [clearDomMaps, updateDomMaps],
  );

  return (
    <ValueContext.Provider value={maps}>
      <UpdateContext.Provider value={updater}>
        {children}
      </UpdateContext.Provider>
    </ValueContext.Provider>
  );
};

function domMapsEqual(first: PreviewDomMaps, second: PreviewDomMaps): boolean {
  const firstComponentIds = Object.keys(first.componentsMap);
  const secondComponentIds = Object.keys(second.componentsMap);
  if (firstComponentIds.length !== secondComponentIds.length) {
    return false;
  }
  for (const id of firstComponentIds) {
    const firstElements = first.componentsMap[id]?.elements;
    const secondElements = second.componentsMap[id]?.elements;
    if (
      !firstElements ||
      !secondElements ||
      firstElements.length !== secondElements.length ||
      firstElements.some((element, index) => element !== secondElements[index])
    ) {
      return false;
    }
  }

  return true;
}
