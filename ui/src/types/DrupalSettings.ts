import type { PropsValues } from '@/types/Form';
import type { FormatType } from '@/types/FormatType';

export interface DrupalSettings {
  xb: {
    base: string;
    entityType: string;
    entity: string;
    entityTypeKeys: {
      id: string;
      label: string;
    };
    globalAssets: {
      css: string;
      jsHeader: string;
      jsFooter: string;
    };
    layoutUtils: PropsValues;
    componentSelectionUtils: PropsValues;
    navUtils: PropsValues;
    xbModulePath: string;
    selectedComponent: string;
    devMode: boolean;
  };
  xbExtension: object;
  path: {
    baseUrl: string;
  };
  editor: {
    formats: {
      [key: string]: FormatType;
    };
  };
  ajaxPageState: {
    libraries: string;
    theme: string;
    theme_token: string;
  };
  langcode: string;
  transliteration_language_overrides: {
    [key: string]: {
      [key: string]: string;
    };
  };
}
