import { v4 as uuidv4 } from 'uuid';
import { createApi } from '@reduxjs/toolkit/query/react';

import { BRAND_KIT_ID } from '@/features/brandKit/constants';
import { setUpdatePreview } from '@/features/layout/layoutModelSlice';
import { baseQueryWithAutoSaves } from '@/services/baseQuery';
import { pendingChangesApi } from '@/services/pendingChangesApi';
import { handleAutoSavesHashUpdate } from '@/utils/autoSaves';

import type { AutoSavesHash } from '@/types/AutoSaves';
import type { BrandKit, BrandKitColor } from '@/types/CodeComponent';

export interface UploadedArtifact {
  fid: number;
  uri: string;
  url: string;
}

/**
 * Patches the Brand kit cache entry the colors UI reads from.
 *
 * @param mutate - Mutates the draft color list in place.
 */
const patchColors = (mutate: (colors: BrandKitColor[]) => void) =>
  brandKitApi.util.updateQueryData('getBrandKit', BRAND_KIT_ID, (draft) => {
    // The list is omitted entirely while the Brand kit has no colors.
    draft.colors ??= [];
    mutate(draft.colors);
  });

/**
 * Tracks the latest pending write per color id.
 *
 * If an older write fails after a newer optimistic write has already run, it
 * must not undo the newer result. Each write stores a token here and only
 * rolls back its patch if it is still the latest token for that color.
 */
const latestColorWrites = new Map<string, symbol>();

/**
 * A single component instance using a specific color.
 */
export interface ColorComponentUsage {
  component_uuid: string;
  component_id: string;
  label: string | null;
  prop_name: string;
  ancestor_labels: string[];
}

/**
 * A content entity (or revision) referencing a color via component usages.
 */
export interface ContentEntityUsage {
  title: string;
  type: string;
  bundle: string;
  id: string;
  revision_id: string;
  usages: ColorComponentUsage[];
}

/**
 * A config entity whose component tree references a color.
 */
export interface ConfigEntityUsage {
  title: string;
  type: string;
  id: string;
  usages: ColorComponentUsage[];
}

/**
 * Response from GET /canvas/api/v0/usage/color/{color}/details.
 * The usage keys are omitted when empty.
 */
export type ColorUsageDetailsResponse = {
  /**
   * Whether the color may be deleted right now.
   *
   * This mirrors the access check the delete route enforces. Do not infer
   * whether a color can be deleted from the usage lists below: they report
   * neither auto-saves nor default revisions that are no longer the latest.
   */
  deletable: boolean;
  current?: ContentEntityUsage[];
  prior?: ContentEntityUsage[];
  config?: ConfigEntityUsage[];
};

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
  tagTypes: ['BrandKits', 'BrandKitsAutoSave', 'ColorUsageDetails'],
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
    createColor: builder.mutation<BrandKitColor, Omit<BrandKitColor, 'id'>>({
      query: (body) => ({
        url: 'canvas/api/v0/config/color',
        method: 'POST',
        body,
      }),
      async onQueryStarted(color, { dispatch, queryFulfilled }) {
        // Insert a temporary row with a fake client id, then replace it with
        // the server row so the UI never keeps a fake id.
        const pendingId = `pending-${uuidv4()}`;
        const patchResult = dispatch(
          patchColors((colors) => {
            colors.push({ ...color, id: pendingId });
          }),
        );
        try {
          const { data: created } = await queryFulfilled;
          dispatch(
            patchColors((colors) => {
              const index = colors.findIndex((c) => c.id === pendingId);
              if (index !== -1) {
                colors[index] = created;
              }
            }),
          );
        } catch {
          patchResult.undo();
        }
      },
      invalidatesTags: [
        { type: 'BrandKits', id: 'LIST' },
        { type: 'BrandKits', id: BRAND_KIT_ID },
      ],
    }),
    updateColor: builder.mutation<
      BrandKitColor,
      { id: string; changes: Partial<BrandKitColor> }
    >({
      query: ({ id, changes }) => ({
        url: `canvas/api/v0/config/color/${id}`,
        method: 'PATCH',
        body: changes,
      }),
      async onQueryStarted({ id, changes }, { dispatch, queryFulfilled }) {
        const token = Symbol(); // uniquely identifies this write
        latestColorWrites.set(id, token); // record as newest for this color
        const patchResult = dispatch(
          patchColors((colors) => {
            const color = colors.find((candidate) => candidate.id === id);
            if (color) {
              Object.assign(color, changes); // apply optimistically
            }
          }),
        );
        try {
          await queryFulfilled;
          dispatch(setUpdatePreview(true)); // trigger server preview refresh
        } catch {
          // Skip undo if a newer write for this color has since been applied —
          // rolling back here would revert a change the user made after this one.
          if (latestColorWrites.get(id) === token) {
            patchResult.undo();
          }
        } finally {
          if (latestColorWrites.get(id) === token) {
            latestColorWrites.delete(id); // clean up once settled
          }
        }
      },
      invalidatesTags: [
        { type: 'BrandKits', id: 'LIST' },
        { type: 'BrandKits', id: BRAND_KIT_ID },
      ],
    }),
    deleteColor: builder.mutation<void, string>({
      query: (id) => ({
        url: `canvas/api/v0/config/color/${id}`,
        method: 'DELETE',
      }),
      invalidatesTags: [
        { type: 'BrandKits', id: 'LIST' },
        { type: 'BrandKits', id: BRAND_KIT_ID },
      ],
    }),
    getColorUsageDetails: builder.query<ColorUsageDetailsResponse, string>({
      query: (colorId) => `canvas/api/v0/usage/color/${colorId}/details`,
      providesTags: (result, error, id) => [{ type: 'ColorUsageDetails', id }],
    }),
  }),
});

export const {
  useGetBrandKitsQuery,
  useGetBrandKitQuery,
  useGetAutoSaveQuery,
  useUpdateAutoSaveMutation,
  useUploadFontMutation,
  useCreateColorMutation,
  useUpdateColorMutation,
  useDeleteColorMutation,
  useGetColorUsageDetailsQuery,
} = brandKitApi;
