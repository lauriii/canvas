import { createContext, useContext } from 'react';

import type { ReactNode } from 'react';

type RegionsMap = Record<string, ReactNode>;

const RegionsContext = createContext<RegionsMap>({});

type RegionsProviderProps = {
  regions: RegionsMap;
  children: ReactNode;
};

/**
 * Supplies the region node map read by `Region`.
 *
 * @deprecated Theme-global regions were replaced by page variants (ADR 19);
 *   compose pages with the component tree instead. Kept for compatibility.
 */
export function RegionsProvider({ regions, children }: RegionsProviderProps) {
  return (
    <RegionsContext.Provider value={regions}>
      {children}
    </RegionsContext.Provider>
  );
}

type RegionProps = {
  name: string;
  fallback?: ReactNode;
};

/**
 * Renders the region whose machine name matches `name`.
 *
 * @deprecated Theme-global regions were replaced by page variants (ADR 19);
 *   compose pages with the component tree instead. Kept for compatibility.
 */
export function Region({ name, fallback = null }: RegionProps) {
  const regions = useContext(RegionsContext);
  const node = regions[name];
  return <>{node ?? fallback}</>;
}
