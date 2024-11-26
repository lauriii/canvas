// Need to use the React-specific entry point to import createApi
import { createApi } from '@reduxjs/toolkit/query/react';
import type { RootLayoutModel } from '@/features/layout/layoutModelSlice';
import { baseQuery } from '@/services/baseQuery';
import type {
  ComponentModels,
  RootNode,
} from '@/features/layout/layoutModelSlice';

export interface LayoutRequest {
  entityId: string;
  entityType: string;
  layout: RootNode;
  model: ComponentModels;
}

// Define a service using a base URL and expected endpoints
export const layoutApi = createApi({
  reducerPath: 'layoutApi',
  baseQuery,
  endpoints: (builder) => ({
    getLayoutById: builder.query<RootLayoutModel, string>({
      query: (nodeId) => `api/layout/{entity_type}/${nodeId}`,
    }),
    saveLayoutById: builder.mutation<RootLayoutModel, LayoutRequest>({
      query: ({ entityId, entityType, layout, model }) => ({
        url: `/xb/api/content-update/${entityType}/${entityId}`,
        method: 'PATCH',
        body: { layout, model },
      }),
    }),
  }),
});

// Export hooks for usage in functional layout, which are
// auto-generated based on the defined endpoints
export const { useGetLayoutByIdQuery, useSaveLayoutByIdMutation } = layoutApi;
