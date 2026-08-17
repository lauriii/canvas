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

/**
 * Options accepted by Drupal.t() and Drupal.formatPlural().
 */
interface DrupalTranslationOptions {
  /**
   * Disambiguates a source string that means different things in different
   * places, so translators can translate each meaning separately.
   */
  context?: string;
}

/**
 * The translation functions Drupal core provides on the `Drupal` global.
 *
 * Call these directly with quoted string literals. Drupal's locale module
 * discovers translatable strings by scanning the built bundle for literal
 * `Drupal.t()` and `Drupal.formatPlural()` calls, so wrapping them in a helper
 * or passing a template literal makes the string untranslatable.
 *
 * @see docs/react-codebase/translation.md
 * @see core/misc/drupal.js
 */
interface DrupalTranslation {
  t: (
    str: string,
    args?: Record<string, string | number>,
    options?: DrupalTranslationOptions,
  ) => string;
  formatPlural: (
    count: number,
    singular: string,
    plural: string,
    args?: Record<string, string | number>,
    options?: DrupalTranslationOptions,
  ) => string;
}

declare global {
  interface Window {
    drupalSettings: DrupalSettings;
    transliterate: TransliterateType;
    React: typeof React;
    ReactDom: typeof ReactDom;
    Redux: typeof ReactRedux;
    ReduxToolkit: typeof ReduxToolkit;
    Drupal: DrupalTranslation & {
      attachBehaviors: (element: HTMLElement, settings?: object) => void;
      detachBehaviors: (element: HTMLElement, settings?: object) => void;
      CKEditor5Instances: Map;
    };
    // Written by the JavaScript translation file Drupal generates for the
    // negotiated interface language, and read by Drupal.t().
    // @see _locale_rebuild_js()
    drupalTranslations?: {
      strings?: Record<string, Record<string, string>>;
      pluralFormula?: Record<string, number> & { default: number };
    };
    CKEditor5: CKEditor5Types;
    jQuery: any;
    _canvasTransforms: Record<string, TransformConfig>;
  }
}

// The bare `Drupal` global, used as `Drupal.t('…')` rather than
// `window.Drupal.t('…')` to match how the rest of Drupal's JavaScript is
// written, is declared separately so packages that type-check shared files from
// ui/src can include it without also pulling in the imports above.
// @see ui/src/types/drupal-translation.d.ts

declare module '*.css';
