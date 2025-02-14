// Need to use the React-specific entry point to import createApi
import { createApi } from '@reduxjs/toolkit/query/react';
import { baseQuery } from '@/services/baseQuery';
import processResponseAssets from '@/services/processResponseAssets';
import addAjaxPageState from '@/services/addAjaxPageState';

export const dummyPropsFormApi = createApi({
  reducerPath: 'dummyPropsFormApi',
  baseQuery,
  endpoints: (builder) => ({
    getDummyPropsForm: builder.query<string, string>({
      query: (queryString) => {
        const fullQueryString = addAjaxPageState(queryString);
        return {
          url: `xb/api/form/component-instance/{entity_type}/{entity_id}`,
          // We use PATCH to keep this distinct from AJAX form submissions which
          // use POST.
          method: 'PATCH',
          body: fullQueryString,
          headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
          },
        };
      },
      transformResponse: processResponseAssets,
    }),
  }),
});

// Export hooks for usage in functional layout, which are
// auto-generated based on the defined endpoints
export const { useGetDummyPropsFormQuery } = dummyPropsFormApi;
