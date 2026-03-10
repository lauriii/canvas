import { createApi } from '@reduxjs/toolkit/query/react';

import { HOMEPAGE_CONFIG_ID } from '@/components/pageInfo/PageInfo';
import { baseQueryWithAutoSaves } from '@/services/baseQuery';
import { pendingChangesApi } from '@/services/pendingChangesApi';

import type { StagedConfig } from '@/types/Config';
import type { ContentStub } from '@/types/Content';

export interface ContentListResponse {
  [key: string]: ContentStub;
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

export interface ContentListParams {
  entityType: string;
  search?: string;
}

export const contentApi = createApi({
  reducerPath: 'contentApi',
  baseQuery: baseQueryWithAutoSaves,
  tagTypes: ['Content', 'StagedConfig', 'PendingChanges'],
  endpoints: (builder) => ({
    getContentList: builder.query<ContentStub[], ContentListParams>({
      query: ({ entityType, search }) => {
        const params = new URLSearchParams();
        if (search) {
          const normalizedSearch = search.toLowerCase().trim();
          params.append('search', normalizedSearch);
        }
        return {
          url: `/canvas/api/v0/content/${entityType}`,
          params: search ? params : undefined,
        };
      },
      transformResponse: (response: ContentListResponse) => {
        return Object.values(response);
      },
      providesTags: [{ type: 'Content', id: 'LIST' }],
    }),
    deleteContent: builder.mutation<void, DeleteContentRequest>({
      query: ({ entityType, entityId }) => ({
        url: `/canvas/api/v0/content/${entityType}/${entityId}`,
        method: 'DELETE',
      }),
      invalidatesTags: [{ type: 'Content', id: 'LIST' }],
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
      invalidatesTags: [{ type: 'Content', id: 'LIST' }],
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
        const updatePageData = (draft: ContentStub[]) => {
          const page = draft.find(
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
