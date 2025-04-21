import { createApi } from '@reduxjs/toolkit/query/react';
import { baseQuery } from '@/services/baseQuery';
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
export const contentApi = createApi({
  reducerPath: 'contentApi',
  baseQuery,
  tagTypes: ['Content'],
  endpoints: (builder) => ({
    getContentList: builder.query<ContentStub[], string>({
      query: (entityType) => `/xb/api/content/${entityType}`,
      transformResponse: (response: ContentListResponse) => {
        return Object.values(response);
      },
      providesTags: [{ type: 'Content', id: 'LIST' }],
    }),
    deleteContent: builder.mutation<void, DeleteContentRequest>({
      query: ({ entityType, entityId }) => ({
        url: `/xb/api/content/${entityType}/${entityId}`,
        method: 'DELETE',
      }),
      invalidatesTags: [{ type: 'Content', id: 'LIST' }],
    }),
    createContent: builder.mutation<
      CreateContentResponse,
      CreateContentRequest
    >({
      query: ({ entity_type, entity_id }) => ({
        url: `/xb/api/content/${entity_type}`,
        method: 'POST',
        body: entity_id ? { entity_id } : {},
      }),
      invalidatesTags: [{ type: 'Content', id: 'LIST' }],
    }),
  }),
});

export const {
  useGetContentListQuery,
  useDeleteContentMutation,
  useCreateContentMutation,
} = contentApi;
