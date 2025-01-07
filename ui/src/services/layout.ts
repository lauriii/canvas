// Need to use the React-specific entry point to import createApi
import { createApi } from '@reduxjs/toolkit/query/react';
import type { RootLayoutModel } from '@/features/layout/layoutModelSlice';
import { baseQuery } from '@/services/baseQuery';
import type {
  ComponentModels,
  RegionNode,
} from '@/features/layout/layoutModelSlice';
import { setPageData } from '@/features/pageData/pageDataSlice';

export interface LayoutRequest {
  entityId: string;
  entityType: string;
  layout: Array<RegionNode>;
  model: ComponentModels;
}

// Define a service using a base URL and expected endpoints
export const layoutApi = createApi({
  reducerPath: 'layoutApi',
  baseQuery,
  endpoints: (builder) => ({
    getLayoutById: builder.query<RootLayoutModel & { data: {} }, string>({
      query: (nodeId) => `api/layout/{entity_type}/${nodeId}`,
      async onQueryStarted(id, { dispatch, queryFulfilled }) {
        try {
          const {
            data: { data },
          } = await queryFulfilled;
          dispatch(setPageData(data));
        } catch (err) {
          dispatch(setPageData({}));
        }
      },
    }),
    saveLayoutById: builder.mutation<RootLayoutModel, LayoutRequest>({
      query: ({ entityId, entityType, layout, model }) => ({
        url: `/xb/api/content-update/${entityType}/${entityId}`,
        method: 'PATCH',
      }),
    }),
  }),
});

// Export hooks for usage in functional layout, which are
// auto-generated based on the defined endpoints
export const { useGetLayoutByIdQuery, useSaveLayoutByIdMutation } = layoutApi;
