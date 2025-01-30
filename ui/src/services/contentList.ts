import { createApi } from '@reduxjs/toolkit/query/react';
import { baseQuery } from '@/services/baseQuery';
import type { ContentStub } from '@/types/Content';

export interface ContentListResponse {
  [key: string]: ContentStub;
}

export const contentListApi = createApi({
  reducerPath: 'contentListApi',
  baseQuery,
  endpoints: (builder) => ({
    getContentList: builder.query<ContentStub[], string>({
      query: (entityType) => `/xb/api/content/${entityType}`,
      transformResponse: (response: ContentListResponse) => {
        return Object.values(response);
      },
    }),
  }),
});

export const { useGetContentListQuery } = contentListApi;
