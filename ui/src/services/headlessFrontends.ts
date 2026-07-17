import { createApi } from '@reduxjs/toolkit/query/react';

import { baseQuery } from './baseQuery';

export interface StoredFrontend {
  url: string;
}

interface FrontendsResponse {
  frontends: StoredFrontend[];
}

export const headlessFrontendsApi = createApi({
  reducerPath: 'headlessFrontendsApi',
  baseQuery,
  tagTypes: ['HeadlessFrontends'],
  endpoints: (builder) => ({
    getFrontends: builder.query<StoredFrontend[], void>({
      query: () => '/canvas/api/v0/headless/frontends',
      transformResponse: (response: FrontendsResponse) => response.frontends,
      providesTags: [{ type: 'HeadlessFrontends', id: 'LIST' }],
    }),
    // Replaces the whole list; its order is the display order. The cache is
    // patched optimistically so reordering does not snap back while the save
    // is in flight, and rolled back if the save fails.
    setFrontends: builder.mutation<StoredFrontend[], StoredFrontend[]>({
      query: (frontends) => ({
        url: '/canvas/api/v0/headless/frontends',
        method: 'PATCH',
        body: { frontends },
      }),
      transformResponse: (response: FrontendsResponse) => response.frontends,
      async onQueryStarted(frontends, { dispatch, queryFulfilled }) {
        const patch = dispatch(
          headlessFrontendsApi.util.updateQueryData(
            'getFrontends',
            undefined,
            () => frontends,
          ),
        );
        try {
          await queryFulfilled;
        } catch {
          patch.undo();
        }
      },
      invalidatesTags: [{ type: 'HeadlessFrontends', id: 'LIST' }],
    }),
  }),
});

export const { useGetFrontendsQuery, useSetFrontendsMutation } =
  headlessFrontendsApi;
