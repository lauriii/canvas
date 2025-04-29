import { createApi } from '@reduxjs/toolkit/query/react';
import { baseQuery } from '@/services/baseQuery';

import type { AssetLibrary } from '@/types/CodeComponent';

export const assetLibraryApi = createApi({
  reducerPath: 'assetLibraryApi',
  baseQuery,
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
    getAutoSave: builder.query<AssetLibrary, string>({
      query: (id) => `xb/api/v0/config/auto-save/xb_asset_library/${id}`,
      providesTags: (result, error, id) => [
        { type: 'AssetLibrariesAutoSave', id },
      ],
    }),
    updateAutoSave: builder.mutation<
      void,
      { id: string; data: Partial<AssetLibrary> }
    >({
      query: ({ id, data }) => ({
        url: `xb/api/v0/config/auto-save/xb_asset_library/${id}`,
        method: 'PATCH',
        body: data,
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
