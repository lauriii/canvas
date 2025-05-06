import type { PropsValues } from '@/types/Form';
import type React from 'react';
import type ReactDom from 'react-dom';
// eslint-disable-next-line @typescript-eslint/no-restricted-imports
import type * as ReactRedux from 'react-redux';
import type * as ReduxToolkit from '@reduxjs/toolkit';

interface DrupalSettings {
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
}

declare global {
  interface Window {
    drupalSettings: DrupalSettings;
    React: typeof React;
    ReactDom: typeof ReactDom;
    Redux: typeof ReactRedux;
    ReduxToolkit: typeof ReduxToolkit;
    Drupal: {
      attachBehaviors: (element: HTMLElement) => void;
      CKEditor5Instances: Map;
    };
  }
}
