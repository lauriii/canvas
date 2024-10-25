// Need to use the React-specific entry point to import createApi
import { createApi } from '@reduxjs/toolkit/query/react';
import { baseQuery } from '@/services/baseQuery';
import processResponseAssets from '@/services/processResponseAssets';
import addAjaxPageState from '@/services/addAjaxPageState';

export const pageDataFormApi = createApi({
  reducerPath: 'pageDataFormApi',
  baseQuery,
  endpoints: (builder) => ({
    getPageDataForm: builder.query<string, void>({
      query: () => {
        return {
          url: `/xb/api/entity-form/{entity_type}/{entity_id}/default?${addAjaxPageState('')}`,
          // fetchBaseQuery assumes every Response will get parsed by JSON so we need to add the below.
          responseHandler: 'text',
        };
      },
      transformResponse: processResponseAssets,
    }),
  }),
});

// Export hooks for usage in functional layout, which are
// auto-generated based on the defined endpoints
export const { useGetPageDataFormQuery } = pageDataFormApi;
