// Need to use the React-specific entry point to import createApi
import { createApi, QueryStatus } from '@reduxjs/toolkit/query/react';

import {
  setConflicts,
  setErrors,
  setPreviousPendingChanges,
} from '@/components/review/PublishReview.slice';
import { baseQuery } from '@/services/baseQuery';
import { componentAndLayoutApi } from '@/services/componentAndLayout';
import { getCurrentUserId } from '@/utils/drupal-globals';

import type { ThunkAction, UnknownAction } from '@reduxjs/toolkit';
import type { AutoSavesHash } from '@/types/AutoSaves';

interface Owner {
  name: string;
  avatar: string | null;
  uri: string;
  id: number;
}

export interface PendingChange {
  owner: Owner;
  entity_type: string;
  entity_id: string | number;
  data_hash: string;
  langcode: string;
  label: string;
  updated: number;
  hasConflict?: boolean;
  conflict_id?: string;
}

export type PendingChanges = {
  [x: string]: PendingChange;
};

interface SuccessResponse {
  message: string;
}

export interface ConflictError {
  code?: number;
  detail: string;
  source: {
    pointer: string;
  };
  meta?: ConflictErrorMeta;
}

export interface ConflictErrorMeta {
  entity_type?: string;
  entity_id?: string | number;
  label?: string;
  api_auto_save_key?: string;
  conflict_id?: string;
}

export interface ErrorResponse {
  errors: Array<ConflictError>;
}

type DiscardPendingChangeArg = PendingChange & {
  pointer?: string;
};

export enum STATUS_CODE {
  CONFLICT = 409,
  UNPROCESSABLE_ENTITY = 422,
}

export enum CONFLICT_CODE {
  UNEXPECTED = 1,
  EXPECTED = 2,
  DETECTED = 4,
}

export interface PendingChangesResponse {
  data: PendingChanges;
  errors?: ConflictError[];
}

type PendingChangesApiResponse = PendingChanges | PendingChangesResponse;

const isPendingChangesResponse = (
  response: PendingChangesApiResponse,
): response is PendingChangesResponse => {
  const asWrapped = response as { data?: unknown };
  return typeof asWrapped.data === 'object' && asWrapped.data !== null;
};

export const getAutoSaveKeyFromError = (
  error: ConflictError,
): string | undefined => error.meta?.api_auto_save_key ?? error.source.pointer;

const isResolvableConflictError = (error?: ConflictError): boolean =>
  error?.code === CONFLICT_CODE.DETECTED;

const normalizePendingChangesResponse = (
  response: PendingChangesApiResponse,
): PendingChangesResponse => {
  if (isPendingChangesResponse(response)) {
    return response;
  }

  return {
    data: response as PendingChanges,
  };
};

export const applyConflictStateFromResponse = (
  response: PendingChangesApiResponse,
): PendingChanges => {
  const normalizedResponse = normalizePendingChangesResponse(response);
  const errorsByPointer = new Map<string, ConflictError>();
  for (const error of normalizedResponse.errors ?? []) {
    const pointer = getAutoSaveKeyFromError(error);
    if (pointer) {
      errorsByPointer.set(pointer, error);
    }
  }

  return Object.fromEntries(
    Object.entries(normalizedResponse.data ?? {}).map(([pointer, change]) => {
      const conflictError = errorsByPointer.get(pointer);
      const hasConflict = isResolvableConflictError(conflictError);
      const conflictId = hasConflict
        ? conflictError?.meta?.conflict_id
        : undefined;

      return [
        pointer,
        {
          ...change,
          hasConflict,
          conflict_id: conflictId,
        },
      ];
    }),
  );
};

// Define a service using a base URL and expected endpoints
export const pendingChangesApi = createApi({
  reducerPath: 'pendingChangesApi',
  baseQuery,
  tagTypes: ['PendingChanges'],
  endpoints: (builder) => ({
    getAllPendingChanges: builder.query<PendingChanges, void>({
      query: () => ({
        url: `/canvas/api/v0/auto-saves/pending`,
        validateStatus: (response) =>
          response.status === STATUS_CODE.CONFLICT || response.status === 200,
      }),
      transformResponse: (response: PendingChangesApiResponse) =>
        applyConflictStateFromResponse(response),
      providesTags: () => [{ type: 'PendingChanges', id: 'LIST' }],
    }),
    publishAllPendingChanges: builder.mutation<
      SuccessResponse | ErrorResponse,
      PendingChanges
    >({
      query: (body) => ({
        url: `/canvas/api/v0/auto-saves/publish`,
        method: 'POST',
        body,
      }),
      async onQueryStarted(body, { dispatch, queryFulfilled }) {
        try {
          await queryFulfilled;

          dispatch(
            pendingChangesApi.util.updateQueryData(
              'getAllPendingChanges',
              undefined,
              (draft) => {
                // Remove only the changes that were successfully published
                Object.keys(body).forEach((key) => {
                  delete draft[key];
                });
                return draft;
              },
            ),
          );

          // Invalidate the layout query cache of the current entity to ensure that the autoSaves hash is updated
          // ALSO, For example Drupal has hook_entity_presave which allows altering an entity before it is saved.
          // Canvas will not be aware of any changes made in custom code here, therefore if Canvas doesn't re-request
          // after publishing, the auto-save request could wipe out any changes that were made in
          // any hook_entity_presave code
          dispatch(
            componentAndLayoutApi.util.invalidateTags([
              { type: 'Layout' },
              { type: 'CodeComponents', id: 'LIST' },
            ]),
          );
          dispatch(setPreviousPendingChanges());
          dispatch(setErrors());
        } catch (error: any) {
          dispatch(setErrors(error.error?.data));

          // Handle conflicts
          // @todo https://www.drupal.org/i/3503404
          if (error.error?.status === STATUS_CODE.CONFLICT) {
            // set previous response
            dispatch(setPreviousPendingChanges(body));
            // set conflicts
            dispatch(setConflicts(error?.error?.data?.errors));
          }
        }
      },
    }),
    discardPendingChange: builder.mutation<
      SuccessResponse | ErrorResponse,
      DiscardPendingChangeArg
    >({
      query: (change: PendingChange) => ({
        url: `/canvas/api/v0/auto-saves/${change.entity_type}/${change.entity_id}`,
        method: 'DELETE',
      }),
      async onQueryStarted(change, { dispatch, queryFulfilled }) {
        try {
          await queryFulfilled;
          if (change.pointer) {
            dispatch(
              pendingChangesApi.util.updateQueryData(
                'getAllPendingChanges',
                undefined,
                (draft) => {
                  delete draft[change.pointer as string];
                },
              ),
            );
          }
          dispatch(
            pendingChangesApi.util.invalidateTags([
              { type: 'PendingChanges', id: 'LIST' },
            ]),
          );

          // Reset errors
          dispatch(setConflicts());
          dispatch(setPreviousPendingChanges());
          dispatch(setErrors());
        } catch (error: any) {
          dispatch(
            setErrors({
              errors: [
                {
                  code: 0,
                  detail:
                    error?.error?.data?.message ??
                    'Failed to discard pending change',
                  source: { pointer: '' },
                  meta: {
                    entity_type: change.entity_type,
                    entity_id: change.entity_id,
                    label: change.label,
                  },
                },
              ],
            }),
          );
        }
      },
    }),
  }),
});

// Export hooks for usage in functional layout, which are
// auto-generated based on the defined endpoints
export const {
  useGetAllPendingChangesQuery,
  usePublishAllPendingChangesMutation,
  useDiscardPendingChangeMutation,
} = pendingChangesApi;

type PendingChangesState = {
  [pendingChangesApi.reducerPath]: ReturnType<typeof pendingChangesApi.reducer>;
};

/**
 * Updates the cached pending changes from an auto-save PATCH response.
 *
 * Refetching the pending changes makes the server load every auto-save on the
 * site. After a PATCH, the response `autoSaves` hashes are usually enough to
 * update the cached list in place: only `data_hash` (needed to publish) and
 * `updated` change for entries whose hash differs from the cached one. Entries
 * with an unchanged hash (the PATCH left the entity's auto-save as it was) are
 * left as they are. `updated` is set from the client clock, so it can differ
 * from the server time until the next refetch.
 *
 * The list is invalidated (refetched) instead when:
 * - there is no cached list yet;
 * - the list query is in flight, because its response may predate this PATCH
 *   and would overwrite the new hash. Invalidating fetches the list again
 *   after that response arrives;
 * - an entity gains or loses its auto-save. A key with a hash that is missing
 *   from the cached list counts as gained. The GET filters out auto-saves of
 *   non-default translations and auto-saves the current user cannot access,
 *   so every PATCH that lists one of them falls back to invalidation;
 * - the cached owner of a changed entry is not the current user (or the
 *   current user is unknown), because the server makes the current user the
 *   owner of the auto-save it saves;
 * - a changed entry has an unresolved conflict, because the conflict
 *   notification fingerprint includes `updated`. The refetch sets the server
 *   time, so the fingerprint changes once per PATCH.
 *
 * Only the PATCH path (`updateComponent`) uses this. `postPreview` still
 * invalidates the list, because labels can change there.
 *
 * @param autoSaves
 *   The `autoSaves` from the PATCH response, keyed by auto-save key. A `null`
 *   hash means the entity has no auto-save.
 */
export const syncPendingChangesFromAutoSaves =
  (
    autoSaves: AutoSavesHash | undefined,
  ): ThunkAction<void, PendingChangesState, unknown, UnknownAction> =>
  (dispatch, getState) => {
    const { data: pending, status } =
      pendingChangesApi.endpoints.getAllPendingChanges.select()(getState());
    const currentUserId = getCurrentUserId();
    let mustRefetch = !pending || status === QueryStatus.pending;
    const changedHashes: [string, string][] = [];
    for (const [key, { hash }] of Object.entries(autoSaves ?? {})) {
      const cached = pending?.[key];
      if (!hash || !cached) {
        // An entity gained or lost its auto-save. An entity without a hash and
        // without a listed auto-save (the PATCH left it equal to the stored
        // entity) is fine.
        mustRefetch ||= !hash !== !cached;
        continue;
      }
      if (cached.data_hash === hash) {
        continue;
      }
      mustRefetch ||=
        cached.owner.id !== currentUserId || cached.hasConflict === true;
      changedHashes.push([key, hash]);
    }
    if (mustRefetch) {
      dispatch(
        pendingChangesApi.util.invalidateTags([
          { type: 'PendingChanges', id: 'LIST' },
        ]),
      );
      return;
    }
    if (changedHashes.length === 0) {
      return;
    }
    const updated = Math.floor(Date.now() / 1000);
    dispatch(
      pendingChangesApi.util.updateQueryData(
        'getAllPendingChanges',
        undefined,
        (draft) => {
          for (const [key, hash] of changedHashes) {
            draft[key].data_hash = hash;
            draft[key].updated = updated;
          }
        },
      ),
    );
  };
