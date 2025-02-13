import type { PropsValues } from '@/types/Form';

interface DrupalSettings {
  xb: {
    base: string;
    entityType: string;
    entity: string;
    global_assets: {
      css: string;
      js_header: string;
      js_footer: string;
    };
    layoutUtils: PropsValues;
    navUtils: PropsValues;
    demo_mode: boolean;
    xbModulePath: string;
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
