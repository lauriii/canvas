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
  }),
});

export const { useGetContentListQuery, useDeleteContentMutation } = contentApi;
