import { createApi } from '@reduxjs/toolkit/query/react';
import { baseQuery } from '@/services/baseQuery';

export interface CreateContentResponse {
  entity_id: string;
  entity_type: string;
}
export interface CreateContentRequest {
  entityType: string;
}

export const contentCreateApi = createApi({
  reducerPath: 'contentCreateApi',
  baseQuery,
  endpoints: (builder) => ({
    createContent: builder.mutation<
      CreateContentResponse,
      CreateContentRequest
    >({
      query: ({ entityType }) => ({
        url: `/xb/api/content/${entityType}`,
        method: 'POST',
      }),
    }),
  }),
});

export const { useCreateContentMutation } = contentCreateApi;
