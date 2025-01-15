// Need to use the React-specific entry point to import createApi
import { createApi } from '@reduxjs/toolkit/query/react';
import type { ComponentsList } from '@/types/Component';
import { baseQuery } from '@/services/baseQuery';

// Define a service using a base URL and expected endpoints
export const componentApi = createApi({
  reducerPath: 'componentsApi',
  baseQuery,
  endpoints: (builder) => ({
    getComponents: builder.query<ComponentsList, void>({
      query: () => `xb/api/config/component`,
    }),
  }),
});

// Export hooks for usage in functional components, which are
// auto-generated based on the defined endpoints
export const { useGetComponentsQuery } = componentApi;
