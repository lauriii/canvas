import { createApi } from '@reduxjs/toolkit/query/react';

import { HOMEPAGE_CONFIG_ID } from '@/components/pageInfo/PageInfo';
import { baseQueryWithAutoSaves } from '@/services/baseQuery';
import { pendingChangesApi } from '@/services/pendingChangesApi';

import type { StagedConfig } from '@/types/Config';
import type { ContentStub } from '@/types/Content';

export interface ContentListResponse {
  data: ContentStub[];
  meta?: { count: number };
  links?: Record<string, { href: string }>;
}

export interface DeleteContentRequest {
  entityType: string;
  entityId: string;
}

export interface CreateContentResponse {
  entity_id: string;
  entity_type: string;
}

export interface CreateContentRequest {
  entity_id?: string;
  entity_type: string;
}

export interface UpdateContentRequest {
  entityType: string;
  entityId: string;
  status?: boolean;
}

export interface ContentListResult {
  items: ContentStub[];
  totalCount: number | null;
}

export interface ContentListParams {
  entityType: string;
  search?: string;
  offset?: number;
}

export const contentApi = createApi({
  reducerPath: 'contentApi',
  baseQuery: baseQueryWithAutoSaves,
  tagTypes: ['Content', 'StagedConfig', 'PendingChanges'],
  endpoints: (builder) => ({
    getContentList: builder.query<ContentListResult, ContentListParams>({
      query: ({ entityType, search, offset }) => {
        const params = new URLSearchParams();
        if (search) {
          const normalizedSearch = search.toLowerCase().trim();
          params.append('search', normalizedSearch);
        }
        if (offset && offset > 0) {
          params.append('page[offset]', String(offset));
        }
        const hasParams = params.toString().length > 0;
        return {
          url: `/canvas/api/v0/content/${entityType}`,
          params: hasParams ? params : undefined,
        };
      },
      transformResponse: (
        response: ContentListResponse,
      ): ContentListResult => ({
        items: response.data,
        totalCount: response.meta?.count ?? null,
      }),
      serializeQueryArgs: ({ queryArgs }) => {
        return `${queryArgs.entityType}-${queryArgs.search || ''}`;
      },
      merge: (currentCache, newItems, { arg }) => {
        if (arg.offset && arg.offset > 0) {
          currentCache.items.push(...newItems.items);
        } else {
          currentCache.items = newItems.items;
        }
        currentCache.totalCount = newItems.totalCount;
      },
      forceRefetch: ({ currentArg, previousArg }) => {
        return currentArg !== previousArg;
      },
      providesTags: [{ type: 'Content', id: 'LIST' }],
    }),
    deleteContent: builder.mutation<void, DeleteContentRequest>({
      query: ({ entityType, entityId }) => ({
        url: `/canvas/api/v0/content/${entityType}/${entityId}`,
        method: 'DELETE',
      }),
      // Use optimistic update instead of cache invalidation to preserve scroll position and
      // ensure the deleted item is removed from the UI. Cache invalidation re-fetches using the
      // last subscribed query args (e.g., offset=50), which only updates that portion of the
      // cache — if the deleted item was in an earlier page (e.g., offset=0), it remains visible.
      async onQueryStarted(
        { entityType, entityId },
        { dispatch, queryFulfilled, getState },
      ) {
        const patchResults: Array<{ undo: () => void }> = [];
        const state = getState() as any;
        const queryCache = state[contentApi.reducerPath]?.queries;

        if (queryCache) {
          Object.keys(queryCache).forEach((queryKey) => {
            const query = queryCache[queryKey];
            if (
              query?.endpointName === 'getContentList' &&
              query?.originalArgs?.entityType === entityType
            ) {
              try {
                const patchResult = dispatch(
                  contentApi.util.updateQueryData(
                    'getContentList',
                    query.originalArgs,
                    (draft) => {
                      draft.items = draft.items.filter(
                        (item) => String(item.id) !== String(entityId),
                      );
                      if (draft.totalCount !== null) {
                        draft.totalCount -= 1;
                      }
                    },
                  ),
                );
                patchResults.push(patchResult);
              } catch {
                // Query might not exist in cache
              }
            }
          });
        }
        try {
          await queryFulfilled;
        } catch {
          patchResults.forEach((result) => result.undo());
        }
      },
    }),
    createContent: builder.mutation<
      CreateContentResponse,
      CreateContentRequest
    >({
      query: ({ entity_type, entity_id }) => ({
        url: `/canvas/api/v0/content/${entity_type}`,
        method: 'POST',
        body: entity_id ? { entity_id } : {},
      }),
      // Instead of invalidating the cache tag { type: 'Content', id: 'LIST' }, we manually refetch the first 50 items after creation.
      // The newly added page is ensured to be the first item of the pages due to the sort order used by the backend.
      // Since we are using pagination queries with offset for pages, if the last subscribed argument in RTK was with offset=50,
      // then invalidating the cache tag would re-fetch with offset=50 without that newly added item.
      async onQueryStarted({ entity_type }, { dispatch, queryFulfilled }) {
        try {
          await queryFulfilled;
          // Force refetch from offset 0 to get fresh data including the new item.
          dispatch(
            contentApi.endpoints.getContentList.initiate(
              { entityType: entity_type, offset: 0 },
              { forceRefetch: true },
            ),
          );
        } catch {
          // If creation fails, no refetch needed
        }
      },
    }),
    updateContent: builder.mutation<void, UpdateContentRequest>({
      query: ({ entityType, entityId, status }) => ({
        url: `/canvas/api/v0/content/auto-save/${entityType}/${entityId}`,
        method: 'PATCH',
        body: status !== undefined ? { status } : {},
      }),
      invalidatesTags: [
        { type: 'Content', id: 'LIST' },
        { type: 'PendingChanges', id: 'LIST' },
      ],
      async onQueryStarted(arg, { dispatch, queryFulfilled, getState }) {
        const { entityType, entityId, status } = arg;
        if (status === undefined) {
          return;
        }

        const unpublishLinkRel = 'disable';
        const publishLinkRel = 'enable';

        // Update function to apply to matching queries
        const updatePageData = (draft: ContentListResult) => {
          const page = draft.items.find(
            (item) => String(item.id) === String(entityId),
          );
          if (!page) {
            return;
          }

          page.status = status;
          page.hasUnsavedStatusChange = true;

          // Swap links: both unpublish and publish use the same PATCH endpoint
          const fromLink = status === false ? unpublishLinkRel : publishLinkRel;
          const toLink = status === false ? publishLinkRel : unpublishLinkRel;
          const existingUrl = page.links[fromLink];
          delete page.links[fromLink];
          if (existingUrl) {
            page.links[toLink] = existingUrl;
          }
        };

        // Optimistically update all cached queries for this entity type
        const patchResults: Array<{ undo: () => void }> = [];
        const state = getState() as any;
        const queryCache = state[contentApi.reducerPath]?.queries;

        if (queryCache) {
          Object.keys(queryCache).forEach((queryKey) => {
            const query = queryCache[queryKey];
            if (
              query?.endpointName === 'getContentList' &&
              query?.originalArgs?.entityType === entityType
            ) {
              try {
                const patchResult = dispatch(
                  contentApi.util.updateQueryData(
                    'getContentList',
                    query.originalArgs,
                    updatePageData,
                  ),
                );
                patchResults.push(patchResult);
              } catch {
                // Query might not exist in cache, which is fine
              }
            }
          });
        }

        try {
          await queryFulfilled;
          dispatch(
            pendingChangesApi.util.invalidateTags([
              { type: 'PendingChanges', id: 'LIST' },
            ]),
          );
        } catch {
          // Revert optimistic updates on error
          patchResults.forEach((result) => result.undo());
        }
      },
    }),
    getStagedConfig: builder.query<StagedConfig, string>({
      query: (entityId) => ({
        url: `/canvas/api/v0/config/auto-save/staged_config_update/${entityId}`,
        method: 'GET',
      }),
      providesTags: (_result, _error, entityId) => [
        { type: 'StagedConfig', id: entityId },
      ],
    }),
    setStagedConfig: builder.mutation<void, StagedConfig>({
      query: (body) => ({
        url: `/canvas/api/v0/staged-update/auto-save`,
        method: 'POST',
        body,
      }),
      // Hardcode HOMEPAGE_CONFIG_ID for now, as it is the only config we handle right now.
      // In the future we can generalize this.
      invalidatesTags: [
        { type: 'StagedConfig', id: HOMEPAGE_CONFIG_ID },
        { type: 'Content', id: 'LIST' },
      ],
    }),
  }),
});

export const {
  useGetContentListQuery,
  useDeleteContentMutation,
  useCreateContentMutation,
  useUpdateContentMutation,
  useGetStagedConfigQuery,
  useSetStagedConfigMutation,
} = contentApi;
