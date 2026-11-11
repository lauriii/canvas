import { createApi } from '@reduxjs/toolkit/query/react';

import { baseQuery } from './baseQuery';

// Whether the cache tags Drupal invalidates reach a frontend.
export interface CacheInvalidationStatus {
  state: 'ok' | 'failing';
  // Unix timestamps, in seconds.
  lastSuccess: number;
  lastFailure: number | null;
  lastError: string | null;
}

export interface StoredFrontend {
  url: string;
  components: string[];
  // Null until the frontend has read announced cache tags once: it may not
  // cache pages at all.
  cacheInvalidationStatus: CacheInvalidationStatus | null;
}

// What a save sends: the status is reported by Drupal, never stored.
export type FrontendInput = Omit<StoredFrontend, 'cacheInvalidationStatus'>;

export interface CacheInvalidationTestResult {
  ok: boolean;
  announced: boolean;
  read: boolean;
  message: string;
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
    setFrontends: builder.mutation<StoredFrontend[], FrontendInput[]>({
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
            (current) =>
              frontends.map((frontend) => ({
                ...frontend,
                cacheInvalidationStatus:
                  current.find((item) => item.url === frontend.url)
                    ?.cacheInvalidationStatus ?? null,
              })),
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
    // Announces to one frontend and waits for it to read the cache tags. With
    // `expireAll`, the frontend is told to expire everything. The list is
    // refetched afterwards because the test changes the status.
    testCacheInvalidation: builder.mutation<
      CacheInvalidationTestResult,
      { url: string; expireAll: boolean }
    >({
      query: (body) => ({
        url: '/canvas/api/v0/headless/frontends/cache-invalidation/test',
        method: 'POST',
        body,
      }),
      invalidatesTags: [{ type: 'HeadlessFrontends', id: 'LIST' }],
    }),
  }),
});

export const {
  useGetFrontendsQuery,
  useSetFrontendsMutation,
  useTestCacheInvalidationMutation,
} = headlessFrontendsApi;
