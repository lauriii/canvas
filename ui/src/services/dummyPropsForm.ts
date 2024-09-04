// Need to use the React-specific entry point to import createApi
import { createApi } from '@reduxjs/toolkit/query/react';
import { baseQuery } from '@/services/baseQuery';
import processResponseAssets from '@/services/processResponseAssets';

export const dummyPropsFormApi = createApi({
  reducerPath: 'dummyPropsFormApi',
  baseQuery,
  endpoints: (builder) => ({
    getDummyPropsForm: builder.query<string, string>({
      query: (queryString) => ({
        url: `xb-field-form/{entity_type}/{entity_id}${queryString}`,
        // fetchBaseQuery assumes every Response will get parsed by JSON so we need to add the below.
        responseHandler: 'text',
      }),
      transformResponse: processResponseAssets,
    }),
  }),
});

// Export hooks for usage in functional layout, which are
// auto-generated based on the defined endpoints
export const { useGetDummyPropsFormQuery } = dummyPropsFormApi;
