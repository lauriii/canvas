import { createContext, useContext } from 'react';

import type { ReactNode } from 'react';

type RegionsMap = Record<string, ReactNode>;

const RegionsContext = createContext<RegionsMap>({});

type RegionsProviderProps = {
  regions: RegionsMap;
  children: ReactNode;
};

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

export function Region({ name, fallback = null }: RegionProps) {
  const regions = useContext(RegionsContext);
  const node = regions[name];
  return <>{node ?? fallback}</>;
}
