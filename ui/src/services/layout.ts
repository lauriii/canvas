// Need to use the React-specific entry point to import createApi
import { createApi } from '@reduxjs/toolkit/query/react';
import type { RootLayoutModel } from '@/features/layout/layoutModelSlice';
import { baseQuery } from '@/services/baseQuery';

// Define a service using a base URL and expected endpoints
export const layoutApi = createApi({
  reducerPath: 'layoutApi',
  baseQuery,
  endpoints: (builder) => ({
    getLayoutById: builder.query<RootLayoutModel, string>({
      query: (nodeId) => `api/layout/{entity_type}/${nodeId}`,
    }),
  }),
});

// Export hooks for usage in functional layout, which are
// auto-generated based on the defined endpoints
export const { useGetLayoutByIdQuery } = layoutApi;
