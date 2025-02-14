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
    getLayoutById: builder.query<
      RootLayoutModel & { entity_form_fields: {} },
      string
    >({
      query: (nodeId) => `xb/api/layout/{entity_type}/${nodeId}`,
      async onQueryStarted(id, { dispatch, queryFulfilled }) {
        try {
          const {
            data: { entity_form_fields },
          } = await queryFulfilled;
          dispatch(setPageData(entity_form_fields));
        } catch (err) {
          dispatch(setPageData({}));
        }
      },
    }),
  }),
});

// Export hooks for usage in functional layout, which are
// auto-generated based on the defined endpoints
export const { useGetLayoutByIdQuery } = layoutApi;
