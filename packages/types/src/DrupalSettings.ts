import type { FormatType } from './FormatType';
import type { PropsValues } from './PropsValues';

export type Language = {
  id: string;
  name: string;
  direction: 'ltr' | 'rtl';
  isDefault: boolean;
};

export interface PageExtension {
  id: string;
  name: string;
  // The Canvas route that hosts the extension (/canvas/app/{id}).
  url: string;
  // The extension's own entry point, loaded inside the hosting page's iframe.
  extension_url: string;
  icon: string;
}

export interface DrupalSettings {
  canvas: {
    base: string;
    entityType: string;
    entity: string;
    entityTypeKeys: {
      [entityType: string]: {
        id: string;
        label: string;
        [key: string]: string;
      };
    };
    entityTypeLabels: {
      [entityType: string]: string | { [key: string]: string };
    };
    globalAssets: {
      css: string;
      jsHeader: string;
      jsFooter: string;
    };
    viewports?: {
      [viewportId: string]: number | string;
    };
    canvasLayoutRequestInProgress?: boolean[];
    layoutUtils: PropsValues;
    componentSelectionUtils: PropsValues;
    navUtils: PropsValues;
    canvasModulePath: string;
    selectedComponent: string;
    devMode: boolean;
    devTranslationMode: boolean;
    devEntityReferenceMode: boolean;
    // @todo Remove the use of 'canvas_dev_cd' flag in https://git.drupalcode.org/project/canvas/-/work_items/3591732
    devConflictDetectionMode: boolean;
    contentTranslationEnabled: boolean;
    configTranslationEnabled: boolean;
    languages: Language[];
    dialogCss: string[];
    extensionsAvailable: boolean;
    pageExtensions: PageExtension[];
    // ⚠️ This is highly experimental and *will* be refactored.
    aiExtensionAvailable: boolean;
    loginUrl: string;
    // ⚠️ This is highly experimental and *will* be refactored.
    personalizationExtensionAvailable: boolean;
    // ⚠️ This is highly experimental and *will* be refactored.
    canvasAiMaxFileSize: number;
  };
  canvasData: {
    v0: {
      pageTitle: string;
      branding: {
        homeUrl: string;
        siteName: string;
        siteSlogan: string;
      };
      baseUrl: string;
      breadcrumbs: Array<{
        key: string;
        text: string;
        url: string;
      }>;
      mainEntity: null | {
        bundle: string;
        entityTypeId: string;
        uuid: string;
        // The language requested via the URL (the negotiated content language).
        requestedLanguage: string;
        // The language the content actually rendered in. Falls back to the
        // default translation when the requested language has no translation,
        // so it can differ from `requestedLanguage`.
        renderedLanguage: string;
        // Every enabled site language, to support building a language switcher.
        // Includes the default translation and languages the entity is not yet
        // translated into (`translationAvailable: false`); the latter still
        // carry a URL that resolves to the fallback translation. Empty when the
        // entity type does not support translation or the site is monolingual.
        translations: Array<{
          langcode: string;
          // The language name in the current display language (e.g. "German").
          name: string;
          // The language's own native name (e.g. "Deutsch").
          nativeName: string;
          url: string;
          // Whether the entity has a translation in this language that the
          // current user may view.
          translationAvailable: boolean;
          // Whether this is the requested language (the URL/content language),
          // even when its content falls back to the default.
          current: boolean;
        }>;
      };
      jsonapiSettings: null | {
        apiPrefix: string;
      };
      themeAssets: {
        logo: { url: string };
        favicon: { url: string; mimeType: string };
      };
    };
  };
  canvasExtension: object;
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
