// Need to use the React-specific entry point to import createApi
import { createApi } from '@reduxjs/toolkit/query/react';
import { baseQuery } from '@/services/baseQuery';
import processResponseAssets from '@/services/processResponseAssets';
import addAjaxPageState from '@/services/addAjaxPageState';
import type { TransformConfig } from '@/utils/transforms';

export const dummyPropsFormApi = createApi({
  reducerPath: 'dummyPropsFormApi',
  baseQuery,
  endpoints: (builder) => ({
    getDummyPropsForm: builder.query<
      { html: string; transforms: TransformConfig },
      string
    >({
      query: (queryString) => {
        // Add timestamp to prevent caching. Every request must be fresh
        // to ensure the selectors match that of the AJAX config.
        const timestamp = new Date().getTime();
        const fullQueryString = addAjaxPageState(
          `${queryString}&_nocache=${timestamp}`,
        );
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
      transformResponse: processResponseAssets(['html', 'transforms']),
      keepUnusedDataFor: 0,
    }),
  }),
});

// Export hooks for usage in functional layout, which are
// auto-generated based on the defined endpoints
export const { useGetDummyPropsFormQuery } = dummyPropsFormApi;
