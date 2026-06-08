import { createApi } from '@reduxjs/toolkit/query/react';

import { setPostPreviewCompleted } from '@/components/review/PublishReview.slice';
import { setLayoutModel } from '@/features/layout/layoutModelSlice';
import {
  setInitialPageData,
  setPageData,
} from '@/features/pageData/pageDataSlice';
import { setHtml } from '@/features/pagePreview/previewSlice';
import { baseQueryWithAutoSaves } from '@/services/baseQuery';
import { pendingChangesApi } from '@/services/pendingChangesApi';
import { handleAutoSavesHashUpdate } from '@/utils/autoSaves';

import type { RootLayoutModel } from '@/features/layout/layoutModelSlice';
import type {
  UpdateComponentQueryArg,
  UpdateComponentResultType,
} from '@/services/preview';
import type { AutoSavesHash } from '@/types/AutoSaves';
import type { CodeComponentSerialized } from '@/types/CodeComponent';
import type { ComponentsList, libraryTypes } from '@/types/Component';

type getComponentsQueryOptions = {
  libraries: libraryTypes[];
  mode: 'include' | 'exclude';
};

type LayoutApiResponse = RootLayoutModel & {
  entity_form_fields: Record<string, any>;
  isNew: boolean;
  isPublished: boolean;
  hasUnsavedStatusChange?: boolean;
  html: string;
  autoSaves: AutoSavesHash;
  translations?: Record<string, any>;
};

export type TemplateViewMode = {
  entityType: string;
  bundle: string;
  viewMode: string;
  viewModeLabel: string;
  label: string;
  status: boolean;
  id: string;
  suggestedPreviewEntityId?: number;
};

export type TemplateInBundle = {
  label: string;
  viewModes: {
    [key: string]: TemplateViewMode;
  };
  deleteUrl?: string;
  editFieldsUrl?: string;
};

export type TemplatesInBundle = {
  [key: string]: TemplateInBundle;
};

type TemplateList = {
  [key: string]: {
    label: string;
    bundles: TemplatesInBundle;
  };
};

export type ModeData = {
  label: string;
  hasTemplate: boolean;
};

export type ViewModesListItem = {
  [key: string]: ModeData;
};

export type ViewModesList = {
  [key: string]: ViewModesListItem;
};

export type PreviewContentEntity = {
  id: string;
  label: string;
};

export type PreviewContentEntitiesResponse = {
  [key: string]: PreviewContentEntity;
};

export type ComponentUsageListResponse = {
  data: Record<string, boolean>;
  links: {
    prev: string | null;
    next: string | null;
  };
};
export type ComponentUsageDetailsResponse = {
  content: Array<{
    id: string;
    title: string;
    type?: string;
    bundle?: string;
    revision_id?: string;
  }>;
};

/** Rebuilds component id → folder id map from folder records (matches `getFolders` transformResponse). */
export function rebuildComponentIndexedFolders(
  folders: Record<string, { items?: string[] }>,
): Record<string, string> {
  return Object.entries(folders).reduce(
    (carry, [folderId, folderInfo]) => {
      folderInfo?.items?.forEach((componentId: string) => {
        carry[componentId] = folderId;
      });
      return carry;
    },
    {} as Record<string, string>,
  );
}

/** Normalize folder API payloads (items as string[], stable id/weight). */
function normalizeFolderFromApi(raw: unknown): Record<string, unknown> {
  if (!raw || typeof raw !== 'object') {
    return {};
  }
  const r = raw as Record<string, unknown>;
  return {
    ...r,
    id: r.id != null ? String(r.id) : r.id,
    items: Array.isArray(r.items) ? r.items : [],
    weight: typeof r.weight === 'number' ? r.weight : Number(r.weight) || 0,
    name: r.name,
    type: r.type,
  };
}

export const componentAndLayoutApi = createApi({
  reducerPath: 'componentAndLayoutApi',
  baseQuery: baseQueryWithAutoSaves,
  tagTypes: [
    'Components',
    'CodeComponents',
    'CodeComponentAutoSave',
    'Layout',
    'Folders',
    'ContentTemplates',
    'ViewModes',
    'PreviewContentEntities',
  ],
  endpoints: (builder) => ({
    getComponents: builder.query<
      ComponentsList,
      getComponentsQueryOptions | void
    >({
      query: () => `canvas/api/v0/config/component`,
      providesTags: () => [{ type: 'Components', id: 'LIST' }],
      transformResponse: (response: ComponentsList) => {
        return Object.fromEntries(
          Object.entries(response).sort(([, a], [, b]) =>
            a.name.localeCompare(b.name),
          ),
        );
      },
    }),
    getComponentUsageList: builder.query<ComponentUsageListResponse, void>({
      query: () => `/canvas/api/v0/usage/component`,
    }),
    getComponentUsageDetails: builder.query<
      ComponentUsageDetailsResponse,
      string
    >({
      query: (id) => `/canvas/api/v0/usage/component/${id}/details`,
    }),
    getPageLayout: builder.query<
      LayoutApiResponse,
      { entityId: string; entityType: string; language?: string }
    >({
      query: ({ entityId, entityType, language }) => {
        // When a language code is provided, prefix the URL so Drupal serves
        // the translated content for that language.
        return language
          ? `${language}/canvas/api/v0/layout/${entityType}/${entityId}`
          : `canvas/api/v0/layout/${entityType}/${entityId}`;
      },
      providesTags: () => [{ type: 'Layout' }],
      async onQueryStarted(arg, { dispatch, queryFulfilled }) {
        try {
          const {
            data: { entity_form_fields, html, autoSaves },
            meta,
          } = await queryFulfilled;
          dispatch(setInitialPageData(entity_form_fields));
          dispatch(setHtml(html));
          handleAutoSavesHashUpdate(dispatch, autoSaves, meta);
        } catch (err) {
          dispatch(setPageData({}));
        }
      },
    }),
    getTemplateLayout: builder.query<
      LayoutApiResponse,
      {
        entityType: string;
        bundle: string;
        viewMode: string;
        previewEntityId: string;
      }
    >({
      query: ({ bundle, viewMode, entityType, previewEntityId }) => {
        return `canvas/api/v0/layout-content-template/${entityType}.${bundle}.${viewMode}/${previewEntityId}`;
      },
      providesTags: () => [{ type: 'Layout' }],
      async onQueryStarted(arg, { dispatch, queryFulfilled }) {
        try {
          const {
            data: { entity_form_fields, html, autoSaves },
            meta,
          } = await queryFulfilled;
          dispatch(setInitialPageData(entity_form_fields));
          dispatch(setHtml(html));
          handleAutoSavesHashUpdate(dispatch, autoSaves, meta);
        } catch (err) {
          dispatch(setPageData({}));
        }
      },
    }),
    postTemplateLayout: builder.mutation<
      { html: string; autoSaves: AutoSavesHash },
      { layout: any; model: any; entity_form_fields: any }
    >({
      query: (body) => ({
        url: 'canvas/api/v0/layout-content-template/{entity_type}.{template_bundle}.{template_view_mode}/{entity_id}',
        method: 'POST',
        body,
      }),
      async onQueryStarted(arg, { dispatch, queryFulfilled }) {
        const { data, meta } = await queryFulfilled;
        const { html, autoSaves } = data;
        dispatch(
          pendingChangesApi.util.invalidateTags([
            { type: 'PendingChanges', id: 'LIST' },
          ]),
        );
        // Update our template preview slice.
        dispatch(setHtml(html));
        handleAutoSavesHashUpdate(dispatch, autoSaves, meta);
        dispatch(setPostPreviewCompleted(true));
      },
    }),
    updateComponentInTemplate: builder.mutation<
      UpdateComponentResultType,
      UpdateComponentQueryArg
    >({
      query: (body) => ({
        url: 'canvas/api/v0/layout-content-template/{entity_type}.{template_bundle}.{template_view_mode}/{entity_id}',
        method: 'PATCH',
        body,
      }),
      async onQueryStarted(body, { dispatch, queryFulfilled }) {
        const { data, meta } = await queryFulfilled;
        const { html, layout, model, autoSaves } = data;
        dispatch(
          pendingChangesApi.util.invalidateTags([
            { type: 'PendingChanges', id: 'LIST' },
          ]),
        );
        dispatch(setHtml(html));
        handleAutoSavesHashUpdate(dispatch, autoSaves, meta);
        // Pass update preview false to prevent a subsequent preview update,
        // we have the data here.
        dispatch(setLayoutModel({ layout, model, updatePreview: false }));
      },
    }),

    getCodeComponents: builder.query<
      Record<string, CodeComponentSerialized>,
      { status?: boolean } | void
    >({
      query: () => 'canvas/api/v0/config/js_component',
      providesTags: () => [{ type: 'CodeComponents', id: 'LIST' }],
    }),
    getCodeComponent: builder.query<CodeComponentSerialized, string>({
      query: (id) => `canvas/api/v0/config/js_component/${id}`,
      providesTags: (result, error, id) => [{ type: 'CodeComponents', id }],
    }),
    createCodeComponent: builder.mutation<
      CodeComponentSerialized,
      Partial<CodeComponentSerialized>
    >({
      query: (body) => ({
        url: 'canvas/api/v0/config/js_component',
        method: 'POST',
        body,
      }),
      invalidatesTags: [{ type: 'CodeComponents', id: 'LIST' }],
    }),
    updateCodeComponent: builder.mutation<
      CodeComponentSerialized,
      { id: string; changes: Partial<CodeComponentSerialized> }
    >({
      query: ({ id, changes }) => ({
        url: `canvas/api/v0/config/js_component/${id}`,
        method: 'PATCH',
        body: changes,
      }),
      invalidatesTags: (result, error, { id }) => [
        { type: 'CodeComponents', id },
        { type: 'CodeComponentAutoSave', id },
        { type: 'CodeComponents', id: 'LIST' },
        { type: 'Components', id: 'LIST' },
        { type: 'Layout' },
      ],
    }),
    deleteCodeComponent: builder.mutation<void, string>({
      query: (id) => ({
        url: `canvas/api/v0/config/js_component/${id}`,
        method: 'DELETE',
      }),
      // Manually delete the cache entry for the deleted component.
      // This is necessary because we need to delete RTK's cache entry for the component. If we do this
      // by invalidating the cache tag in the invalidatesTags function, getCodeComponent gets called for this deleted component
      // on a re-render which results in a 404.
      async onQueryStarted(id, { dispatch, queryFulfilled }) {
        const deleteCacheEntry = dispatch(
          componentAndLayoutApi.util.updateQueryData(
            'getCodeComponent',
            id,
            (draft) => {
              for (const key in draft) {
                delete (draft as Record<string, any>)[key];
              }
            },
          ),
        );
        try {
          await queryFulfilled;
        } catch (error) {
          deleteCacheEntry.undo();
        }
      },
      invalidatesTags: (result, error, id) => [
        { type: 'CodeComponentAutoSave', id },
        { type: 'CodeComponents', id: 'LIST' },
        { type: 'Components', id: 'LIST' },
      ],
    }),
    createFolder: builder.mutation<any, any>({
      query: (body) => ({
        url: 'canvas/api/v0/config/folder',
        method: 'POST',
        body: {
          items: body.items || [],
          name: body.name,
          weight: body.weight || 0,
          type: body.type,
        },
      }),
      transformResponse: (response: unknown) =>
        normalizeFolderFromApi(response),
      async onQueryStarted(body, { dispatch, queryFulfilled, getState }) {
        // If weight is not explicitly provided, calculate it to place the folder at the top.
        if (body.weight === undefined || body.weight === 0) {
          const state = getState() as any;
          const foldersData =
            state.componentAndLayoutApi?.queries?.['getFolders(undefined)']
              ?.data;

          if (foldersData?.folders) {
            const folders = Object.entries(foldersData.folders)
              .filter(
                ([folderKey]) => !folderKey.startsWith('optimistic-folder-'),
              )
              .map(([, folder]) => folder);
            if (folders.length > 0) {
              // Find the minimum weight among existing folders
              const minWeight = Math.min(
                ...folders.map((folder: any) => folder.weight || 0),
              );
              // Set new folder weight to be less than minimum to appear at top
              body.weight = minWeight - 1;
            }
          }
        }

        try {
          const { data } = await queryFulfilled;
          const created = data as { id?: string };
          const id =
            created?.id != null && String(created.id).length > 0
              ? String(created.id)
              : null;

          const foldersCache =
            componentAndLayoutApi.endpoints.getFolders.select()(
              getState() as any,
            );

          if (foldersCache.data && id) {
            const normalized = normalizeFolderFromApi(data) as Record<
              string,
              unknown
            >;
            dispatch(
              componentAndLayoutApi.util.updateQueryData(
                'getFolders',
                undefined,
                (draft) => {
                  draft.folders[id] = normalized;
                  draft.componentIndexedFolders =
                    rebuildComponentIndexedFolders(draft.folders);
                },
              ),
            );
            return;
          }

          dispatch(
            componentAndLayoutApi.endpoints.getFolders.initiate(undefined, {
              forceRefetch: true,
              subscribe: false,
            }),
          );
        } catch {
          dispatch(
            componentAndLayoutApi.util.invalidateTags([
              { type: 'Folders', id: 'LIST' },
            ]),
          );
        }
      },
      // Avoid loading the full folder list again: that briefly replaces cache and flickers the
      // library. The POST response is merged into `getFolders` in onQueryStarted instead.
      invalidatesTags: [],
    }),
    updateFolder: builder.mutation<
      any,
      { id: string; changes: any; skipFoldersOptimistic?: boolean }
    >({
      query: ({ id, changes }) => ({
        url: `canvas/api/v0/config/folder/${id}`,
        method: 'PATCH',
        body: changes,
      }),
      async onQueryStarted(
        { id, changes, skipFoldersOptimistic },
        { dispatch, queryFulfilled },
      ) {
        if (skipFoldersOptimistic) {
          return;
        }
        const patchResult = dispatch(
          componentAndLayoutApi.util.updateQueryData(
            'getFolders',
            undefined,
            (draft) => {
              const folderDraft = draft.folders[id];
              if (!folderDraft) {
                return;
              }
              if (changes.name !== undefined) {
                folderDraft.name = changes.name;
              }
              if (changes.weight !== undefined) {
                folderDraft.weight = changes.weight;
              }
              if (changes.items !== undefined) {
                folderDraft.items = changes.items;
              }
              draft.componentIndexedFolders = rebuildComponentIndexedFolders(
                draft.folders,
              );
            },
          ),
        );
        try {
          await queryFulfilled;
        } catch {
          patchResult.undo();
        }
      },
      invalidatesTags: (result, error, arg) => {
        // Batched handler updates (move / reorder) set skipFoldersOptimistic and apply one
        // optimistic cache patch; invalidating after each PATCH reloads the list from the server
        // mid-operation and causes flicker. Those flows call invalidateTags once after all requests.
        if (!error && arg.skipFoldersOptimistic) {
          return [];
        }
        return [{ type: 'Folders', id: 'LIST' }, { type: 'Layout' }];
      },
    }),
    deleteFolder: builder.mutation<void, string>({
      query: (id) => ({
        url: `canvas/api/v0/config/folder/${id}`,
        method: 'DELETE',
      }),
      invalidatesTags: [
        { type: 'Folders', id: 'LIST' },
        { type: 'Components', id: 'LIST' },
      ],
    }),
    getFolders: builder.query<
      {
        folders: Record<string, any>;
        componentIndexedFolders: Record<string, string>;
      },
      void
    >({
      query: () => 'canvas/api/v0/config/folder',
      providesTags: () => [{ type: 'Folders', id: 'LIST' }],
      transformResponse: (response: any) => {
        const raw = response && typeof response === 'object' ? response : {};
        const folders = Object.fromEntries(
          Object.entries(raw).map(([id, folder]) => [
            id,
            normalizeFolderFromApi(folder),
          ]),
        );
        return {
          folders,
          componentIndexedFolders: rebuildComponentIndexedFolders(folders),
        };
      },
    }),
    getAutoSave: builder.query<
      { data: CodeComponentSerialized; autoSaves: AutoSavesHash },
      string
    >({
      query: (id) => `canvas/api/v0/config/auto-save/js_component/${id}`,
      providesTags: (result, error, id) => [
        { type: 'CodeComponentAutoSave', id },
      ],
      async onQueryStarted(id, { dispatch, queryFulfilled }) {
        try {
          const {
            data: { autoSaves },
            meta,
          } = await queryFulfilled;
          handleAutoSavesHashUpdate(dispatch, autoSaves, meta);
        } catch (err) {
          console.error(err);
        }
      },
    }),
    updateAutoSave: builder.mutation<
      void,
      {
        id: string;
        data: Partial<CodeComponentSerialized>;
      }
    >({
      query: ({ id, data }) => ({
        url: `canvas/api/v0/config/auto-save/js_component/${id}`,
        method: 'PATCH',
        body: { data },
      }),
      invalidatesTags: (result, error, { id }) => [
        { type: 'CodeComponentAutoSave', id },
        { type: 'Components', id: 'LIST' }, // The component list contains markup for the preview thumbnails.
      ],
    }),
    createContentTemplate: builder.mutation<any, any>({
      query: (body) => ({
        url: 'canvas/api/v0/config/content_template',
        method: 'POST',
        body,
      }),
      invalidatesTags: [
        { type: 'ContentTemplates', id: 'LIST' },
        { type: 'ViewModes', id: 'LIST' },
      ],
    }),
    deleteContentTemplate: builder.mutation<void, string>({
      query: (id: string) => ({
        url: `canvas/api/v0/config/content_template/${id}`,
        method: 'DELETE',
      }),
      invalidatesTags: [
        { type: 'ContentTemplates', id: 'LIST' },
        { type: 'ViewModes', id: 'LIST' },
      ],
    }),
    getContentTemplates: builder.query<TemplateList, void>({
      query: () => `canvas/api/v0/config/content_template`,
      providesTags: () => [{ type: 'ContentTemplates', id: 'LIST' }],
    }),
    getViewModes: builder.query<ViewModesList, void>({
      query: () => `canvas/api/v0/ui/content_template/view_modes/node`,
      providesTags: () => [{ type: 'ViewModes', id: 'LIST' }],
    }),
    getPreviewContentEntities: builder.query<
      PreviewContentEntitiesResponse,
      { entityTypeId: string; bundle: string }
    >({
      query: ({ entityTypeId, bundle }) =>
        `canvas/api/v0/ui/content_template/suggestions/preview/${entityTypeId}/${bundle}`,
      providesTags: (result, error, { entityTypeId, bundle }) => [
        { type: 'PreviewContentEntities', id: `${entityTypeId}-${bundle}` },
      ],
    }),
  }),
});

export const {
  useGetComponentsQuery,
  useGetComponentUsageDetailsQuery,
  useGetComponentUsageListQuery,
  useGetPageLayoutQuery,
  useGetTemplateLayoutQuery,
  usePostTemplateLayoutMutation,
  useUpdateComponentInTemplateMutation,
  useGetCodeComponentsQuery,
  useGetCodeComponentQuery,
  useCreateCodeComponentMutation,
  useUpdateCodeComponentMutation,
  useDeleteCodeComponentMutation,
  useCreateFolderMutation,
  useUpdateFolderMutation,
  useDeleteFolderMutation,
  useGetFoldersQuery,
  useGetAutoSaveQuery,
  useUpdateAutoSaveMutation,
  useCreateContentTemplateMutation,
  useDeleteContentTemplateMutation,
  useGetContentTemplatesQuery,
  useGetViewModesQuery,
  useGetPreviewContentEntitiesQuery,
} = componentAndLayoutApi;
