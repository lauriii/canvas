import { createApi } from '@reduxjs/toolkit/query/react';

import { baseQuery } from '@/services/baseQuery';

import type { CodeComponentOptionsSource } from '@/types/CodeComponent';

export interface OptionsSourceSuggestion {
  group: string;
  label: string;
  options: CodeComponentOptionsSource;
}

/**
 * Lists the options sources that code component props can use.
 *
 * @see \Drupal\canvas\Controller\ApiUiOptionsSourcesController
 */
export const optionsSourcesApi = createApi({
  reducerPath: 'optionsSourcesApi',
  baseQuery,
  endpoints: (builder) => ({
    listOptionsSources: builder.query<OptionsSourceSuggestion[], void>({
      query: () => ({ url: '/canvas/api/v0/ui/options-sources' }),
      transformResponse: (response: { data: OptionsSourceSuggestion[] }) =>
        response.data,
    }),
  }),
});

export const { useListOptionsSourcesQuery } = optionsSourcesApi;
