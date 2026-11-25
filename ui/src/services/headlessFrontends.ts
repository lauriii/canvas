import { createApi } from '@reduxjs/toolkit/query/react';

import { baseQuery } from './baseQuery';

export interface StoredFrontend {
  url: string;
  components: string[];
}

export interface FrontendsResponse {
  frontends: StoredFrontend[];
  frontendUrlsHash: string;
}

interface FrontendsUpdate {
  frontends: Pick<StoredFrontend, 'url'>[];
  // Send the hash from before the edit so Drupal can reject a stale replacement.
  frontendUrlsHash: string;
}

export const headlessFrontendsApi = createApi({
  reducerPath: 'headlessFrontendsApi',
  baseQuery,
  tagTypes: ['HeadlessFrontends'],
  endpoints: (builder) => ({
    getFrontends: builder.query<FrontendsResponse, void>({
      query: () => '/canvas/api/v0/headless/frontends',
      providesTags: [{ type: 'HeadlessFrontends', id: 'LIST' }],
    }),
    // Replaces the whole list; its order is the display order. The cache is
    // patched optimistically so reordering does not snap back while the save
    // is in flight, and rolled back if the save fails. The optimistic update
    // preserves cached associations without sending them to the server.
    setFrontends: builder.mutation<FrontendsResponse, FrontendsUpdate>({
      query: (update) => ({
        url: '/canvas/api/v0/headless/frontends',
        method: 'PATCH',
        body: update,
      }),
      async onQueryStarted(update, { dispatch, queryFulfilled }) {
        const patch = dispatch(
          headlessFrontendsApi.util.updateQueryData(
            'getFrontends',
            undefined,
            (cached) => {
              // Reordering a frontend doesn't change which components it has.
              // Keep its component IDs so the library can still show them while Drupal saves.
              const componentIdsByUrl = new Map(
                cached.frontends.map(({ url, components }) => [
                  url,
                  components,
                ]),
              );
              return {
                frontendUrlsHash: update.frontendUrlsHash,
                frontends: update.frontends.map(({ url }) => ({
                  url,
                  components: componentIdsByUrl.get(url) ?? [],
                })),
              };
            },
          ),
        );
        try {
          const { data } = await queryFulfilled;
          // Keep the list and hash Drupal just saved. The next edit starts from that list.
          dispatch(
            headlessFrontendsApi.util.updateQueryData(
              'getFrontends',
              undefined,
              () => data,
            ),
          );
        } catch {
          patch.undo();
        }
      },
    }),
  }),
});

export const { useGetFrontendsQuery, useSetFrontendsMutation } =
  headlessFrontendsApi;
