import { createApi } from '@reduxjs/toolkit/query/react';

import { baseQuery } from '@/services/baseQuery';

import type { StylesResponse, StyleUsage } from '@/types/Styles';

/**
 * Lists the component styles this site offers. The editor fetches it once,
 * with the draft brand kit applied, and builds every Style tab from it.
 *
 * @see \Drupal\canvas\Controller\ApiStylesController
 */
export const stylesApi = createApi({
  reducerPath: 'stylesApi',
  baseQuery,
  tagTypes: ['Styles'],
  endpoints: (builder) => ({
    getStyles: builder.query<StylesResponse, void>({
      query: () => 'canvas/api/v0/styles?preview=1',
      providesTags: () => [{ type: 'Styles', id: 'LIST' }],
    }),
    /** Every style and option, before the brand kit: for the brand kit UI. */
    getUnbrandedStyles: builder.query<StylesResponse, void>({
      query: () => 'canvas/api/v0/styles?unbranded=1',
    }),
    /** Instances per style option, for the brand kit UI. */
    getStyleUsage: builder.query<StyleUsage, void>({
      query: () => 'canvas/api/v0/styles/usage',
      keepUnusedDataFor: 0,
    }),
  }),
});

export const {
  useGetStylesQuery,
  useGetUnbrandedStylesQuery,
  useGetStyleUsageQuery,
} = stylesApi;
