import { createApi } from '@reduxjs/toolkit/query/react';

import { baseQueryWithAutoSaves } from '@/services/baseQuery';
import { pendingChangesApi } from '@/services/pendingChangesApi';
import { handleAutoSavesHashUpdate } from '@/utils/autoSaves';

import type { AutoSavesHash } from '@/types/AutoSaves';
import type { BrandKit } from '@/types/CodeComponent';

export interface UploadedArtifact {
  fid: number;
  uri: string;
  url: string;
}

export const createUploadFontRequest = (file: File) => ({
  url: 'canvas/api/v0/artifacts/upload',
  method: 'POST' as const,
  body: file.slice(0, file.size, 'application/octet-stream'),
  headers: {
    'Content-Disposition': `file; filename="${file.name.replaceAll('"', '\\"')}"`,
  },
});

export const brandKitApi = createApi({
  reducerPath: 'brandKitApi',
  baseQuery: baseQueryWithAutoSaves,
  tagTypes: ['BrandKits', 'BrandKitsAutoSave'],
  endpoints: (builder) => ({
    getBrandKits: builder.query<Record<string, BrandKit>, void>({
      query: () => 'canvas/api/v0/config/brand_kit',
      providesTags: () => [{ type: 'BrandKits', id: 'LIST' }],
    }),
    getBrandKit: builder.query<BrandKit, string>({
      query: (id) => `canvas/api/v0/config/brand_kit/${id}`,
      providesTags: (result, error, id) => [{ type: 'BrandKits', id }],
    }),
    getAutoSave: builder.query<
      { data: BrandKit; autoSaves: AutoSavesHash },
      string
    >({
      query: (id) => `canvas/api/v0/config/auto-save/brand_kit/${id}`,
      providesTags: (result, error, id) => [{ type: 'BrandKitsAutoSave', id }],
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
        data: Partial<BrandKit>;
      }
    >({
      query: ({ id, data }) => ({
        url: `canvas/api/v0/config/auto-save/brand_kit/${id}`,
        method: 'PATCH',
        body: { data },
      }),
      async onQueryStarted(arg, { dispatch, queryFulfilled }) {
        try {
          await queryFulfilled;
          dispatch(
            pendingChangesApi.util.invalidateTags([
              { type: 'PendingChanges', id: 'LIST' },
            ]),
          );
        } catch (err) {
          console.error(err);
        }
      },
      invalidatesTags: (result, error, { id }) => [
        { type: 'BrandKitsAutoSave', id },
      ],
    }),
    uploadFont: builder.mutation<UploadedArtifact, File>({
      query: (file) => createUploadFontRequest(file),
    }),
  }),
});

export const {
  useGetBrandKitsQuery,
  useGetBrandKitQuery,
  useGetAutoSaveQuery,
  useUpdateAutoSaveMutation,
  useUploadFontMutation,
} = brandKitApi;
