// Need to use the React-specific entry point to import createApi
import { createApi, fetchBaseQuery } from '@reduxjs/toolkit/query/react';
import type { LayoutNode } from '../features/layout/layoutModelSlice';

export interface LayoutResponse {
  model: {};
  layout: LayoutNode;
}

// Define a service using a base URL and expected endpoints
export const layoutApi = createApi({
  reducerPath: 'layoutApi',
  baseQuery: fetchBaseQuery({ baseUrl: '/api' }),
  endpoints: (builder) => ({
    getLayoutById: builder.query<LayoutResponse, string>({
      query: (nodeId) => `layout/${nodeId}`,
    }),
  }),
});

// Export hooks for usage in functional layout, which are
// auto-generated based on the defined endpoints
export const { useGetLayoutByIdQuery } = layoutApi;
