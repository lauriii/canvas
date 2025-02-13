import { createApi } from '@reduxjs/toolkit/query/react';
import { baseQuery } from '@/services/baseQuery';

import type { CodeComponent } from '@/types/CodeComponent';

export const codeComponentApi = createApi({
  reducerPath: 'codeComponentApi',
  baseQuery,
  tagTypes: ['CodeComponents'],
  endpoints: (builder) => ({
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
      ],
    }),
    deleteCodeComponent: builder.mutation<void, string>({
      query: (id) => ({
        url: `xb/api/config/js_component/${id}`,
        method: 'DELETE',
      }),
      invalidatesTags: [{ type: 'CodeComponents', id: 'LIST' }],
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
  useGetCodeComponentsQuery,
  useGetCodeComponentQuery,
  useCreateCodeComponentMutation,
  useUpdateCodeComponentMutation,
  useDeleteCodeComponentMutation,
  useUpdateAutoSaveMutation,
} = codeComponentApi;
