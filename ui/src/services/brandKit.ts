import { createApi } from '@reduxjs/toolkit/query/react';

import { BRAND_KIT_ID } from '@/features/brandKit/constants';
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
 * Builds the cache patches that apply a color-list change optimistically.
 *
 * Returns one `updateQueryData` thunk per cache entry the Brand kit colors UI
 * reads from. Both entries must be patched: `useBrandKitColors` prefers the
 * auto-save draft's colors over the canonical ones, so patching only the
 * canonical entry leaves the UI unchanged whenever a Brand kit draft exists.
 *
 * The mutator must change the smallest possible part of the list. Immer records
 * an inverse patch per mutated path, so a narrow edit produces a narrow undo. A
 * mutator that reassigns the whole array would make its undo restore the whole
 * array, discarding any concurrent edit to a different color.
 *
 * @param mutate - Mutates the draft color list in place.
 */
const buildColorCachePatches = (mutate: (colors: BrandKitColor[]) => void) => [
  brandKitApi.util.updateQueryData('getBrandKit', BRAND_KIT_ID, (draft) => {
    if (draft.colors) {
      mutate(draft.colors);
    }
  }),
  brandKitApi.util.updateQueryData('getAutoSave', BRAND_KIT_ID, (draft) => {
    if (draft.data?.colors) {
      mutate(draft.data.colors);
    }
  }),
];

/**
 * The in-flight optimistic write per color id, newest last.
 *
 * Two things need this. A rejected write must not roll back a newer write to
 * the same color that has already been applied, so each write carries a token
 * and only rolls back while it is still the newest. And a Brand kit read that
 * resolves mid-write arrives with pre-edit colors, so the mutator is kept here
 * to be re-applied on top of that response.
 */
const pendingColorWrites = new Map<
  string,
  { token: symbol; mutate: (colors: BrandKitColor[]) => void }
>();

/**
 * The dispatch handed to an endpoint lifecycle, narrowed to what is used here.
 */
interface PatchDispatch {
  (patch: ReturnType<typeof buildColorCachePatches>[number]): {
    undo: () => void;
  };
  (action: ReturnType<typeof brandKitApi.util.invalidateTags>): unknown;
}

/**
 * Re-applies every in-flight optimistic write to the Brand kit caches.
 *
 * `useBrandKitColors` prefers the auto-save draft's colors, and the UI becomes
 * interactive as soon as the canonical query resolves. A `getAutoSave` response
 * that lands while a write is in flight therefore carries pre-edit colors and
 * would replace the optimistic value with the old one until reconciliation —
 * a visible flash of a color the user already changed.
 *
 * @param dispatch - The lifecycle dispatch.
 */
const reapplyPendingColorWrites = (dispatch: PatchDispatch) => {
  if (pendingColorWrites.size === 0) {
    return;
  }
  buildColorCachePatches((colors) => {
    pendingColorWrites.forEach(({ mutate }) => mutate(colors));
  }).forEach((patch) => dispatch(patch));
};

/**
 * Applies a color change optimistically and reconciles it with the response.
 *
 * @param id - The color being written to.
 * @param mutate - Mutates the draft color list in place.
 * @param dispatch - The mutation lifecycle dispatch.
 * @param queryFulfilled - Resolves when the write succeeds.
 */
const applyOptimisticColorWrite = async (
  id: string,
  mutate: (colors: BrandKitColor[]) => void,
  dispatch: PatchDispatch,
  queryFulfilled: Promise<unknown>,
) => {
  const token = Symbol(id);
  pendingColorWrites.set(id, { token, mutate });
  const patches = buildColorCachePatches(mutate).map((patch) =>
    dispatch(patch),
  );
  const isNewest = () => pendingColorWrites.get(id)?.token === token;
  try {
    await queryFulfilled;
    if (isNewest()) {
      pendingColorWrites.delete(id);
    }
  } catch {
    // The server rejected the write, so restore the previous value. The UI must
    // never keep showing something that was not stored.
    if (!isNewest()) {
      return;
    }
    pendingColorWrites.delete(id);
    patches.forEach((patch) => patch.undo());
    // A read may have re-applied this write on top of a response since the
    // patches above were recorded, which their inverses do not account for.
    // Failure is the rare path, so resync from the server rather than trying to
    // reconstruct the difference. `invalidatesTags` only fires on success.
    dispatch(
      brandKitApi.util.invalidateTags([
        { type: 'BrandKits', id: BRAND_KIT_ID },
        { type: 'BrandKitsAutoSave', id: BRAND_KIT_ID },
      ]),
    );
  }
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
  tagTypes: ['BrandKits', 'BrandKitsAutoSave'],
  endpoints: (builder) => ({
    getBrandKits: builder.query<Record<string, BrandKit>, void>({
      query: () => 'canvas/api/v0/config/brand_kit',
      providesTags: () => [{ type: 'BrandKits', id: 'LIST' }],
    }),
    getBrandKit: builder.query<BrandKit, string>({
      query: (id) => `canvas/api/v0/config/brand_kit/${id}`,
      providesTags: (result, error, id) => [{ type: 'BrandKits', id }],
      async onQueryStarted(id, { dispatch, queryFulfilled }) {
        try {
          await queryFulfilled;
        } catch {
          return;
        }
        reapplyPendingColorWrites(dispatch);
      },
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
          return;
        }
        // This response carries pre-edit colors if a write is in flight.
        reapplyPendingColorWrites(dispatch);
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
      // ponytail: no optimistic insert here. The server assigns the UUID, so an
      // optimistic row would need a placeholder id that the row's rename, edit,
      // and delete actions would then send to the server. The add popover is an
      // explicit submit that already reports progress and errors inline, so the
      // wait is visible rather than surprising. Add one if creation latency is
      // measured as a problem, keying the placeholder row as non-interactive
      // until the real id arrives.
      query: (body) => ({
        url: 'canvas/api/v0/config/color',
        method: 'POST',
        body,
      }),
      invalidatesTags: [
        { type: 'BrandKits', id: 'LIST' },
        { type: 'BrandKits', id: 'global' },
        // The auto-save draft embeds colors too, and `useBrandKitColors`
        // prefers it over the canonical entry, so it must be reconciled as
        // well. Without this a new color is not reflected at all while a Brand
        // kit draft exists.
        { type: 'BrandKitsAutoSave', id: BRAND_KIT_ID },
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
        // Apply the edit to the cache before the request goes out so the color
        // row and the preview update without waiting for the round trip.
        await applyOptimisticColorWrite(
          id,
          (colors) => {
            const color = colors.find((candidate) => candidate.id === id);
            if (color) {
              Object.assign(color, changes);
            }
          },
          dispatch,
          queryFulfilled,
        );
      },
      invalidatesTags: [
        { type: 'BrandKits', id: 'LIST' },
        { type: 'BrandKits', id: 'global' },
        // The auto-save draft embeds colors too, and `useBrandKitColors`
        // prefers it over the canonical entry, so it must be reconciled as
        // well. Without this a color edit is not reflected at all while a
        // Brand kit draft exists.
        { type: 'BrandKitsAutoSave', id: BRAND_KIT_ID },
      ],
    }),
    deleteColor: builder.mutation<void, string>({
      query: (id) => ({
        url: `canvas/api/v0/config/color/${id}`,
        method: 'DELETE',
      }),
      async onQueryStarted(id, { dispatch, queryFulfilled }) {
        // Remove the row immediately; restore it if the delete is rejected.
        await applyOptimisticColorWrite(
          id,
          (colors) => {
            const index = colors.findIndex((color) => color.id === id);
            if (index !== -1) {
              colors.splice(index, 1);
            }
          },
          dispatch,
          queryFulfilled,
        );
      },
      invalidatesTags: [
        { type: 'BrandKits', id: 'LIST' },
        { type: 'BrandKits', id: 'global' },
        // The auto-save draft embeds colors too, and `useBrandKitColors`
        // prefers it over the canonical entry, so it must be reconciled as
        // well. Without this a color edit is not reflected at all while a
        // Brand kit draft exists.
        { type: 'BrandKitsAutoSave', id: BRAND_KIT_ID },
      ],
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
} = brandKitApi;
