import type React from 'react';
import type ReactDom from 'react-dom';
// eslint-disable-next-line @typescript-eslint/no-restricted-imports
import type * as ReactRedux from 'react-redux';
import type { DrupalSettings } from '@drupal-canvas/types';
import type * as ReduxToolkit from '@reduxjs/toolkit';
import type { transliterate as TransliterateType } from '@/types/transliterate';
import type { TransformConfig } from '@/utils/transforms';

interface CKEditor5Types {
  editorClassic: {
    ClassicEditor: any;
  };
  [key: string]: any;
}

declare global {
  interface Window {
    drupalSettings: DrupalSettings;
    transliterate: TransliterateType;
    React: typeof React;
    ReactDom: typeof ReactDom;
    Redux: typeof ReactRedux;
    ReduxToolkit: typeof ReduxToolkit;
    Drupal: {
      attachBehaviors: (element: HTMLElement) => void;
      CKEditor5Instances: Map;
    };
    CKEditor5: CKEditor5Types;
    jQuery: any;
    _canvasTransforms: Record<string, TransformConfig>;
  }
}

declare module '*.css';
