import {
  createContext,
  useCallback,
  useContext,
  useMemo,
  useState,
} from 'react';
import isEqual from 'lodash/isEqual';

import type { ReactNode } from 'react';
import type {
  CanvasBoundaryType,
  CanvasGeometry,
} from '@drupal-canvas/preview-geometry';

export type CanvasGeometryMap = Record<
  CanvasBoundaryType,
  Record<string, CanvasGeometry>
>;

interface PreviewGeometryContextValue {
  geometryMap: CanvasGeometryMap;
}

interface PreviewGeometryContextUpdater {
  updateGeometry: (geometry: CanvasGeometry[]) => void;
  clearGeometry: () => void;
}

const ValueContext = createContext<PreviewGeometryContextValue | null>(null);
const UpdateContext = createContext<PreviewGeometryContextUpdater | null>(null);

function emptyGeometryMap(): CanvasGeometryMap {
  return { component: {}, slot: {}, region: {} };
}

export function indexCanvasGeometry(
  geometry: CanvasGeometry[],
): CanvasGeometryMap {
  const map = emptyGeometryMap();
  geometry.forEach((item) => {
    map[item.type][item.id] = item;
  });
  return map;
}

export const usePreviewGeometry = (): PreviewGeometryContextValue => {
  const context = useContext(ValueContext);
  if (context === null) {
    throw new Error('usePreviewGeometry must be used within a Provider');
  }
  return context;
};

export const usePreviewGeometryUpdater = (): PreviewGeometryContextUpdater => {
  const context = useContext(UpdateContext);
  if (context === null) {
    throw new Error('usePreviewGeometryUpdater must be used within a Provider');
  }
  return context;
};

export const PreviewGeometryProvider: React.FC<{ children: ReactNode }> = ({
  children,
}) => {
  const [geometryMap, setGeometryMap] =
    useState<CanvasGeometryMap>(emptyGeometryMap);
  const updateGeometry = useCallback((geometry: CanvasGeometry[]) => {
    const next = indexCanvasGeometry(geometry);
    setGeometryMap((previous) => (isEqual(previous, next) ? previous : next));
  }, []);
  const clearGeometry = useCallback(
    () => setGeometryMap(emptyGeometryMap()),
    [],
  );
  const value = useMemo(() => ({ geometryMap }), [geometryMap]);
  const updater = useMemo(
    () => ({ updateGeometry, clearGeometry }),
    [clearGeometry, updateGeometry],
  );

  return (
    <ValueContext.Provider value={value}>
      <UpdateContext.Provider value={updater}>
        {children}
      </UpdateContext.Provider>
    </ValueContext.Provider>
  );
};
