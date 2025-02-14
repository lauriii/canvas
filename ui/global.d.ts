import type { PropsValues } from '@/types/Form';

interface DrupalSettings {
  xb: {
    base: string;
    entityType: string;
    entity: string;
    globalAssets: {
      css: string;
      jsHeader: string;
      jsFooter: string;
    };
    layoutUtils: PropsValues;
    navUtils: PropsValues;
    xbModulePath: string;
    selectedComponent: string;
    demoMode: boolean;
  };
  path: {
    baseUrl: string;
  };
}

declare global {
  interface Window {
    drupalSettings: DrupalSettings;
  }
}
