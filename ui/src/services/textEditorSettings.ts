import { createApi } from '@reduxjs/toolkit/query/react';

import addAjaxPageState from '@/services/addAjaxPageState';
import { baseQuery } from '@/services/baseQuery';
import processResponseAssets from '@/services/processResponseAssets';

import type { FormatType } from '@drupal-canvas/types';

/**
 * The `settings` member of the text-editor-settings response: the same
 * `drupalSettings.editor.formats` structure the editor module attaches to
 * server-built `text_format` elements, restricted to the formats the current
 * user may use.
 */
export interface TextEditorSettings {
  editor?: {
    formats?: Record<string, FormatType>;
  };
  [key: string]: unknown;
}

/**
 * Fetches CKEditor 5 editor settings and asset libraries for the native
 * formatted text widgets, once per session.
 *
 * The response is in the CanvasTemplateRenderer shape, so
 * `processResponseAssets` loads the editor's asset libraries (deduplicated
 * against scripts already on the page, which also covers assets the escape
 * hatch may have loaded) and merges the settings into `drupalSettings`
 * before the query resolves. When the query reports success, the
 * `window.CKEditor5` globals for the delivered formats are available.
 *
 * @see \Drupal\canvas\Controller\ApiTextEditorSettingsController
 */
export const textEditorSettingsApi = createApi({
  reducerPath: 'textEditorSettingsApi',
  baseQuery,
  endpoints: (builder) => ({
    getTextEditorSettings: builder.query<TextEditorSettings, void>({
      // Session-length retention: RTK Query's default 60-second
      // unused-cache expiry would re-fetch whenever no formatted text
      // widget stays mounted, contradicting the fetch-once-per-session
      // contract. This is RTK's maximum supported timer value (~24 days).
      keepUnusedDataFor: 2147483,
      query: () => ({
        // ajaxPageState tells the server which asset libraries the page
        // already has, so their scripts are not delivered twice.
        url: `canvas/api/v0/text-editor-settings?${addAjaxPageState('')}`,
      }),
      transformResponse: processResponseAssets(['settings']),
    }),
  }),
});

export const { useGetTextEditorSettingsQuery } = textEditorSettingsApi;
