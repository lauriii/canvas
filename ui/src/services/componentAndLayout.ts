import { createApi } from '@reduxjs/toolkit/query/react';
import { baseQuery } from '@/services/baseQuery';
import type { CodeComponentSerialized } from '@/types/CodeComponent';
import type { ComponentsList } from '@/types/Component';
import type { RootLayoutModel } from '@/features/layout/layoutModelSlice';
import { setPageData } from '@/features/pageData/pageDataSlice';

export const componentAndLayoutApi = createApi({
  reducerPath: 'componentAndLayoutApi',
  baseQuery,
  tagTypes: ['Components', 'CodeComponents', 'CodeComponentAutoSave', 'Layout'],
  endpoints: (builder) => ({
    getComponents: builder.query<ComponentsList, void>({
      query: () => `xb/api/config/component`,
      providesTags: () => [{ type: 'Components', id: 'LIST' }],
    }),
    getLayoutById: builder.query<
      RootLayoutModel & { entity_form_fields: {} },
      string
    >({
      query: (nodeId) => `xb/api/layout/{entity_type}/${nodeId}`,
      providesTags: () => [{ type: 'Layout' }],
      async onQueryStarted(id, { dispatch, queryFulfilled }) {
        try {
          const {
            data: { entity_form_fields },
          } = await queryFulfilled;
          dispatch(setPageData(entity_form_fields));
        } catch (err) {
          dispatch(setPageData({}));
        }
      },
    }),
    getCodeComponents: builder.query<
      Record<string, CodeComponentSerialized>,
      void
    >({
      query: () => 'xb/api/config/js_component',
      providesTags: () => [{ type: 'CodeComponents', id: 'LIST' }],
    }),
    getCodeComponent: builder.query<CodeComponentSerialized, string>({
      query: (id) => `xb/api/config/js_component/${id}`,
      providesTags: (result, error, id) => [{ type: 'CodeComponents', id }],
    }),
    createCodeComponent: builder.mutation<
      CodeComponentSerialized,
      Partial<CodeComponentSerialized>
    >({
      query: (body) => ({
        url: 'xb/api/config/js_component',
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
        url: `xb/api/config/js_component/${id}`,
        method: 'PATCH',
        body: changes,
      }),
      invalidatesTags: (result, error, { id }) => [
        { type: 'CodeComponents', id },
        { type: 'CodeComponents', id: 'LIST' },
        { type: 'Components', id: 'LIST' },
        { type: 'Layout' },
      ],
    }),
    deleteCodeComponent: builder.mutation<void, string>({
      query: (id) => ({
        url: `xb/api/config/js_component/${id}`,
        method: 'DELETE',
      }),
      invalidatesTags: [
        { type: 'CodeComponents', id: 'LIST' },
        { type: 'Components', id: 'LIST' },
      ],
    }),
    getAutoSave: builder.query<CodeComponentSerialized, string>({
      query: (id) => `xb/api/config/auto-save/js_component/${id}`,
      providesTags: (result, error, id) => [
        { type: 'CodeComponentAutoSave', id },
      ],
    }),
    updateAutoSave: builder.mutation<
      void,
      { id: string; data: Partial<CodeComponentSerialized> }
    >({
      query: ({ id, data }) => ({
        url: `xb/api/config/auto-save/js_component/${id}`,
        method: 'PATCH',
        body: data,
      }),
      invalidatesTags: (result, error, { id }) => [
        { type: 'CodeComponentAutoSave', id },
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
  useGetAutoSaveQuery,
  useUpdateAutoSaveMutation,
} = componentAndLayoutApi;
