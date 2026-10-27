import { defaultSerializeQueryArgs } from '@reduxjs/toolkit/query';
import { fetchBaseQuery } from '@reduxjs/toolkit/query/react';

import { fetchCsrfToken } from '@/utils/csrf';
import { getCanvasSettings } from '@/utils/drupal-globals';

import type {
  BaseQueryApi,
  BaseQueryFn,
  FetchArgs,
  FetchBaseQueryError,
  SerializeQueryArgs,
} from '@reduxjs/toolkit/query/react';
import type { RootState } from '@/app/store';
import type { AppConfiguration } from '@/features/configuration/configurationSlice';

export const baseQuery: BaseQueryFn<
  string | FetchArgs,
  unknown,
  FetchBaseQueryError
> = async (args, api, extraOptions) => {
  const state = api.getState() as RootState;
  return rawBaseQuery(state.configuration)(args, api, extraOptions);
};

export const pushCanvasLayoutRequest = () => {
  // Some requests that update components or their form should not occur at the
  // same time as a Drupal AJAX request. Those requests are identified here,
  // then a variable in canvasSettings is used to track such a request being in
  // progress. AJAX events will wait until these requests are complete.
  // @see the override of Drupal.Ajax.prototype.eventResponse in
  //   ajax.command.customizations.js
  const canvasSettings = getCanvasSettings();
  if (typeof canvasSettings?.canvasLayoutRequestInProgress === 'undefined') {
    canvasSettings.canvasLayoutRequestInProgress = [];
  }

  // Set body attribute to allow CSS to block use of elements that may cause
  // conflicts during layout / model / entity updating requests.
  document.body.setAttribute('data-canvas-layout-request-in-progress', 'true');
  canvasSettings.canvasLayoutRequestInProgress.push(true);
};

export const popCanvasLayoutRequest = () => {
  const canvasSettings = getCanvasSettings();
  canvasSettings.canvasLayoutRequestInProgress.pop();
  if (canvasSettings.canvasLayoutRequestInProgress.length === 0) {
    // Notify the application that the layout request has completed.
    const event = new CustomEvent('canvasLayoutRequestComplete');
    document.dispatchEvent(event);
    setTimeout(() => {
      if (canvasSettings.canvasLayoutRequestInProgress.length === 0) {
        document.body.removeAttribute('data-canvas-layout-request-in-progress');
      }
    }, 10);
  }
};

/**
 * Extracts entityType and entityId from a URL
 * @param url - The URL string to parse.
 * @returns An object with entityType and entityId, or undefined values if not found.
 */
export const extractEntityParams = (url: string) => {
  // Remove query parameters and hash fragments
  url = url.split('?')[0].split('#')[0];
  // Match /canvas/(editor||preview)/:entityType/:entityId/
  const matchPageEditor = url.match(
    /\/canvas\/(editor|preview)\/([^/]+)\/([^/]+)\/?/,
  );
  // /template/:entityType/:bundle/:viewMode/:previewEntityId/
  // /canvas/api/v0/layout-content-template/{entity_type}.{template_bundle}.{template_view_mode}/{entity_id}
  const matchTemplateEditor = url.match(
    /\/canvas\/template\/([^/]+)\/([^/]+)\/([^/]+)\/([^/]+)\/?/,
  );
  // /pattern/:patternId/
  // /canvas/api/v0/layout-pattern/{entity_id}
  const matchPatternEditor = url.match(/\/canvas\/pattern\/([^/]+)\/?/);
  if (matchPageEditor) {
    return { entityType: matchPageEditor[2], entityId: matchPageEditor[3] };
  } else if (matchTemplateEditor) {
    return {
      entityType: matchTemplateEditor[1],
      templateBundle: matchTemplateEditor[2],
      templateViewMode: matchTemplateEditor[3],
      entityId: matchTemplateEditor[4],
    };
  } else if (matchPatternEditor) {
    return { entityType: 'pattern', entityId: matchPatternEditor[1] };
  }
  return { entityType: undefined, entityId: undefined };
};

/**
 * Replaces {entity_type} and {entity_id} in a URL string with extracted values.
 * Throws an error if a required value is missing.
 */
export const replaceEntityParamsInUrl = (
  url: string,
  entityType?: string,
  entityId?: string,
  templateBundle?: string,
  templateViewMode?: string,
): string => {
  let newUrl = url;
  if (url.includes('{entity_type}')) {
    if (entityType !== undefined) {
      newUrl = newUrl.replace('{entity_type}', entityType);
    } else {
      throw new Error(
        `The URL "${url}" requires an Entity type, but it could not be extracted from the current location.`,
      );
    }
  }
  if (url.includes('{entity_id}')) {
    if (entityId !== undefined) {
      newUrl = newUrl.replace('{entity_id}', entityId);
    } else {
      throw new Error(
        `The URL "${url}" requires an Entity ID, but it could not be extracted from the current location.`,
      );
    }
  }
  if (url.includes('{template_bundle}')) {
    if (templateBundle !== undefined) {
      newUrl = newUrl.replace('{template_bundle}', templateBundle);
    } else {
      throw new Error(
        `The URL "${url}" requires a Template Bundle, but it could not be extracted from the current location.`,
      );
    }
  }
  if (url.includes('{template_view_mode}')) {
    if (templateViewMode !== undefined) {
      newUrl = newUrl.replace('{template_view_mode}', templateViewMode);
    } else {
      throw new Error(
        `The URL "${url}" requires a Template View Mode, but it could not be extracted from the current location.`,
      );
    }
  }
  return newUrl;
};

/**
 * The language of the forked translation open in the entity editor, if any.
 *
 * Forked translations own their layout, so the entity editor edits them like
 * the default translation, with the language in the `?language=` query of the
 * editor route. Symmetric translations are only previewed, on the preview
 * route, so they never yield an editor language.
 */
export const getEditorLanguage = (href: string): string => {
  const url = new URL(href, 'http://localhost');
  return /\/canvas\/(?:editor|template)\//.test(url.pathname)
    ? (url.searchParams.get('language') ?? '')
    : '';
};

// The entity-scoped editor API paths whose route entity the backend swaps for
// the translation named by `canvas_langcode`. Requests on behalf of a listed
// pending change (discard, content auto-save) name its translation themselves.
// @see \Drupal\canvas\EventSubscriber\ForkedTranslationEditRequestSubscriber::ROUTE_ENTITY_PARAMETERS
const EDITOR_ENTITY_API_PATH =
  /canvas\/api\/v0\/(?:layout|form\/component-instance|form\/content-entity)\/([^/?]+)\/([^/?]+)(?:[/?]|$)/;
// The content template editor API paths: the template ID, then the preview
// entity ID.
const TEMPLATE_EDITOR_API_PATH =
  /canvas\/api\/v0\/(?:layout-content-template|form\/component-instance\/content_template)\/([^/?]+)\/([^/?]+)(?:[/?]|$)/;

/**
 * Names a forked translation on editor API requests for its entity.
 */
export const addEditorLanguageToUrl = (
  url: string,
  language: string | undefined,
  entityType: string | undefined,
  entityId: string | undefined,
  // On the content template editor: the template's bundle and view mode.
  template?: { bundle: string; viewMode: string },
): string => {
  // Previews of a translation name it with their own hint, and are allowed for
  // every translation, forked or not. A URL that already names a translation
  // keeps it.
  if (
    !language ||
    url.includes('canvas_preview_langcode=') ||
    url.includes('canvas_langcode=')
  ) {
    return url;
  }
  const match = template
    ? url.match(TEMPLATE_EDITOR_API_PATH)
    : url.match(EDITOR_ENTITY_API_PATH);
  const expected = template
    ? [`${entityType}.${template.bundle}.${template.viewMode}`, entityId]
    : [entityType, entityId];
  if (!match || match[1] !== expected[0] || match[2] !== expected[1]) {
    return url;
  }
  return `${url}${url.includes('?') ? '&' : '?'}canvas_langcode=${encodeURIComponent(language)}`;
};

/**
 * The translation an editor API request acts on.
 *
 * Reads follow the editor route, which decides what to load. Writes follow the
 * translation the editor's data was loaded for, so a write that races a
 * language switch can never send one translation's data to another. Without
 * loaded data (e.g. after a failed refetch), writes follow the route too, so
 * they never fall back to the default translation while a fork is open.
 */
export const getRequestTranslation = (
  api: BaseQueryApi,
): {
  language?: string;
  entityType?: string;
  entityId?: string;
  template?: { bundle: string; viewMode: string };
} => {
  const { entityType, entityId, templateBundle, templateViewMode } =
    extractEntityParams(window.location.href);
  // Content templates have no page data owner: the route decides.
  if (templateBundle && templateViewMode) {
    return {
      language: getEditorLanguage(window.location.href),
      entityType,
      entityId,
      template: { bundle: templateBundle, viewMode: templateViewMode },
    };
  }
  const owner = (api.getState() as Partial<RootState>).pageDataOwner;
  if (api.type === 'mutation' && owner) {
    return {
      language: owner.langcode,
      entityType: owner.entityType,
      entityId: owner.entityId,
    };
  }
  return {
    language: getEditorLanguage(window.location.href),
    entityType,
    entityId,
  };
};

/**
 * Cache key for queries the base query names the edited translation on.
 *
 * The default translation and a forked translation of the same entity are
 * different data, so the editor language is part of the cache key.
 */
export const serializeEditorQueryArgs: SerializeQueryArgs<any> = ({
  queryArgs,
  endpointDefinition,
  endpointName,
}) =>
  defaultSerializeQueryArgs({
    queryArgs: {
      ...queryArgs,
      editorLanguage: getEditorLanguage(window.location.href),
    },
    endpointDefinition,
    endpointName,
  });

const rawBaseQuery = (appConfiguration: AppConfiguration) => {
  const { baseUrl } = appConfiguration;
  const defaultQuery = fetchBaseQuery({
    baseUrl,
    prepareHeaders: async (headers, api) => {
      if (api.type === 'mutation') {
        try {
          headers.set('X-CSRF-Token', await fetchCsrfToken(baseUrl));
        } catch (error) {
          console.error((error as Error).message);
        }
      }
    },
  });
  return async (
    arg: string | FetchArgs,
    api: BaseQueryApi,
    extraOptions: object = {},
  ) => {
    const url = typeof arg == 'string' ? arg : arg.url;
    const { entityType, entityId, templateBundle, templateViewMode } =
      extractEntityParams(window.location.href);
    const translation = getRequestTranslation(api);
    const newUrl = addEditorLanguageToUrl(
      replaceEntityParamsInUrl(
        url,
        entityType,
        entityId,
        templateBundle,
        templateViewMode,
      ),
      translation.language,
      translation.entityType,
      translation.entityId,
      translation.template,
    );
    const newArg = typeof arg == 'string' ? newUrl : { ...arg, url: newUrl };
    return defaultQuery(newArg, api, extraOptions);
  };
};

// Higher-order base query to inject the current autoSavesHash and clientInstanceId for all mutations (POST, PATCH, DELETE)
// to allow the backend to recognize potential conflicts where the client is updating potentially out of date data.
export const withAutoSavesInjection: (
  baseQuery: BaseQueryFn<string | FetchArgs, unknown, FetchBaseQueryError>,
) => BaseQueryFn<string | FetchArgs, unknown, FetchBaseQueryError> = (
  baseQuery,
) => {
  return (args, api, extraOptions) => {
    if (typeof args === 'object') {
      const { url } = args;
      if (
        url &&
        api.type === 'mutation' &&
        // Skip autoSaves injection for mutations that do not impact data that
        // is autosaved, such as creating folders.
        ![
          'createFolder',
          'createContentTemplate',
          'updateContentTemplate',
          'updateFolder',
          'uploadFont',
          'createColor',
          'updateColor',
          'deleteColor',
          'setLangcode',
        ].includes(api.endpoint)
      ) {
        const state = api.getState() as RootState;
        const { publishReview } = state;
        const { entityType, entityId, templateBundle, templateViewMode } =
          extractEntityParams(window.location.href);
        const autoSaveKey = replaceEntityParamsInUrl(
          url,
          entityType,
          entityId,
          templateBundle,
          templateViewMode,
        );
        // We want to send back the specific autoSave hash for the particular URL being updated
        const autoSaves =
          autoSaveKey && publishReview.autoSavesHash[autoSaveKey]
            ? publishReview.autoSavesHash[autoSaveKey]
            : undefined;
        return baseQuery(
          {
            ...args,
            body: {
              ...args.body,
              ...(autoSaves && { autoSaves }),
              clientInstanceId: publishReview.clientInstanceId,
            },
          },
          api,
          extraOptions,
        );
      }
    }
    return baseQuery(args, api, extraOptions);
  };
};

// Export a baseQuery with autoSaves injection by default
export const baseQueryWithAutoSaves: BaseQueryFn<
  string | FetchArgs,
  unknown,
  FetchBaseQueryError
> = withAutoSavesInjection(baseQuery);
