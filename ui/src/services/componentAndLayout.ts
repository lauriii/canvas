import { createApi } from '@reduxjs/toolkit/query/react';
import { baseQuery } from '@/services/baseQuery';

import type { CodeComponent } from '@/types/CodeComponent';
import type { ComponentsList } from '@/types/Component';
import type { RootLayoutModel } from '@/features/layout/layoutModelSlice';
import { setPageData } from '@/features/pageData/pageDataSlice';

export const componentAndLayoutApi = createApi({
  reducerPath: 'componentAndLayoutApi',
  baseQuery,
  tagTypes: ['Components', 'CodeComponents', 'Layout'],
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
    getCodeComponents: builder.query<Record<string, CodeComponent>, void>({
      query: () => 'xb/api/config/js_component',
      providesTags: () => [{ type: 'CodeComponents', id: 'LIST' }],
    }),
    getCodeComponent: builder.query<CodeComponent, string>({
      query: (id) => `xb/api/config/js_component/${id}`,
      providesTags: (result, error, id) => [{ type: 'CodeComponents', id }],
    }),
    createCodeComponent: builder.mutation<
      CodeComponent,
      Partial<CodeComponent>
    >({
      query: (body) => ({
        url: 'xb/api/config/js_component',
        method: 'POST',
        body,
      }),
      invalidatesTags: [{ type: 'CodeComponents', id: 'LIST' }],
    }),
    updateCodeComponent: builder.mutation<
      CodeComponent,
      { id: string; changes: Partial<CodeComponent> }
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
    updateAutoSave: builder.mutation<
      void,
      { entityTypeId: string; configEntityId: string; data: any }
    >({
      query: ({ entityTypeId, configEntityId, data }) => ({
        url: `xb/api/config/auto-save/${entityTypeId}/${configEntityId}`,
        method: 'PATCH',
        body: data,
      }),
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
  useUpdateAutoSaveMutation,
} = componentAndLayoutApi;
