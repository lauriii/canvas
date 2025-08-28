import { createApi } from '@reduxjs/toolkit/query/react';
import { baseQueryWithAutoSaves } from '@/services/baseQuery';
import type { CodeComponentSerialized } from '@/types/CodeComponent';
import type { ComponentsList, libraryTypes } from '@/types/Component';
import type { RootLayoutModel } from '@/features/layout/layoutModelSlice';
import {
  setPageData,
  setInitialPageData,
} from '@/features/pageData/pageDataSlice';
import { setHtml } from '@/features/pagePreview/previewSlice';
import type { AutoSavesHash } from '@/types/AutoSaves';
import { handleAutoSavesHashUpdate } from '@/utils/autoSaves';

type getComponentsQueryOptions = {
  libraries: libraryTypes[];
  mode: 'include' | 'exclude';
};

type LayoutApiResponse = RootLayoutModel & {
  entity_form_fields: Record<string, any>;
  isNew: boolean;
  isPublished: boolean;
  html: string;
  autoSaves: AutoSavesHash;
};

export const componentAndLayoutApi = createApi({
  reducerPath: 'componentAndLayoutApi',
  baseQuery: baseQueryWithAutoSaves,
  tagTypes: [
    'Components',
    'CodeComponents',
    'CodeComponentAutoSave',
    'Layout',
    'Folders',
  ],
  endpoints: (builder) => ({
    getComponents: builder.query<
      ComponentsList,
      getComponentsQueryOptions | void
    >({
      query: () => `xb/api/v0/config/component`,
      providesTags: () => [{ type: 'Components', id: 'LIST' }],
      transformResponse: (response: ComponentsList) => {
        return Object.fromEntries(
          Object.entries(response).sort(([, a], [, b]) =>
            a.name.localeCompare(b.name),
          ),
        );
      },
    }),
    getLayoutById: builder.query<LayoutApiResponse, string>({
      query: (nodeId) => `xb/api/v0/layout/{entity_type}/${nodeId}`,
      providesTags: () => [{ type: 'Layout' }],
      async onQueryStarted(id, { dispatch, queryFulfilled }) {
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
    getCodeComponents: builder.query<
      Record<string, CodeComponentSerialized>,
      { status?: boolean } | void
    >({
      query: () => 'xb/api/v0/config/js_component',
      providesTags: () => [{ type: 'CodeComponents', id: 'LIST' }],
      transformResponse: (
        response: Record<string, CodeComponentSerialized>,
        meta,
        arg,
      ) => {
        if (!arg || typeof arg !== 'object') {
          // If no filter is provided or arg is undefined, return all components.
          return response;
        }

        const { status } = arg;

        return Object.entries(response).reduce(
          (filtered, [key, component]) => {
            // Only filter by status if it's provided (internal=false, exposed=true)
            const statusMatch =
              status === undefined ? true : component.status === status;
            if (statusMatch) {
              filtered[key] = component;
            }
            return filtered;
          },
          {} as Record<string, CodeComponentSerialized>,
        );
      },
    }),
    getCodeComponent: builder.query<CodeComponentSerialized, string>({
      query: (id) => `xb/api/v0/config/js_component/${id}`,
      providesTags: (result, error, id) => [{ type: 'CodeComponents', id }],
    }),
    createCodeComponent: builder.mutation<
      CodeComponentSerialized,
      Partial<CodeComponentSerialized>
    >({
      query: (body) => ({
        url: 'xb/api/v0/config/js_component',
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
        url: `xb/api/v0/config/js_component/${id}`,
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
        url: `xb/api/v0/config/js_component/${id}`,
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
        url: 'xb/api/v0/config/folder',
        method: 'POST',
        body: {
          items: body.items || [],
          name: body.name,
          weight: body.weight || 0,
          type: body.type,
        },
      }),
      invalidatesTags: [{ type: 'Folders', id: 'LIST' }],
    }),
    getFolders: builder.query<any, any>({
      query: () => 'xb/api/v0/config/folder',
      providesTags: () => [{ type: 'Folders', id: 'LIST' }],
      transformResponse: (response: any) => {
        // Create a mapping of component IDs to folder IDs for quick access.
        const componentIndexedFolders: Record<string, string> = Object.entries(
          response,
        ).reduce(
          (
            carry: Record<string, string>,
            [folderId, folderInfo]: [string, any],
          ) => {
            folderInfo?.items.forEach((componentId: string) => {
              carry[componentId] = folderId;
            });
            return carry;
          },
          {} as Record<string, string>,
        );
        return {
          folders: response,
          componentIndexedFolders,
        };
      },
    }),
    getAutoSave: builder.query<
      { data: CodeComponentSerialized; autoSaves: AutoSavesHash },
      string
    >({
      query: (id) => `xb/api/v0/config/auto-save/js_component/${id}`,
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
        url: `xb/api/v0/config/auto-save/js_component/${id}`,
        method: 'PATCH',
        body: { data },
      }),
      invalidatesTags: (result, error, { id }) => [
        { type: 'CodeComponentAutoSave', id },
        { type: 'Components', id: 'LIST' }, // The component list contains markup for the preview thumbnails.
      ],
    }),
  }),
});

export const {
  useGetComponentsQuery,
  useGetLayoutByIdQuery,
  useGetCodeComponentsQuery,
  useGetCodeComponentQuery,
  useCreateCodeComponentMutation,
  useUpdateCodeComponentMutation,
  useDeleteCodeComponentMutation,
  useCreateFolderMutation,
  useGetFoldersQuery,
  useGetAutoSaveQuery,
  useUpdateAutoSaveMutation,
} = componentAndLayoutApi;
