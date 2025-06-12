// Need to use the React-specific entry point to import createApi
import { createApi } from '@reduxjs/toolkit/query/react';
import { baseQuery } from '@/services/baseQuery';
import processResponseAssets from '@/services/processResponseAssets';
import addAjaxPageState from '@/services/addAjaxPageState';
import type { TransformConfig } from '@/utils/transforms';

let lastArgInUseByAnyComponent: string | undefined = '';

export const dummyPropsFormApi = createApi({
  reducerPath: 'dummyPropsFormApi',
  baseQuery,
  endpoints: (builder) => ({
    getDummyPropsForm: builder.query<
      { html: string; transforms: TransformConfig },
      string
    >({
      query: (queryString) => {
        const fullQueryString = addAjaxPageState(queryString);
        return {
          url: `xb/api/v0/form/component-instance/{entity_type}/{entity_id}`,
          // We use PATCH to keep this distinct from AJAX form submissions which
          // use POST.
          method: 'PATCH',
          body: fullQueryString,
          headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
          },
        };
      },
      forceRefetch: ({ currentArg, previousArg, endpointState }) => {
        // When true, this will fetch new data on the request, but will use
        // cached data until the new data is available.
        const noChangesFound =
          currentArg === previousArg &&
          lastArgInUseByAnyComponent === currentArg;
        lastArgInUseByAnyComponent = currentArg;
        return !noChangesFound;
      },
      transformResponse: processResponseAssets(['html', 'transforms']),
    }),
  }),
});

// Export hooks for usage in functional layout, which are
// auto-generated based on the defined endpoints
export const { useGetDummyPropsFormQuery } = dummyPropsFormApi;
