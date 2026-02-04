// Need to use the React-specific entry point to import createApi
import { createApi } from '@reduxjs/toolkit/query/react';

import addAjaxPageState from '@/services/addAjaxPageState';
import { baseQuery } from '@/services/baseQuery';
import processResponseAssets from '@/services/processResponseAssets';

export const pageDataFormApi = createApi({
  reducerPath: 'pageDataFormApi',
  baseQuery,
  tagTypes: ['PageDataForm'],
  endpoints: (builder) => ({
    getPageDataForm: builder.query<
      string,
      { entityId: string; entityType: string }
    >({
      query: ({ entityId, entityType }) => {
        return {
          url: `/canvas/api/v0/form/content-entity/${entityType}/${entityId}/default?${addAjaxPageState('')}`,
        };
      },
      transformResponse: processResponseAssets(),
      providesTags: [{ type: 'PageDataForm', id: 'FORM' }],
    }),
  }),
});

// Export hooks for usage in functional layout, which are
// auto-generated based on the defined endpoints
export const { useGetPageDataFormQuery } = pageDataFormApi;
