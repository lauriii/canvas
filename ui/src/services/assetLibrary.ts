import { createApi } from '@reduxjs/toolkit/query/react';
import { baseQueryWithAutoSaves } from '@/services/baseQuery';
import { handleAutoSavesHashUpdate } from '@/utils/autoSaves';

import type { AssetLibrary } from '@/types/CodeComponent';
import type { AutoSavesHash } from '@/types/AutoSaves';

export const assetLibraryApi = createApi({
  reducerPath: 'assetLibraryApi',
  baseQuery: baseQueryWithAutoSaves,
  tagTypes: ['AssetLibraries', 'AssetLibrariesAutoSave'],
  endpoints: (builder) => ({
    getAssetLibraries: builder.query<Record<string, AssetLibrary>, void>({
      query: () => 'xb/api/v0/config/xb_asset_library',
      providesTags: () => [{ type: 'AssetLibraries', id: 'LIST' }],
    }),
    getAssetLibrary: builder.query<AssetLibrary, string>({
      query: (id) => `xb/api/v0/config/xb_asset_library/${id}`,
      providesTags: (result, error, id) => [{ type: 'AssetLibraries', id }],
    }),
    createAssetLibrary: builder.mutation<AssetLibrary, Partial<AssetLibrary>>({
      query: (body) => ({
        url: 'xb/api/v0/config/xb_asset_library',
        method: 'POST',
        body,
      }),
      invalidatesTags: [{ type: 'AssetLibraries', id: 'LIST' }],
    }),
    updateAssetLibrary: builder.mutation<
      AssetLibrary,
      { id: string; changes: Partial<AssetLibrary> }
    >({
      query: ({ id, changes }) => ({
        url: `xb/api/v0/config/xb_asset_library/${id}`,
        method: 'PATCH',
        body: changes,
      }),
      invalidatesTags: (result, error, { id }) => [
        { type: 'AssetLibraries', id },
        { type: 'AssetLibraries', id: 'LIST' },
      ],
    }),
    deleteAssetLibrary: builder.mutation<void, string>({
      query: (id) => ({
        url: `xb/api/v0/config/xb_asset_library/${id}`,
        method: 'DELETE',
      }),
      invalidatesTags: [{ type: 'AssetLibraries', id: 'LIST' }],
    }),
    getAutoSave: builder.query<
      { data: AssetLibrary; autoSaves: AutoSavesHash },
      string
    >({
      query: (id) => `xb/api/v0/config/auto-save/xb_asset_library/${id}`,
      providesTags: (result, error, id) => [
        { type: 'AssetLibrariesAutoSave', id },
      ],
      async onQueryStarted(id, { dispatch, queryFulfilled }) {
        try {
          const { data, meta } = await queryFulfilled;
          const { autoSaves } = data;
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
        data: Partial<AssetLibrary>;
      }
    >({
      query: ({ id, data }) => ({
        url: `xb/api/v0/config/auto-save/xb_asset_library/${id}`,
        method: 'PATCH',
        body: { data },
      }),
      invalidatesTags: (result, error, { id }) => [
        { type: 'AssetLibrariesAutoSave', id },
      ],
    }),
  }),
});

export const {
  useGetAssetLibrariesQuery,
  useGetAssetLibraryQuery,
  useCreateAssetLibraryMutation,
  useUpdateAssetLibraryMutation,
  useDeleteAssetLibraryMutation,
  useGetAutoSaveQuery,
  useUpdateAutoSaveMutation,
} = assetLibraryApi;
